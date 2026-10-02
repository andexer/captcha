<?php

declare(strict_types=1);

namespace Captcha\Renderer\Support;

/**
 * Los dos colores con los que se pinta un glifo: su tinta y la sombra que la
 * separa del ruido de fondo.
 *
 * Viajan juntos a todas partes —los calcula una vez el renderizador y los
 * consume el pintado de cada dígito—, así que van en un objeto en lugar de
 * como dos enteros sueltos que el compilador no puede distinguir.
 */
final readonly class Ink
{
    public function __construct(
        public int $text,
        public int $shadow,
    ) {}
}
