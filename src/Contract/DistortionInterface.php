<?php

declare(strict_types=1);

namespace Captcha\Contract;

use Captcha\Config\Config;
use GdImage;

interface DistortionInterface
{
    /**
     * Aplica una distorsión visual a la imagen y devuelve el resultado.
     *
     * La implementación puede mutar y devolver la misma instancia o devolver
     * una nueva; quien llama destruye cualquier imagen que quede huérfana.
     */
    public function apply(GdImage $image, Config $config): GdImage;
}
