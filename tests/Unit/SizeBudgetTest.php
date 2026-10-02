<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Vela por el tamaño de los métodos del código de producción.
 *
 * Un método largo no es un delito por sí mismo —el constructor de Config tiene
 * razones de peso para ser lo que es—, pero sí es una señal de aviso: cuando un
 * método pasa de una pantalla, suele estar haciendo dos cosas y la segunda se
 * puede nombrar aparte. El presupuesto existe para que esa señal no se
 * normalice en silencio: cada método que se pase hoy está en la lista de
 * excepciones, con su motivo, y lo nuevo nace dentro del límite.
 *
 * La medición va de la palabra `function` a la llave que cierra el cuerpo, en
 * líneas físicas, y se hace con token_get_all() en vez de con expresiones
 * regulares: un `{` dentro de una cadena o de un comentario no abre un
 * cuerpo, y un cierre de interpolación no lo cierra. Las firmas sin cuerpo de
 * los contratos se quedan fuera: no se pueden partir y son un punto de
 * extensión declarado.
 *
 * Solo se escanea el código de producción: src/examples son recetas y demos
 * (y admiten else a propósito) y src/public es el drop-in del endpoint. Ambos
 * ya no se distribuyen (excluidos en .gitattributes) pero se mantienen en el
 * repo para desarrollo. src/app sí cuenta, a diferencia de la cobertura: sus
 * ficheros viajan en el archivo y se ejecutan como configuración en la app del
 * host, así que el presupuesto también los vigila.
 */
final class SizeBudgetTest extends TestCase
{
    /**
     * Líneas máximas por método, de la palabra `function` a su llave de
     * cierre, ambas incluidas.
     */
    private const PRESUPUESTO = 15;

    /**
     * Directorios de src/ que no cuentan como producción.
     *
     * @var list<string>
     */
    private const EXCLUIDOS = ['src/examples', 'src/public'];

    /**
     * Métodos que pueden pasar del presupuesto, con el motivo por el que se
     * accepts. Añadir una entrada es una decisión: se escribe aquí el porqué,
     * igual que en los ignores de PHPStan o en NamingTest.
     *
     * @var array<string, string>
     */
    private const EXENTOS = [
        'src/Config/Config.php::Config::__construct' => 'sus 23 parámetros promovidos son la API pública '
            . 'documentada del value object; reducirlos rompería a quien la consume. La vía con parameter '
            . 'object es ConfigBuilder (y Config::fromArray(), que la usa por dentro).',
        'src/Renderer/Support/GlyphLayout.php::GlyphLayout::construye' => 'la geometría son diez valores y '
            . 'el constructor los nombra uno a uno (named arguments), así que la llamada ocupa doce líneas '
            . 'exactas por mucho que se reparta: partirla obligaría a agruparlos en un array de strings '
            . 'o a cambiar la superficie pública de propiedades que leen GlyphPainter, GdRenderer y sus '
            . 'tests. Lo que sí se extrajo son las medidas: FontMetrics y GlyphSize ya no se arrastran '
            . 'por seis parámetros.',
        'src/Storage/FileStorage.php::FileStorage::consumeUnderLock' => 'el fix de concurrencia requiere '
            . 'truncar el archivo a 0 bytes bajo lock antes de unlink() para garantizar uso único; las '
            . 'tres operaciones (stream_get_contents, ftruncate, fflush) son atómicas como bloque y no '
            . 'pueden extraerse sin romper la garantía de seguridad.',
        'src/Storage/FileStorage.php::FileStorage::parse' => 'debe detectar archivos vacíos (truncados por '
            . 'consumo concurrente) además de malformados y caducados; la validación secuencial de casos '
            . 'no admite partición sin duplicar el remove() en cada rama.',
    ];

    /**
     * @return Generator<string, array{0: string}>
     */
    public static function sourceProvider(): Generator
    {
        $root = dirname(__DIR__, 2);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $path = self::relative($entry->getPathname(), $root);

            if (self::isExcluded($path)) {
                continue;
            }

            yield $path => [$path];
        }
    }

    #[DataProvider('sourceProvider')]
    public function testNoMethodExceedsTheLineBudget(string $path): void
    {
        $offenders = [];

        foreach (self::methods($path) as $method) {
            if ($method['lineas'] <= self::PRESUPUESTO || self::isExempt($path, $method['nombre'])) {
                continue;
            }

            $offenders[] = sprintf(
                '%s::%s() mide %d líneas (presupuesto %d)',
                $path,
                $method['nombre'],
                $method['lineas'],
                self::PRESUPUESTO,
            );
        }

        self::assertSame([], $offenders, $this->explica($offenders));
    }

    /**
     * @return list<array{nombre: string, lineas: int}>
     */
    private static function methods(string $path): array
    {
        $root = dirname(__DIR__, 2);
        $tokens = token_get_all((string) file_get_contents($root . '/' . $path));
        $total = count($tokens);
        $methods = [];
        $depth = 0;

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                $depth += $token === '{' ? 1 : ($token === '}' ? -1 : 0);

                continue;
            }

            // Solo los métodos importan: una función suelta vive en profundidad 0.
            if ($token[0] !== T_FUNCTION || $depth !== 1) {
                continue;
            }

            $name = self::methodName($tokens, $i, $total);
            $body = $name === null ? null : self::bodyBounds($tokens, $i, $total);

            if ($name !== null && $body !== null) {
                $methods[] = ['nombre' => $name, 'lineas' => $body['cierre'] - $token[2] + 1];
            }

            /*
             *  Saltar al cierre en vez de a la apertura: el bucle exterior lleva
             *  la cuenta de llaves y debe ver la apertura y el cierre para que
             *  la profundidad vuelva a 1 al salir del método.
             */
            $i = $body['indice'] ?? $i;
        }

        return $methods;
    }

    /**
     * El nombre del método, o null si es una closure (no lleva nombre).
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function methodName(array $tokens, int $indice, int $total): ?string
    {
        for ($i = $indice + 1; $i < $total; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }

            if (!is_array($token) && ($token === '(' || $token === '{' || $token === ';')) {
                return null;
            }
        }

        return null;
    }

    /**
     * Índice y línea de las llaves que abren y cierran el cuerpo.
     *
     * Null cuando la firma no tiene cuerpo: las firmas de los contratos, que
     * no se pueden partir. Las llaves sueltas son tokens de una sola
     * carácter sin número de línea, así que la línea se lleva por delante con
     * el último token con posición conocida.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return array{indice: int, apertura: int, cierre: int}|null
     */
    private static function bodyBounds(array $tokens, int $indice, int $total): ?array
    {
        $parentesis = 0;
        $llaves = 0;
        $linea = 0;
        $apertura = null;

        for ($i = $indice; $i < $total; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                // La línea que informa el token es en la que empieza; sus saltos
                // de línea dejan la posición en la última del texto.
                $linea = $token[2] + substr_count($token[1], "\n");

                continue;
            }

            if ($token === '(' || $token === '[') {
                $parentesis++;
            }

            if ($token === ')' || $token === ']') {
                $parentesis--;
            }

            if ($parentesis !== 0) {
                continue;
            }

            if ($token === '{') {
                $llaves++;

                if ($apertura === null) {
                    $apertura = ['indice' => $i, 'linea' => $linea];
                }

                continue;
            }

            if ($token === '}') {
                $llaves--;

                if ($llaves === 0 && $apertura !== null) {
                    return ['indice' => $i, 'apertura' => $apertura['linea'], 'cierre' => $linea];
                }
            }

            if ($token === ';' && $apertura === null) {
                return null;
            }
        }

        return null;
    }

    private static function isExcluded(string $path): bool
    {
        foreach (self::EXCLUIDOS as $excluido) {
            if (str_starts_with($path, $excluido)) {
                return true;
            }
        }

        return false;
    }

    private static function isExempt(string $path, string $metodo): bool
    {
        return array_key_exists(self::label($path, $metodo), self::EXENTOS);
    }

    private static function label(string $path, string $metodo): string
    {
        $clase = basename($path, '.php');

        return $path . '::' . $clase . '::' . $metodo;
    }

    private static function relative(string $path, string $root): string
    {
        return str_starts_with($path, $root) ? substr($path, strlen($root) + 1) : $path;
    }

    /**
     * @param list<string> $offenders
     */
    private function explica(array $offenders): string
    {
        return $offenders === [] ? '' : sprintf(
            "Métodos por encima del presupuesto de %d líneas:\n%s\n\n"
            . 'Si el método se queda, la entrada va en SizeBudgetTest::EXENTOS con su motivo; '
            . 'si no, se parte en métodos que hagan una cosa cada uno.',
            self::PRESUPUESTO,
            implode("\n", $offenders),
        );
    }
}
