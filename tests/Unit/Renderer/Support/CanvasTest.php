<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Renderer\Support;

use Captcha\Renderer\Support\Canvas;
use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanvasTest extends TestCase
{
    public function testCreatesAnImageOfTheRequestedSize(): void
    {
        $canvas = Canvas::create(40, 25);

        self::assertInstanceOf(GdImage::class, $canvas);
        self::assertSame(40, imagesx($canvas));
        self::assertSame(25, imagesy($canvas));
    }

    /**
     * GD exige enteros positivos y devuelve false sin avisar cuando no los
     * recibe, así que cada dimensión no positiva tiene que salir del recorte
     * antes de llegar a imagecreatetruecolor(), sin arrastrar a la otra.
     */
    #[DataProvider('tamanosNoPositivos')]
    public function testNonPositiveSizesAreClampedToOnePixel(
        int $width,
        int $height,
        int $esperadoAncho,
        int $esperadoAlto,
    ): void {
        $canvas = Canvas::create($width, $height);

        self::assertSame($esperadoAncho, imagesx($canvas), 'ancho');
        self::assertSame($esperadoAlto, imagesy($canvas), 'alto');
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function tamanosNoPositivos(): iterable
    {
        yield 'ambos a cero' => [0, 0, 1, 1];
        yield 'ambos negativos' => [-10, -10, 1, 1];
        yield 'solo el ancho no positivo' => [0, 30, 1, 30];
        yield 'solo el alto no positivo' => [30, 0, 30, 1];
    }

    public function testFillPaintsEveryPixelOfTheCanvas(): void
    {
        $canvas = Canvas::create(3, 3);
        $rojo = imagecolorallocate($canvas, 255, 0, 0);

        self::assertIsInt($rojo);
        Canvas::fill($canvas, $rojo);

        foreach ([[0, 0], [1, 1], [2, 2]] as [$x, $y]) {
            self::assertSame($rojo, imagecolorat($canvas, $x, $y), sprintf('(%d,%d)', $x, $y));
        }
    }
}
