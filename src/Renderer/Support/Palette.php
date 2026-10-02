<?php

declare(strict_types=1);

namespace Captcha\Renderer\Support;

use Captcha\Config\Difficulty;

/**
 * Paleta de colores aleatoria para fondos, texto y ruido del captcha.
 *
 * Los fondos son claros y los frentes oscuros, de modo que el contraste
 * sigue legible incluso después de aplicar ruido y distorsión.
 *
 * @internal
 */
final class Palette
{
    /** @var list<array{int, int, int}> */
    private const BACKGROUNDS = [
        [235, 240, 245],
        [245, 240, 230],
        [230, 245, 235],
        [245, 235, 240],
        [240, 245, 250],
    ];

    /** @var list<array{int, int, int}> */
    private const FOREGROUNDS = [
        [20, 30, 60],
        [60, 20, 20],
        [20, 60, 30],
        [50, 20, 70],
        [20, 50, 70],
    ];

    public function background(): int
    {
        return $this->color(self::BACKGROUNDS);
    }

    public function foreground(): int
    {
        return $this->color(self::FOREGROUNDS);
    }

    /**
     * Tinte de tinta determinista empleado en la sombra de los dígitos: una
     * versión más oscura del fondo del lienzo, dividida hacia el color del
     * texto solo hasta la mitad. El suelo del 25 % garantiza un escalón
     * visible contra el fondo incluso cuando el fondo ya es oscuro.
     */
    public function shadow(int $background, int $foreground): int
    {
        $sombra = self::sombraDe($background, $foreground);

        return ($sombra[0] << 16) | ($sombra[1] << 8) | $sombra[2];
    }

    /**
     * @var array<string, int>
     */
    private const JITTER = [
        'low' => 0,
        'medium' => 40,
        'high' => 80,
    ];

    /**
     * Color de ruido semialeatorio derivado de la paleta de primer plano.
     */
    public function noise(Difficulty $difficulty): int
    {
        $base = self::FOREGROUNDS[random_int(0, count(self::FOREGROUNDS) - 1)];
        $jitter = self::JITTER[$difficulty->value];
        $rgb = [];

        foreach ($base as $channel) {
            $delta = $jitter > 0 ? random_int(-$jitter, $jitter) : 0;
            $rgb[] = max(0, min(255, $channel + $delta));
        }

        return ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2];
    }

    /**
     * @param list<array{int, int, int}> $colors
     */
    private function color(array $colors): int
    {
        [$r, $g, $b] = $colors[random_int(0, count($colors) - 1)];

        return ($r << 16) | ($g << 8) | $b;
    }

    /**
     * Los tres canales de la sombra, ya oscurecidos: el recorrido va por canal
     * porque el de color se calcula sobre su propio valor de fondo.
     *
     * @return list<int>
     */
    private static function sombraDe(int $background, int $foreground): array
    {
        $apagado = self::canales($background);
        $tinta = self::canales($foreground);
        $sombra = [];

        foreach ($apagado as $indice => $canal) {
            $sombra[] = self::sombraDeCanal($canal, $tinta[$indice]);
        }

        return $sombra;
    }

    /**
     * @return list<int>
     */
    private static function canales(int $color): array
    {
        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    private static function sombraDeCanal(int $canal, int $tinta): int
    {
        $apagado = (int) ($canal * 0.75);

        return (int) max($apagado, ($apagado + $tinta) / 2);
    }
}
