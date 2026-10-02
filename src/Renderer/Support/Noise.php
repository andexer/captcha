<?php

declare(strict_types=1);

namespace Captcha\Renderer\Support;

use Captcha\Config\Config;
use Captcha\Config\Difficulty;
use GdImage;

/**
 * Dibuja puntos y líneas aleatorios sobre la imagen del captcha.
 *
 * La intensidad escala con Difficulty; el flag noise de Config es el
 * interruptor maestro que el renderizador comprueba antes de llamar a esta
 * clase. Los recuentos base están afinados para un lienzo de ~180×60 y
 * escalan con el área del lienzo, de modo que las imágenes pequeñas
 * (120×40) no queden enterradas en puntos y las grandes conserven su
 * densidad.
 *
 * @internal
 */
final class Noise
{
    /**
     * Puntos y líneas dibujados por nivel de dificultad sobre el lienzo de
     * referencia.
     *
     * @var array<string, array{dots: int, lines: int}> Indexado por Difficulty->value.
     */
    private const INTENSITY = [
        'low' => ['dots' => 30, 'lines' => 2],
        'medium' => ['dots' => 80, 'lines' => 4],
        'high' => ['dots' => 160, 'lines' => 7],
    ];

    /** Área de referencia para los recuentos base (el lienzo clásico 180×60). */
    private const REFERENCE_AREA = 180 * 60;

    /** Recortes para que un lienzo diminuto no caiga a cero ni explote en 4K. */
    private const MIN_DOTS = 8;
    private const MAX_DOTS = 400;

    public function apply(GdImage $image, Config $config, Palette $palette): void
    {
        $intensity = self::INTENSITY[$config->difficulty->value];
        $surface = Surface::of($image);

        self::drawDots($surface, self::dotCount($intensity['dots'], $surface), $palette->noise($config->difficulty));
        self::drawLines($surface, $intensity['lines'], $config->difficulty, $palette);
    }

    /**
     * Misma densidad sin importar el tamaño del lienzo: los recuentos de
     * referencia son proporcionales al área (un lienzo de 120×40 es ~44 % de
     * 180×60, así que el ruido medium dibuja ~36 puntos en lugar de 80 sobre
     * un tercio de la superficie). El mínimo mantiene el flag visiblemente
     * activo y el máximo evita que un lienzo 4K se llene de ruido.
     */
    private static function dotCount(int $base, Surface $surface): int
    {
        $dots = (int) round($base * ($surface->width * $surface->height) / self::REFERENCE_AREA);

        return max(self::MIN_DOTS, min(self::MAX_DOTS, $dots));
    }

    private static function drawDots(Surface $surface, int $dots, int $color): void
    {
        for ($i = 0; $i < $dots; $i++) {
            imagesetpixel($surface->image(), $surface->randomX(), $surface->randomY(), $color);
        }
    }

    /**
     * Cada línea sortea su propio color de ruido, porque Palette::noise() es
     * aleatorio: calcularlo una vez y reutilizarlo haría que todas las líneas
     * salieran del mismo tono, que no es lo que hace el render original.
     */
    private static function drawLines(Surface $surface, int $lines, Difficulty $difficulty, Palette $palette): void
    {
        for ($i = 0; $i < $lines; $i++) {
            self::line($surface, $palette->noise($difficulty));
        }
    }

    private static function line(Surface $surface, int $color): void
    {
        imageline(
            $surface->image(),
            $surface->randomX(),
            $surface->randomY(),
            $surface->randomX(),
            $surface->randomY(),
            $color,
        );
    }
}
