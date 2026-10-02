<?php

declare(strict_types=1);

namespace Captcha\Renderer\Support;

/**
 * Las medidas del glifo y de su celda ya multiplicadas por el factor de
 * escalado.
 *
 * @internal
 */
final readonly class GlyphSize
{
    public function __construct(
        public int $width,
        public int $height,
        public int $cellWidth,
    ) {}
}
