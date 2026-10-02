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
 * Convierte en puerta las reglas duras que hasta ahora solo vivían escritas.
 *
 * Strict types, cero funciones de depuración en producción, cero operador @,
 * superglobales solo en los puntos sancionados, una sola salida directa y clases
 * cerradas por defecto: son reglas que se leen, se aceptan y luego se incumplen
 * sin ruido, porque nada avisa de que sea una regla. Este test es ese aviso.
 *
 * También se vigila a sí mismo: el último caso pone delante de los detectores
 * un fichero que rompe todas las reglas a la vez y exige que todas salte. Una
 * puerta que no puede fallar no es una puerta, y sin ese caso no hay forma de
 * saber si estos detectores detectan algo.
 */
final class ProductionRulesTest extends TestCase
{
    /**
     * Arboles donde se exige declare(strict_types=1) en cada fichero PHP.
     *
     * @var list<string>
     */
    private const ARBOLES = ['src', 'tests', 'tools', 'bin'];

    /**
     * Subdirectorios de src/ que no cuentan como producción: recetas y demos,
     * y el drop-in del endpoint, que es una frontera de arranque. Ambos ya no
     * se distribuyen (excluidos en .gitattributes) pero se mantienen en el
     * repo para desarrollo.
     *
     * @var list<string>
     */
    private const NO_PRODUCCION = ['src/examples', 'src/public'];

    /**
     * Funciones que no deben aparecer en producción. Son de inspección: en una
     * biblioteca, imprimir es decidir sobre el canal de salida de quien la usa.
     *
     * @var list<string>
     */
    private const PROHIBIDAS = [
        'var_dump', 'print_r', 'var_export', 'dd', 'dump', 'error_log', 'trigger_error',
        'extract', 'debug_zval_refcount', 'debug_print_backtrace', 'set_error_handler',
        'set_exception_handler',
    ];

    /**
     * Zonas de producción donde la salida directa está permitida, por prefijo.
     *
     * src/Http/JsonResponse.php es la frontera fina de I/O del endpoint: su
     * salida no es testeable —lo testeable son encode() y headers()—, así que
     * tiene que poder imprimir y terminar el proceso. Y el fragmento de
     * src/app/Config/fragments/ se imprime a propósito: tools/render-templates.php
     * lo incluye y captura lo que devuelve para componer las plantillas.
     *
     * @var list<string>
     */
    private const SALIDA_PERMITIDA = ['src/Http/JsonResponse.php', 'src/app/Config/fragments/'];

    /**
     * Ficheros de producción autorizados a tocar superglobales: el puente con
     * la petición y los dos almacenes que viven en la sesión.
     *
     * @var list<string>
     */
    private const SUPERGLOBALES_PERMITIDOS = [
        'src/Http/Globals.php',
        'src/Storage/SessionStorage.php',
        'src/Storage/SessionRateLimiter.php',
    ];

    /**
     * Clases que no son final a propósito, con el motivo.
     *
     * @var array<string, string>
     */
    private const CLASES_ABIERTAS = [
        'src/Exception/CaptchaException.php' => 'raíz de la jerarquía de excepciones: es el punto de '
            . 'extensión para que quien integra defina sus propias excepciones sin tocar el paquete. '
            . 'Sus descendientes sí son final.',
    ];

    /**
     * @return Generator<string, array{0: string}>
     */
    public static function phpProvider(): Generator
    {
        $root = dirname(__DIR__, 2);

        foreach (self::ARBOLES as $arbol) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $arbol, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $entry */
            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $relative = self::relative($entry->getPathname(), $root);

                    yield $relative => [$relative];
                }
            }
        }
    }

    /**
     * @return Generator<string, array{0: string}>
     */
    public static function productionProvider(): Generator
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

            $relative = self::relative($entry->getPathname(), $root);

            if (self::esProduccion($relative)) {
                yield $relative => [$relative];
            }
        }
    }

    #[DataProvider('phpProvider')]
    public function testEveryFileDeclaresStrictTypes(string $path): void
    {
        self::assertTrue(
            self::declaraStrictTypes($path),
            sprintf(
                '%s no declara strict_types=1: sin él, un string donde se espera un int se '
                . 'convierte en silencio y el error aflora en otro sitio.',
                $path,
            ),
        );
    }

    #[DataProvider('productionProvider')]
    public function testNoDebugFunctionsInProduction(string $path): void
    {
        self::assertSame([], self::llamadasProhibidas($path), sprintf(
            '%s llama a una función de inspección. En producción, imprimir es decidir sobre la '
            . 'salida de quien usa la biblioteca.',
            $path,
        ));
    }

    #[DataProvider('productionProvider')]
    public function testNoErrorSuppressionOperatorInProduction(string $path): void
    {
        self::assertSame([], self::operadorSilencio($path), sprintf(
            '%s usa el operador @. Se sustituye por prechecks: is_readable(), el === false del '
            . 'resultado y un aviso cuando la carrera sobrevive al precheck.',
            $path,
        ));
    }

    #[DataProvider('productionProvider')]
    public function testSuperglobalsOnlyWhereTheyAreSanctioned(string $path): void
    {
        if (in_array($path, self::SUPERGLOBALES_PERMITIDOS, true)) {
            self::assertNotSame([], self::superglobales($path), 'el fichero autorizado sí usa los suyos');

            return;
        }

        self::assertSame([], self::superglobales($path), sprintf(
            '%s lee una superglobal. El acceso al exterior de la petición va por Http\\Globals; '
            . 'los ficheros autorizados son %s.',
            $path,
            implode(', ', self::SUPERGLOBALES_PERMITIDOS),
        ));
    }

    #[DataProvider('productionProvider')]
    public function testDirectOutputIsConfinedToTheThinIoBoundary(string $path): void
    {
        if (self::tieneSalidaPermitida($path)) {
            self::assertNotSame([], self::salidaDirecta($path), 'el fichero autorizado sí imprime');

            return;
        }

        self::assertSame([], self::salidaDirecta($path), sprintf(
            '%s escribe en la salida o termina el proceso. La única frontera de I/O del paquete es '
            . '%s; el resto devuelve datos.',
            $path,
            implode(', ', self::SALIDA_PERMITIDA),
        ));
    }

    #[DataProvider('productionProvider')]
    public function testClassesAreFinalByDefault(string $path): void
    {
        $motivo = self::CLASES_ABIERTAS[$path] ?? null;

        if ($motivo !== null) {
            self::assertNotSame([], self::clasesNoFinales($path), 'la excepción sigue siendo necesaria');

            return;
        }

        self::assertSame([], self::clasesNoFinales($path), sprintf(
            '%s declara una clase que no es final. Cerrada por defecto, heredar es una decisión de '
            . 'quien lo pide; abierta por defecto, es una promesa que el paquete debe mantener. '
            . 'Si de verdad tiene que quedar abierta, va en ProductionRulesTest::CLASES_ABIERTAS '
            . 'con su motivo.',
            $path,
        ));
    }

    /**
     * Ninguna puerta sirve si sus detectores no detectan. Este caso pone delante
     * de ellos un fichero que rompe las seis reglas y exige las seis marcas.
     */
    public function testTheDetectorsFlagEveryRuleOfAViolatingFile(): void
    {
        $path = self::ficheroQueIncumple();

        try {
            self::assertFalse(self::declaraStrictTypes($path), 'no debe detectar strict types');
            self::assertSame([9], self::llamadasProhibidas($path), 'no debe detectar la llamada prohibida');
            self::assertSame([10], self::operadorSilencio($path), 'no debe detectar el operador @');
            self::assertSame([11], self::superglobales($path), 'no debe detectar la superglobal');
            self::assertSame([12, 13], self::salidaDirecta($path), 'no debe detectar la salida directa');
            self::assertSame([5], self::clasesNoFinales($path), 'no debe detectar la clase abierta');
        } finally {
            unlink($path);
        }
    }

    /**
     * Busca el declare(strict_types=1) con tokens y no con una expresión
     * regular: el token T_DECLARE solo contiene la palabra declare, y el resto
     * —strict_types, el igual y el uno— son tokens aparte. Un `declare` de
     * entidades, o un `declare(ticks=1)`, no cuentan.
     */
    private static function declaraStrictTypes(string $path): bool
    {
        $tokens = self::tokens($path);
        $total = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_DECLARE && self::esStrictTypes($tokens, $i)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function esStrictTypes(array $tokens, int $indice): bool
    {
        $total = count($tokens);

        for ($i = $indice + 1; $i < $total; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if ($token === '(') {
                continue;
            }

            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'strict_types') {
                return false;
            }

            $uno = $tokens[$i + 2] ?? null;

            return $tokens[$i + 1] === '=' && is_array($uno) && $uno[0] === T_LNUMBER && $uno[1] === '1';
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private static function llamadasProhibidas(string $path): array
    {
        return self::lineasDe($path, static function (array|string $token, array|string|null $previo) use ($path): bool {
            return self::nombreDeLlamada($token, $previo) !== null
                && in_array(self::nombreDeLlamada($token, $previo), self::PROHIBIDAS, true);
        });
    }

    /**
     * @return list<int>
     */
    private static function operadorSilencio(string $path): array
    {
        return self::lineasDe($path, static fn(array|string $token): bool => $token === '@');
    }

    /**
     * @return list<int>
     */
    private static function superglobales(string $path): array
    {
        return self::lineasDe($path, static fn(array|string $token): bool => is_array($token)
            && $token[0] === T_VARIABLE
            && in_array($token[1], self::superglobalesDePhp(), true));
    }

    /**
     * @return list<int>
     */
    private static function salidaDirecta(string $path): array
    {
        return self::lineasDe($path, static fn(array|string $token): bool => is_array($token)
            && in_array($token[0], [T_ECHO, T_PRINT, T_EXIT, T_EVAL], true));
    }

    /**
     * Solo se miran las clases: los enums son finales por definición del
     * lenguaje y no admiten la palabra final, así que no hay nada que exigirles.
     *
     * @return list<int>
     */
    private static function clasesNoFinales(string $path): array
    {
        $tokens = self::tokens($path);
        $total = count($tokens);
        $abiertas = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            if (!self::esDeclaracion($tokens, $i) || self::esAbstractaOCerrada($tokens, $i)) {
                continue;
            }

            $abiertas[] = $token[2];
        }

        return $abiertas;
    }

    /**
     * Números de línea de los tokens que cumplen la condición.
     *
     * La cuenta se lleva por delante del token anterior: un token de un solo
     * carácter —el @— no lleva posición, así que la línea en la que está es la
     * del último token con posición conocida.
     *
     * @param callable(array{int, string, int}|string, array{int, string, int}|string|null): bool $cumple
     *
     * @return list<int>
     */
    private static function lineasDe(string $path, callable $cumple): array
    {
        $lineas = [];
        $previo = null;
        $linea = 0;

        foreach (self::tokens($path) as $token) {
            if (is_array($token)) {
                $linea = $token[2] + substr_count($token[1], "\n");
            }

            if ($cumple($token, $previo)) {
                $lineas[] = $linea;
            }

            $previo = $token;
        }

        return $lineas;
    }

    /**
     * Una clase sin nombre no se puede heredar: `new class {}` no tiene nombre
     * ni subclases, así que final le sobra.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function esDeclaracion(array $tokens, int $indice): bool
    {
        $total = count($tokens);

        for ($i = $indice + 1; $i < $total; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING;
        }

        return false;
    }

    /**
     * Se acepta final y también abstract: una clase abstracta es un punto de
     * extensión declarado, no un descuido. Entre el final y la palabra de clase
     * solo puede haber modificadores y comentarios.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function esAbstractaOCerrada(array $tokens, int $indice): bool
    {
        $ignorados = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_READONLY];

        for ($i = $indice - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], $ignorados, true)) {
                continue;
            }

            return is_array($token) && in_array($token[0], [T_FINAL, T_ABSTRACT], true);
        }

        return false;
    }

    /**
     * El nombre de la función propia que se está llamando, o null si el token
     * no abre una: un método (->dump()), una constante de clase (::DUMP) o una
     * declaración no son la función global.
     *
     * @param array{int, string, int}|string|null $previo
     */
    private static function nombreDeLlamada(array|string $token, array|string|null $previo): ?string
    {
        if (!is_array($token) || $token[0] !== T_STRING) {
            return null;
        }

        if ($previo === null) {
            return null;
        }

        $sigueAUnaLlamada = is_array($previo) && in_array(
            $previo[0],
            [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION],
            true,
        );

        return $sigueAUnaLlamada ? null : $token[1];
    }

    /**
     * @return list<string>
     */
    private static function superglobalesDePhp(): array
    {
        return [
            '$_GET', '$_POST', '$_SERVER', '$_SESSION', '$_FILES',
            '$_ENV', '$_COOKIE', '$_REQUEST', '$GLOBALS',
        ];
    }

    /**
     * @return list<array{int, string, int}|string>
     */
    private static function tokens(string $path): array
    {
        $contenido = file_get_contents(self::ruta($path));

        return token_get_all($contenido === false ? '' : $contenido);
    }

    /**
     * Resuelve una ruta del paquete, o la deja como está si ya es absoluta:
     * los detectores se prueban también contra ficheros temporales.
     */
    private static function ruta(string $path): string
    {
        return preg_match('#^([A-Za-z]:)?[/\\\\]#', $path) === 1 ? $path : dirname(__DIR__, 2) . '/' . $path;
    }

    private static function tieneSalidaPermitida(string $path): bool
    {
        foreach (self::SALIDA_PERMITIDA as $prefijo) {
            if (str_starts_with($path, $prefijo)) {
                return true;
            }
        }

        return false;
    }

    private static function esProduccion(string $path): bool
    {
        foreach (self::NO_PRODUCCION as $excluido) {
            if (str_starts_with($path, $excluido)) {
                return false;
            }
        }

        return true;
    }

    private static function relative(string $path, string $root): string
    {
        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }

    /**
     * Un fichero que rompe las seis reglas, con las líneas en las que lo hace.
     */
    private static function ficheroQueIncumple(): string
    {
        $path = sys_get_temp_dir() . '/captcha-reglas-' . bin2hex(random_bytes(6)) . '.php';

        file_put_contents($path, implode("\n", [
            '<?php',
            '',
            'namespace Prueba;',
            '',
            'class Rota',
            '{',
            '    public function hacer(): void',
            '    {',
            '        print_r($algo);',
            '        $x = @file_get_contents($ruta);',
            '        $y = $_SERVER[\'HOME\'];',
            '        echo $x;',
            '        exit(0);',
            '    }',
            '}',
            '',
        ]));

        return $path;
    }
}
