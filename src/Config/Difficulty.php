<?php

declare(strict_types=1);

namespace Captcha\Config;

/**
 * Nivel de dificultad del captcha; gradúa la intensidad de ruido, líneas y
 * distorsión.
 */
enum Difficulty: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Inclinación máxima por glifo, en grados.
     *
     * Cero significa "no rotar": el modo barato y de máxima legibilidad. La
     * dificultad superior ensancha el rango sin llegar a los ángulos grandes
     * que vuelven ambiguos los dígitos (un 4 rotado y un 1 rotado dejan de
     * distinguirse).
     */
    public function rotationRange(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 8,
            self::High => 12,
        };
    }

    /**
     * Fracción del alto del lienzo que el desplazamiento vertical puede
     * recorrer. Multiplicado por el alto del lienzo da el rango del jitter.
     */
    public function jitterFactor(): float
    {
        return match ($this) {
            self::Low => 0.0,
            self::Medium => 0.06,
            self::High => 0.12,
        };
    }

    /**
     * Piso del desplazamiento en píxeles.
     *
     * Sin él, un lienzo de 20 px de alto con dificultad media daría
     * 20 * 0.06 = 1 píxel de separación, que no separa nada visible. El piso
     * garantiza un mínimo de separación en lienzos pequeños.
     */
    public function jitterFloor(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 2,
            self::High => 4,
        };
    }
}
