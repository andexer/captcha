<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Renderer;

use Captcha\Config\Config;
use Captcha\Config\Difficulty;
use Captcha\Renderer\GdRenderer;
use PHPUnit\Framework\TestCase;

final class GdRendererTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is required for renderer tests.');
        }
    }

    public function testRendersValidPngBytes(): void
    {
        $png = (new GdRenderer())->render('47391', new Config());

        self::assertStringStartsWith("\x89PNG", $png);
        $info = getimagesizefromstring($png);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
    }

    public function testRendersConfiguredDimensions(): void
    {
        $config = new Config(width: 220, height: 80);
        $png = (new GdRenderer())->render('123', $config);

        $info = getimagesizefromstring($png);
        self::assertNotFalse($info);
        self::assertSame(220, $info[0]);
        self::assertSame(80, $info[1]);
    }

    public function testMimeTypeIsPng(): void
    {
        self::assertSame('image/png', (new GdRenderer())->mimeType());
    }

    public function testRendersWithoutNoiseOrDistortion(): void
    {
        $config = new Config(noise: false, distortion: false);
        $png = (new GdRenderer())->render('55555', $config);

        self::assertStringStartsWith("\x89PNG", $png);
    }

    public function testRendersAtEveryDifficulty(): void
    {
        $renderer = new GdRenderer();

        foreach (Difficulty::cases() as $difficulty) {
            $png = $renderer->render('90210', new Config(difficulty: $difficulty));
            self::assertStringStartsWith("\x89PNG", $png);
        }
    }

    public function testRendersAtMinimumSize(): void
    {
        $config = new Config(width: 1, height: 1, length: 3, noise: false, distortion: false);
        $png = (new GdRenderer())->render('123', $config);

        self::assertStringStartsWith("\x89PNG", $png);
    }

    public function testRendersWithEveryBuiltinFont(): void
    {
        $renderer = new GdRenderer();

        foreach ([1, 2, 3, 4, 5] as $font) {
            $png = $renderer->render('81234', new Config(font: $font, noise: false, distortion: false));

            self::assertStringStartsWith("\x89PNG", $png);
        }
    }

    public function testRendersWithCustomFontAndFontSize(): void
    {
        $config = new Config(font: 3, fontSize: 36, noise: false, distortion: false);
        $png = (new GdRenderer())->render('12345', $config);

        self::assertStringStartsWith("\x89PNG", $png);
        $info = getimagesizefromstring($png);
        self::assertNotFalse($info);
        self::assertSame($config->width, $info[0]);
        self::assertSame($config->height, $info[1]);
    }

    public function testExplicitFontSizeNeverOverflowsTheWidth(): void
    {
        $config = new Config(width: 120, height: 40, fontSize: 999, noise: false, distortion: false);
        $png = (new GdRenderer())->render('123', $config);

        self::assertStringStartsWith("\x89PNG", $png);
        $info = getimagesizefromstring($png);
        self::assertNotFalse($info);
        self::assertSame(120, $info[0]);
        self::assertSame(40, $info[1]);
    }

    public function testFontSizeSmallerThanTheGlyphUsesMinimumScale(): void
    {
        $config = new Config(fontSize: 2, noise: false, distortion: false);
        $png = (new GdRenderer())->render('741', $config);

        self::assertStringStartsWith("\x89PNG", $png);
    }
}
