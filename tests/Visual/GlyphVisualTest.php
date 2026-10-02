<?php

declare(strict_types=1);

namespace Captcha\Tests\Visual;

use Captcha\Config\Config;
use Captcha\Config\Difficulty;
use Captcha\Renderer\GdRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Genera PNGs de captcha de muestra en tests/Visual/output/ para
 * inspección manual: `ddev php vendor/bin/phpunit
 * tests/Visual/GlyphVisualTest.php` y luego abrir los PNGs en un visor. No
 * forma parte de la suite habitual (solo corren tests/Unit y
 * tests/Integration) para no tocar la CI.
 *
 * @internal
 */
final class GlyphVisualTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd es necesaria para los tests del renderizador.');
        }
    }

    #[Group('visual')]
    public function testGeneratesSamplePngs(): void
    {
        $renderer = new GdRenderer();
        $outDir = __DIR__ . '/output';

        if (!is_dir($outDir)) {
            mkdir($outDir, 0o777, true);
        }

        $cases = [
            'digits-medium-default' => new Config(),
            'digits-high' => new Config(difficulty: Difficulty::High),
            'digits-low' => new Config(difficulty: Difficulty::Low),
            'digits-large-canvas' => new Config(width: 260, height: 90),
            'digits-no-effects' => new Config(noise: false, distortion: false),
        ];

        foreach ($cases as $name => $config) {
            file_put_contents(
                $outDir . '/' . $name . '.png',
                $renderer->render('47391', $config),
            );

            self::assertFileExists($outDir . '/' . $name . '.png');
        }
    }
}
