<?php

declare(strict_types=1);

namespace Captcha\Renderer\Support;

/**
 * Las medidas de una fuente bitmap de GD, leídas una sola vez.
 *
 * imagefontwidth() e imagefontheight() cambian según el build de la extensión,
 * así que no pueden ser constantes del paquete; leerlas una vez por geometría
 * evita además que el ancho y el alto que se usan para escalar y para medir
 * vengan de dos llamadas distintas.
 *
 * @internal
 */
final readonly class FontMetrics
{
    public function __construct(
        public int $width,
        public int $height,
        public int $spacing,
    ) {}

    public static function of(int $font, int $gap): self
    {
        $width = imagefontwidth($font);

        return new self($width, imagefontheight($font), $width + $gap);
    }

    public function scaled(int $scale): GlyphSize
    {
        return new GlyphSize($this->width * $scale, $this->height * $scale, $this->spacing * $scale);
    }
}
