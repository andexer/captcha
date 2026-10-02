<?php

declare(strict_types=1);

namespace Captcha\Renderer\Support;

use GdImage;

/**
 * El lienzo GD con sus medidas, para no arrastrar imagen + ancho + alto por
 * cada helper de dibujo.
 *
 * Las medidas se leen una sola vez (imagesx/imagesy son llamadas al backend)
 * y los helpers piden puntos aleatorios sin recalcular los límites, que es el
 * error clásico al ruido: si el alto es cero, random_int(0, -1) lanza.
 *
 * @internal
 */
final readonly class Surface
{
    public function __construct(
        private GdImage $image,
        public int $width,
        public int $height,
    ) {}

    public static function of(GdImage $image): self
    {
        return new self($image, imagesx($image), imagesy($image));
    }

    /**
     * Una columna aleatoria dentro del lienzo.
     */
    public function randomX(): int
    {
        return random_int(0, max(0, $this->width - 1));
    }

    /**
     * Una fila aleatoria dentro del lienzo.
     */
    public function randomY(): int
    {
        return random_int(0, max(0, $this->height - 1));
    }

    public function image(): GdImage
    {
        return $this->image;
    }
}
