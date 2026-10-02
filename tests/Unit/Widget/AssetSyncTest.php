<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * Vela por el paso de minificación manual de los assets empaquetados.
 *
 * No hay paso de build: los captcha.css/js legibles son la fuente y los
 * captcha.min.css/js son lo que se distribuye. Este test lee ambas formas
 * y comprueba que son funcionalmente idénticas (sin comentarios, sin
 * whitespace fuera de cadenas entrecomilladas), de modo que un cambio en la
 * fuente que olvide regenerar la copia minificada falle de forma ruidosa en
 * lugar de distribuir assets obsoletos en silencio.
 */
final class AssetSyncTest extends TestCase
{
    private const ASSETS = __DIR__ . '/../../../src/resources/assets';

    public function testMinifiedJavaScriptMatchesTheReadableSource(): void
    {
        self::assertSame(
            $this->normalizeJs(self::read('captcha.js')),
            $this->normalizeJs(self::read('captcha.min.js')),
            'Edita captcha.js y regenera captcha.min.js a mano: el .min distribuido no puede divergir de la fuente.',
        );
    }

    public function testMinifiedCssMatchesTheReadableSource(): void
    {
        self::assertSame(
            $this->normalizeCss(self::read('captcha.css')),
            $this->normalizeCss(self::read('captcha.min.css')),
            'Edita captcha.css y regenera captcha.min.css a mano: el .min distribuido no puede divergir de la fuente.',
        );
    }

    private function read(string $file): string
    {
        $content = file_get_contents(self::ASSETS . '/' . $file);

        self::assertIsString($content, "No se pudo leer el asset {$file}.");

        return $content;
    }

    /**
     * Funcionalmente equivalente al minificador distribuido: elimina los
     * comentarios de línea y de bloque tras las cadenas, y después colapsa
     * cada tramo de whitespace fuera de las cadenas entrecomilladas. Quitar
     * TODO el whitespace (no solo colapsarlo) y comparar ambos lados con la
     * misma regla hace la comprobación independiente del whitespace exacto
     * que el minificador conserva junto a la puntuación.
     */
    private function normalizeJs(string $source): string
    {
        return $this->stripWhitespaceAndComments($source, stripLineComments: true);
    }

    private function normalizeCss(string $source): string
    {
        return $this->stripWhitespaceAndComments($source, stripLineComments: false);
    }

    private function stripWhitespaceAndComments(string $source, bool $stripLineComments): string
    {
        $out = '';
        $i = 0;
        $inString = null;
        $length = strlen($source);

        while ($i < $length) {
            $char = $source[$i];

            if ($inString !== null) {
                $out .= $char;

                if ($char === '\\') {
                    $out .= $source[$i + 1] ?? '';
                    $i += 2;

                    continue;
                }

                if ($char === $inString) {
                    $inString = null;
                }

                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = $char;
                $out .= $char;
                $i++;

                continue;
            }

            if ($stripLineComments && $char === '/' && ($source[$i + 1] ?? '') === '/') {
                while ($i < $length && $source[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $i += 2;

                while ($i < $length && !($source[$i] === '*' && ($source[$i + 1] ?? '') === '/')) {
                    $i++;
                }

                $i += 2;

                continue;
            }

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            $out .= $char;
            $i++;
        }

        return trim($out);
    }
}
