<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Renderer\Support;

use Captcha\Config\Config;
use Captcha\Config\Difficulty;
use Captcha\Renderer\Support\GlyphLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GlyphLayoutTest extends TestCase
{
    public function testLayoutOfSixDigitsOnTheDefaultSize(): void
    {
        $layout = GlyphLayout::forCode('777777', new Config(font: 1));

        self::assertSame(4, $layout->scale);
        self::assertSame(20, $layout->glyphWidth);
        self::assertSame(32, $layout->glyphHeight);
        self::assertSame(28, $layout->cellWidth);
        self::assertSame(6, $layout->left);
        self::assertSame(14, $layout->top);
    }

    public function testTheDefaultConfigFitsSixDigitsInsideTheCanvas(): void
    {
        $config = new Config();
        $layout = GlyphLayout::forCode('777777', $config);

        self::assertSame($config->font, $layout->font);
        self::assertGreaterThanOrEqual(1, $layout->scale);
        self::assertLessThanOrEqual($config->width, $layout->left + 6 * $layout->cellWidth);
        self::assertLessThanOrEqual($config->height, $layout->top + $layout->glyphHeight);
    }

    public function testExplicitFontSizeReplacesTheHeightTargetAndStaysInsideTheWidth(): void
    {
        $layout = GlyphLayout::forCode('77777', new Config(length: 5, font: 2, fontSize: 20));

        self::assertSame(2, $layout->scale);
        self::assertSame(12, $layout->glyphWidth);
        self::assertSame(26, $layout->glyphHeight);
        self::assertSame(16, $layout->cellWidth);
        self::assertSame(50, $layout->left);
        self::assertSame(17, $layout->top);
    }

    public function testTallestFontOnA80pxTallCanvasIsCappedByTheHeightBudget(): void
    {
        $layout = GlyphLayout::forCode('7777', new Config(length: 4, width: 200, height: 80, font: 3));

        self::assertSame(4, $layout->scale);
        self::assertSame(28, $layout->glyphWidth);
        self::assertSame(52, $layout->glyphHeight);
        self::assertSame(36, $layout->cellWidth);
        self::assertSame(28, $layout->left);
        self::assertSame(14, $layout->top);
    }

    public function testAnEmptyCodeStillProducesAUsableLayoutInsteadOfDividingByZero(): void
    {
        $layout = GlyphLayout::forCode('', new Config());

        self::assertGreaterThanOrEqual(1, $layout->scale);
        self::assertGreaterThan(0, $layout->glyphWidth);
        self::assertGreaterThan(0, $layout->glyphHeight);
    }

    #[DataProvider('jitterProvider')]
    public function testJitterGrowsWithTheDifficulty(Difficulty $difficulty, int $height, int $esperado): void
    {
        $layout = GlyphLayout::forCode('777777', new Config(height: $height, difficulty: $difficulty));

        self::assertSame($esperado, $layout->jitter);
    }

    /** @return iterable<string, array{Difficulty, int, int}> */
    public static function jitterProvider(): iterable
    {
        yield 'baja no desplaza' => [Difficulty::Low, 60, 0];
        yield 'media desplaza un 6 % del alto' => [Difficulty::Medium, 60, 3];
        yield 'media con piso de 2 px' => [Difficulty::Medium, 20, 2];
        yield 'alta desplaza un 12 % del alto' => [Difficulty::High, 60, 7];
        yield 'alta con piso de 4 px' => [Difficulty::High, 20, 4];
    }
}
