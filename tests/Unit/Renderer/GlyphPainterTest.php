<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Renderer;

use Captcha\Config\Config;
use Captcha\Config\Difficulty;
use Captcha\Renderer\GlyphPainter;
use Captcha\Renderer\Support\Canvas;
use Captcha\Renderer\Support\GlyphLayout;
use Captcha\Renderer\Support\Ink;
use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GlyphPainterTest extends TestCase
{
    private const FONDO = 0xFFFFFF;

    private GdImage $lienzo;

    private Ink $tinta;

    /**
     * Pinta un carácter y devuelve el lienzo ya modificado.
     *
     * La tinta se reserva una sola vez sobre el lienzo porque en truecolor cada
     * imagecolorallocate() devuelve un índice nuevo aunque el color sea el
     * mismo: releerla después compararía contra un índice que el glifo nunca
     * usó, y la comprobación de ausencia pasaría siempre.
     */
    private function pintar(string $char, Config $config): GdImage
    {
        $this->lienzo = Canvas::create($config->width, $config->height);
        Canvas::fill($this->lienzo, self::FONDO);

        $texto = imagecolorallocate($this->lienzo, 20, 20, 20);
        $sombra = imagecolorallocate($this->lienzo, 128, 128, 128);
        self::assertIsInt($texto);
        self::assertIsInt($sombra);

        $this->tinta = new Ink(text: $texto, shadow: $sombra);

        $layout = GlyphLayout::forCode($char, $config);
        (new GlyphPainter($this->lienzo, $config, $this->tinta, $layout, self::FONDO))
            ->paint($char, $layout->left, $layout->top);

        return $this->lienzo;
    }

    /** @return list<int> */
    private function coloresDistintosDelFondo(GdImage $lienzo): array
    {
        $vistos = [];

        for ($x = 0; $x < imagesx($lienzo); $x++) {
            for ($y = 0; $y < imagesy($lienzo); $y++) {
                $color = imagecolorat($lienzo, $x, $y);
                if (is_int($color) && $color !== self::FONDO) {
                    $vistos[$color] = true;
                }
            }
        }

        return array_map(intval(...), array_keys($vistos));
    }

    // ── TINTA ────────────────────────────────────────────────────────────────

    public function testPaintsInkOfTheTextColor(): void
    {
        $lienzo = $this->pintar('7', new Config(difficulty: Difficulty::Low));

        self::assertContains($this->tinta->text, $this->coloresDistintosDelFondo($lienzo));
    }

    public function testWithoutNoiseNoShadowColorReachesTheCanvas(): void
    {
        $lienzo = $this->pintar('7', new Config(difficulty: Difficulty::Low, noise: false));

        self::assertNotContains($this->tinta->shadow, $this->coloresDistintosDelFondo($lienzo));
    }

    /**
     * El glifo se pega relleno de fondo, así que borra el ruido de su propio
     * rectángulo aunque no haya sombra: un dígito limpio sobre fondo liso.
     */
    public function testTheGlyphErasesTheNoiseUnderItsOwnFootprint(): void
    {
        $borrados = $this->ruidoBorrado(new Config(difficulty: Difficulty::Low, noise: false));

        self::assertGreaterThan(0, $borrados, 'El glifo debería tapar el ruido de su rectángulo.');
    }

    /**
     * Con ruido activo se pega antes el bitmap de sombra, desplazado un píxel,
     * y por tanto borra además el anillo que rodea al glifo. Ese anillo es el
     * halo limpio que separa el dígito del resto del ruido.
     */
    public function testTheShadowWidensTheErasedAreaByOnePixelRing(): void
    {
        $sinSombra = $this->ruidoBorrado(new Config(difficulty: Difficulty::Low, noise: false));
        $conSombra = $this->ruidoBorrado(new Config(difficulty: Difficulty::Low, noise: true));

        self::assertGreaterThan(
            $sinSombra,
            $conSombra,
            'La sombra no amplió el área limpia alrededor del glifo.',
        );
    }

    /**
     * Pinta un glifo sobre un lienzo lleno de ruido sintético y cuenta cuántos
     * píxeles de ruido quedaron borrados.
     */
    private function ruidoBorrado(Config $config): int
    {
        $this->lienzo = Canvas::create($config->width, $config->height);
        Canvas::fill($this->lienzo, self::FONDO);

        $rojo = imagecolorallocate($this->lienzo, 255, 0, 0);
        self::assertIsInt($rojo);

        for ($x = 0; $x < imagesx($this->lienzo); $x++) {
            for ($y = 0; $y < imagesy($this->lienzo); $y++) {
                if (($x * 7 + $y * 13) % 11 === 0) {
                    imagesetpixel($this->lienzo, $x, $y, $rojo);
                }
            }
        }

        $antes = $this->cuentaPixeles($this->lienzo, $rojo);

        $texto = imagecolorallocate($this->lienzo, 20, 20, 20);
        $sombra = imagecolorallocate($this->lienzo, 128, 128, 128);
        self::assertIsInt($texto);
        self::assertIsInt($sombra);
        $this->tinta = new Ink(text: $texto, shadow: $sombra);

        $layout = GlyphLayout::forCode('7', $config);
        (new GlyphPainter($this->lienzo, $config, $this->tinta, $layout, self::FONDO))
            ->paint('7', $layout->left, $layout->top);

        return $antes - $this->cuentaPixeles($this->lienzo, $rojo);
    }

    private function cuentaPixeles(GdImage $lienzo, int $color): int
    {
        $cuenta = 0;

        for ($x = 0; $x < imagesx($lienzo); $x++) {
            for ($y = 0; $y < imagesy($lienzo); $y++) {
                if (imagecolorat($lienzo, $x, $y) === $color) {
                    $cuenta++;
                }
            }
        }

        return $cuenta;
    }

    // ── ROBUSTEZ ─────────────────────────────────────────────────────────────

    #[DataProvider('caracteres')]
    public function testPaintsAnyCharacterTheGeneratorCanEmit(string $char): void
    {
        $lienzo = $this->pintar($char, new Config(difficulty: Difficulty::Low, noise: true));

        self::assertInstanceOf(GdImage::class, $lienzo);
        self::assertNotSame([], $this->coloresDistintosDelFondo($lienzo));
    }

    /** @return iterable<string, array{string}> */
    public static function caracteres(): iterable
    {
        yield 'cero' => ['0'];
        yield 'cuatro' => ['4'];
        yield 'nueve' => ['9'];
        yield 'signo de suma' => ['+'];
        yield 'asterisco' => ['*'];
        yield 'signo de division' => ['/'];
    }

    #[DataProvider('dificultades')]
    public function testEveryDifficultyKeepsTheGlyphInsideTheCanvas(Difficulty $dificultad): void
    {
        $config = new Config(difficulty: $dificultad, width: 120, height: 24, noise: true);
        $this->lienzo = Canvas::create($config->width, $config->height);
        Canvas::fill($this->lienzo, self::FONDO);

        $texto = imagecolorallocate($this->lienzo, 20, 20, 20);
        $sombra = imagecolorallocate($this->lienzo, 128, 128, 128);
        self::assertIsInt($texto);
        self::assertIsInt($sombra);

        $this->tinta = new Ink(text: $texto, shadow: $sombra);
        $layout = GlyphLayout::forCode('77777', $config);
        $pintor = new GlyphPainter($this->lienzo, $config, $this->tinta, $layout, self::FONDO);

        $x = $layout->left;
        for ($i = 0; $i < 5; $i++) {
            $pintor->paint('7', $x, $layout->top);
            $x += $layout->cellWidth;
        }

        self::assertNotSame([], $this->coloresDistintosDelFondo($this->lienzo), 'No quedó nada pintado.');

        /*
         * Se acumulan los píxeles fuera del lienzo en vez de comprobar uno a
         * uno: el glifo se pinta sobre ruido aleatorio, así que cuántos
         * píxeles caen en el color del texto cambia en cada ejecución y el
         * recuento de aserciones era impredecible. La garantía es la misma
         * (ninguno se sale) y el fallo muestra las coordenadas.
         */
        $fuera = [];

        foreach ($this->posicionesDe($this->lienzo, $texto) as [$px, $py]) {
            if ($px < 0 || $py < 0 || $px >= $config->width || $py >= $config->height) {
                $fuera[] = [$px, $py];
            }
        }

        self::assertSame([], $fuera, 'Píxeles del glifo fuera del lienzo.');
    }

    /** @return iterable<string, array{Difficulty}> */
    public static function dificultades(): iterable
    {
        yield 'baja' => [Difficulty::Low];
        yield 'media' => [Difficulty::Medium];
        yield 'alta' => [Difficulty::High];
    }

    /**
     * Coordenadas de cada píxel pintado con un color concreto.
     *
     * @return list<array{int, int}>
     */
    private function posicionesDe(GdImage $lienzo, int $color): array
    {
        $posiciones = [];

        for ($x = 0; $x < imagesx($lienzo); $x++) {
            for ($y = 0; $y < imagesy($lienzo); $y++) {
                if (imagecolorat($lienzo, $x, $y) === $color) {
                    $posiciones[] = [$x, $y];
                }
            }
        }

        return $posiciones;
    }
}
