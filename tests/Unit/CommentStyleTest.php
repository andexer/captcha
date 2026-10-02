<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vela por el contrato de estilo de los comentarios del paquete.
 *
 * El paquete es íntegramente español y sus comentarios se leen como
 * documentación, no como notas a pie de página. Tres reglas, y esta clase
 * existe para que ninguna se degrade en silencio:
 *
 *   1. Idioma. Ningún comentario fuera del PHPDoc puede contener prosa
 *      inglesa. El PHPDoc queda exento porque sus etiquetas son inglés por
 *      definición (@param, @return, @var, @internal) y porque las herramientas
 *      las leen como contrato, no como texto para humanos.
 *   2. Sin emojis. La tipografía funcional sí se admite (→, ⇒, •, ·), porque
 *      describe relaciones (descubrimiento del config, transiciones del
 *      guard) y no decora.
 *   3. Banners de sección. Cuando un comentario abre una sección usa el
 *      formato `// ── TÍTULO ─────` y la línea mide exactamente
 *      ANCHO_BANNER columnas contando la indentación, con relleno a la
 *      derecha. Sin relleno el bloque deja de leerse como una unidad.
 *
 * El escaneo usa token_get_all(), no expresiones regulares: distingue un
 * comentario real de un `//` que es contenido de una cadena (el fragmento de
 * config y las cabeceras del generador de plantillas se construyen con
 * literales `//` que NO son comentarios) y distingue T_COMMENT de
 * T_DOC_COMMENT, que es la frontera de la regla 1.
 */
final class CommentStyleTest extends TestCase
{
    /**
     * Columnas que debe medir un banner de sección, indentación incluida.
     */
    private const ANCHO_BANNER = 80;

    /**
     * Prefijo exacto de un banner de sección: dos rayas, espacio a cada
     * lado. Es lo que distingue un banner de un comentario de línea normal.
     */
    private const PREFIJO_BANNER = '// ── ';

    /**
     * Directorios escaneados, relativos a la raíz del paquete. bin/ entra
     * porque su cabecera documenta la CLI igual que cualquier clase.
     *
     * @var list<string>
     */
    private const ESCANEADOS = ['src', 'tests', 'tools', 'bin'];

    /**
     * Ficheros exentos de la regla de ancho (regla 3).
     *
     * Los dos guardan contenido serializado, no comentarios escritos a mano:
     *
     * - templates/*.php se GENERAN desde el fragmento. Su ancho lo decide
     *   tools/render-templates.php, y lo que garantiza que estén al día es
     *   TemplateRenderTest, no esta clase.
     * - fragments/options.php es un nowdoc flexible: PHP resta la
     *   indentación del marcador de cierre a cada línea, así que su ancho en
     *   el fuente (84) no es el que sale en la plantilla (80). Juzgarlo aquí
     *   mediría el mecanismo de PHP, no el estilo del paquete.
     *
     * @var list<string>
     */
    private const EXENTOS_DE_ANCHO = [
        'src/app/Config/fragments/options.php',
        'src/app/Config/templates/',
    ];

    /**
     * Emoji prohibidos, como rangos Unicode.
     *
     * Se cubren los bloques de emoticonos y símbolos, los suplementarios y el
     * selector de variación de presentación, que es el sufijo que convierte un
     * símbolo neutro en emoji. Se dejan fuera a propósito las flechas y los
     * puntos tipográficos, que el paquete usa como notación de relaciones
     * (descubrimiento del config, transiciones del guard) y no como
     * decoración; escribirlos aquí dispararía el test contra sí mismo.
     *
     * @var list<string>
     */
    private const EMOJIS = [
        '/\x{1F300}-\x{1FAFF}/u',   // pictogramas, emojis y suplementarios
        '/\x{1F900}-\x{1F9FF}/u',   // Supplementary Symbols and Pictographs
        '/\x{2600}-\x{26FF}/u',     // Misc Symbols
        '/\x{2700}-\x{27BF}/u',     // Dingbats
        '/\x{2B00}-\x{2BFF}/u',     // Misc Symbols and Arrows
        '/\x{FE0F}/u',              // selector de variación de presentación
    ];

    /**
     * Palabras inglesas inequívocas: no existen como término español y por
     * tanto no colisionan con identificadores técnicos citados en los
     * comentarios del paquete ('between', 'path', 'file', 'session' son
     * nombres de opción o de clase, y quedan fuera del léxico a propósito).
     *
     * @var list<string>
     */
    private const INGLES = [
        'the', 'this', 'that', 'these', 'those', 'they', 'them', 'their', 'there',
        'with', 'without', 'from', 'into', 'when', 'while', 'which', 'than', 'because',
        'however', 'therefore', 'thus', 'never', 'always', 'often', 'sometimes',
        'should', 'would', 'could', 'cannot', 'must', 'shall',
        'returns', 'return', 'make', 'makes', 'given', 'via', 'per',
        'keep', 'keeps', 'call', 'calls', 'pass', 'passes', 'throw', 'throws',
        'fail', 'fails', 'used', 'uses', 'following', 'above', 'below', 'during',
        'between', 'through', 'without', 'another', 'different', 'same', 'both',
    ];

    /**
     * @return \Generator<string, array{0: string}>
     */
    public static function sourceProvider(): \Generator
    {
        $root = dirname(__DIR__, 2);

        foreach (self::ESCANEADOS as $directory) {
            $path = $root . '/' . $directory;

            if (is_file($path)) {
                yield $directory => [$path];

                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $entry */
            foreach ($iterator as $entry) {
                if ($entry->isFile() && ($entry->getExtension() === 'php' || $entry->getExtension() === '')) {
                    yield self::relative($entry->getPathname(), $root) => [$entry->getPathname()];
                }
            }
        }
    }

    #[DataProvider('sourceProvider')]
    public function testSectionBannersAreEightyColumnsWideWithPadding(string $path): void
    {
        $offenders = [];

        foreach (self::commentLines($path) as $line) {
            $trimmed = ltrim($line['text']);

            if (! str_starts_with($trimmed, self::PREFIJO_BANNER)) {
                continue;
            }

            if (self::isExemptFromWidth($path)) {
                continue;
            }

            $width = mb_strlen($line['text'], 'UTF-8');

            // Sin guiones de cierre no es un banner, es un título suelto.
            if (! str_ends_with($trimmed, '─')) {
                $offenders[] = sprintf('%s sin relleno (mide %d)', self::label($path, $line), $width);

                continue;
            }

            if ($width !== self::ANCHO_BANNER) {
                $offenders[] = sprintf('%s mide %d, debe medir %d', self::label($path, $line), $width, self::ANCHO_BANNER);
            }
        }

        self::assertSame([], $offenders, $this->explica($offenders, 'banners de sección'));
    }

    #[DataProvider('sourceProvider')]
    public function testNoCommentCarriesAnEmoji(string $path): void
    {
        $offenders = [];

        foreach (self::commentLines($path) as $line) {
            foreach (self::EMOJIS as $pattern) {
                if (preg_match($pattern, $line['text']) === 1) {
                    $offenders[] = self::label($path, $line) . ' — ' . mb_substr($line['text'], 0, 60, 'UTF-8');
                }
            }
        }

        self::assertSame([], $offenders, $this->explica($offenders, 'emojis'));
    }

    /**
     * Prohibe la prosa inglesa fuera del PHPDoc.
     *
     * Se exigen DOS palabras del léxico consecutivas: una suelta casi siempre
     * es un identificador citado ('file', 'between', 'get'), pero dos seguidas
     * en un comentario solo pueden ser una frase. Ese umbral es el que hace
     * el test fiable: con una sola palabra, cada opción del config citada en
     * los comentarios dispararía un falso positivo.
     */
    #[DataProvider('sourceProvider')]
    public function testNoLineCommentIsWrittenInEnglish(string $path): void
    {
        $offenders = [];

        foreach (self::commentLines($path) as $line) {
            $palabras = self::englishRun($line['text']);

            if ($palabras !== '') {
                $offenders[] = self::label($path, $line) . ' — ' . $palabras;
            }
        }

        self::assertSame([], $offenders, $this->explica($offenders, 'prosa inglesa'));
    }

    /**
     * Toda línea de comentario del paquete, con su número de línea real.
     *
     * token_get_all() es lo que permite separar el comentario del `//` que es
     * contenido de cadena: en src/app/Config/fragments/options.php y en las
     * cabeceras de tools/render-templates.php, esas líneas son datos y
     * borrarlas de este escaneo las ocultaría del todo.
     *
     * El ancho se mide sobre la línea física del fichero, no sobre el texto
     * del token: el analizador de PHP descarta la sangría inicial de un
     * comentario `//`, así que un banner indentado 4 llegaría aquí como 76
     * columnas y el gate medaría 4 menos de las que tiene de verdad.
     *
     * @return \Generator<int, array{text: string, line: int}>
     */
    private static function commentLines(string $path): \Generator
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return;
        }

        $lineas = explode("\n", $contents);

        foreach (token_get_all($contents) as $token) {
            if (! is_array($token)) {
                continue;
            }

            // T_DOC_COMMENT queda fuera a propósito: es PHPDoc (regla 1).
            if ($token[0] !== T_COMMENT) {
                continue;
            }

            /*
            *  $token[2] es la línea (1-based) donde arranca el comentario;
            *  un bloque de varias líneas avanza una a una con el offset.
            */
            foreach (array_keys(explode("\n", rtrim($token[1], "\n"))) as $offset) {
                $numero = $token[2] + $offset;

                yield ['text' => $lineas[$numero - 1] ?? '', 'line' => $numero];
            }
        }
    }

    /**
     * Secuencia más larga de palabras inglesas consecutivas en un comentario.
     *
     * Se neutraliza antes de trocear todo lo que no es prosa: sintaxis PHP,
     * identificadores en camelCase y snake_case, CONSTANTES, URLs, números
     * y, sobre todo, la puntuación de los propios banners (rayas, asteriscos).
     */
    private static function englishRun(string $text): string
    {
        $limpio = preg_replace('/[@\\\\$][A-Za-z_][A-Za-z0-9_\\\\]*/', ' ', $text) ?? $text;
        $limpio = preg_replace('/\b[a-z]+[A-Z][A-Za-z0-9]*\b/', ' ', $limpio) ?? $limpio;
        $limpio = preg_replace('/\b[A-Za-z]+_[A-Za-z0-9_]+\b/', ' ', $limpio) ?? $limpio;
        $limpio = preg_replace('/https?:\/\/\S+/', ' ', $limpio) ?? $limpio;
        $limpio = preg_replace('/\b[A-Z]{2,}\b|\b\d+\b/', ' ', $limpio) ?? $limpio;
        $limpio = str_replace(['//', '/*', '*/', '*', '─', '═', '-', '—', '_', '→', '·'], ' ', $limpio);

        $ingles = array_flip(self::INGLES);
        $corrida = [];
        $mejor = [];

        foreach (preg_split('/[^A-Za-z]+/', $limpio) ?: [] as $palabra) {
            if ($palabra !== '' && isset($ingles[strtolower($palabra)])) {
                $corrida[] = $palabra;

                if (count($corrida) > count($mejor)) {
                    $mejor = $corrida;
                }

                continue;
            }

            $corrida = [];
        }

        return count($mejor) >= 2 ? implode(' ', $mejor) : '';
    }

    private static function isExemptFromWidth(string $path): bool
    {
        $normalizada = self::relative($path, dirname(__DIR__, 2));

        foreach (self::EXENTOS_DE_ANCHO as $exento) {
            if (str_starts_with($normalizada, $exento)) {
                return true;
            }
        }

        return false;
    }

    private static function label(string $path, array $line): string
    {
        return sprintf('%s:%d', self::relative($path, dirname(__DIR__, 2)), $line['line']);
    }

    private static function relative(string $path, string $root): string
    {
        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }

    /**
     * @param list<string> $offenders
     */
    private function explica(array $offenders, string $que): string
    {
        if ($offenders === []) {
            return '';
        }

        return sprintf(
            "\n%s en:\n  - %s\n\n%s",
            mb_strtoupper($que, 'UTF-8'),
            implode("\n  - ", $offenders),
            'Regla del paquete: PHPDoc en el idioma que sea; el resto de '
            . 'comentarios, en español, sin emojis y con los banners de sección '
            . 'a ' . self::ANCHO_BANNER . " columnas.\n",
        );
    }
}
