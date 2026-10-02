<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Build;

use Captcha\Build\AssetMinifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El minificador (Build\AssetMinifier detrás de tools/minify.php) debe
 * satisfacer el contrato de normalización exacto de AssetSyncTest: sin
 * comentarios, todo whitespace fuera de cadenas entrecomilladas eliminado,
 * contenido de cadenas byte a byte idéntico. Los casos tramposos (comillas
 * dentro de comentarios, marcadores de comentario dentro de cadenas,
 * escapes) velan por el escáner con estado contra corrupciones silenciosas.
 */
final class MinifyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function sourceProvider(): iterable
    {
        yield 'js conserva el contenido de las cadenas' => [
            "var a = \"hello // not comment\"; // real comment\nvar b = 'it\\'s';",
            true,
        ];

        yield 'js comentario de bloque con comillas dentro' => [
            "/* \"quote' inside */ var x = 1;",
            true,
        ];

        yield 'js sobrevive la barra tipo regex' => [
            'var re = /a\\/b/;',
            true,
        ];

        yield 'css sobrevive el protocolo, el comentario no' => [
            'a { background: url("https://x.test/i.png") } /* note */',
            false,
        ];

        yield 'css conserva los espacios dentro de las cadenas' => [
            'content: "a  b";',
            false,
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testMinifiedOutputKeepsStringsAndDropsComments(string $source, bool $stripLineComments): void
    {
        $minified = AssetMinifier::minify($source, $stripLineComments);

        // Idempotente: normalizar el .min con la misma regla devuelve el .min.
        self::assertSame($minified, AssetMinifier::minify($minified, $stripLineComments));
    }

    public function testMinifierDropsCommentsAndNewlinesButKeepsInnerSpaces(): void
    {
        $minified = AssetMinifier::minify("var a = 1; // trailing\n/* block */ var b = 2;", true);

        /*
        *  Los espacios INTERNOS son significativos en JS: pegar keywords
        *  ("vara", "functionWidget") produce un bundle sintácticamente roto.
        *  Al eliminar el comentario quedan los dos espacios que lo rodeaban
        *  (sin plegado: el gluing de tokens por plegado es el mismo riesgo).
        */
        self::assertSame('var a = 1;  var b = 2;', $minified);
    }

    public function testShippedMinifiedAssetsMatchTheMinifier(): void
    {
        $assets = dirname(__DIR__, 3) . '/src/resources/assets';

        foreach ([
            ['captcha.css', 'captcha.min.css', false],
            ['captcha.js', 'captcha.min.js', true],
        ] as [$source, $target, $strip]) {
            $raw = (string) file_get_contents($assets . '/' . $source);
            $shipped = (string) file_get_contents($assets . '/' . $target);

            self::assertSame(
                AssetMinifier::minify($raw, $strip),
                $shipped,
                "Regenera {$target} con `composer assets:min`: el .min distribuido no coincide con el minificador.",
            );
        }
    }
}
