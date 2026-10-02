<?php

declare(strict_types=1);

namespace Captcha\Renderer\Support;

use Captcha\Config\Config;
use Captcha\Config\Difficulty;
use Captcha\Contract\DistortionInterface;
use Captcha\Exception\GdNotAvailableException;
use GdImage;

/**
 * Distorsión de onda horizontal: cada fila de píxeles se desplaza según un
 * desfase sinusoidal.
 *
 * La amplitud y la longitud de onda varían con la dificultad configurada.
 *
 * @internal
 */
final class Distortion implements DistortionInterface
{
    private const WAVE_LENGTH = 40.0;

    public function apply(GdImage $image, Config $config): GdImage
    {
        $surface = Surface::of($image);

        if ($surface->height < 3) {
            return $image;
        }

        $distorted = self::canvas($surface);
        $this->deformar($distorted, $surface, self::amplitude($config->difficulty, $surface->height), self::phase());

        return $distorted;
    }

    private static function phase(): float
    {
        return random_int(0, 360) * M_PI / 180;
    }

    private function deformar(GdImage $distorted, Surface $surface, float $amplitude, float $phase): void
    {
        for ($y = 0; $y < $surface->height; $y++) {
            self::warpRow($distorted, $surface, self::shiftAt($amplitude, $y, $phase), $y);
        }
    }

    /**
     * El desplazamiento horizontal de una fila, que puede ser negativo: la
     * onda sube y baja.
     */
    private static function shiftAt(float $amplitude, int $y, float $phase): int
    {
        return (int) round($amplitude * sin(2 * M_PI * $y / self::WAVE_LENGTH + $phase));
    }

    /**
     * Amplitud de la onda según la dificultad, acotada para que las filas nunca
     * se desplacen más allá de la mitad de la imagen.
     */
    private static function amplitude(Difficulty $difficulty, int $height): float
    {
        $amplitude = match ($difficulty) {
            Difficulty::Low => 1.5,
            Difficulty::Medium => 3.0,
            Difficulty::High => 5.0,
        };

        return min($amplitude, max(1.0, $height / 4));
    }

    /**
     * @throws GdNotAvailableException Cuando GD no concede la imagen.
     */
    private static function canvas(Surface $surface): GdImage
    {
        $distorted = imagecreatetruecolor(max(1, $surface->width), max(1, $surface->height));

        if ($distorted === false) {
            throw new GdNotAvailableException('No se pudo reservar memoria para la imagen distorsionada.');
        }

        imagesavealpha($distorted, true);
        imagefill($distorted, 0, 0, imagecolorallocate($distorted, 255, 255, 255));

        return $distorted;
    }

    /**
     * Copia la fila tres veces para que los bordes envueltos llenen todo el
     * ancho, sin huecos en los extremos.
     */
    private static function warpRow(GdImage $distorted, Surface $source, int $shift, int $y): void
    {
        $image = $source->image();
        $width = $source->width;

        imagecopy($distorted, $image, $shift, $y, 0, $y, $width, 1);
        imagecopy($distorted, $image, $shift - $width, $y, 0, $y, $width, 1);
        imagecopy($distorted, $image, $shift + $width, $y, 0, $y, $width, 1);
    }
}
