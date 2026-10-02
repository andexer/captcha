<?php

declare(strict_types=1);

namespace Captcha\Contract;

use Captcha\Config\Config;

interface RendererInterface
{
    /**
     * Renderiza el código como una imagen binaria.
     *
     * @param string $code Código del captcha que hay que dibujar.
     * @param Config $config Opciones de renderizado (tamaño, dificultad, ruido...).
     *
     * @throws \Captcha\Exception\GdNotAvailableException Cuando GD no es utilizable.
     *
     * @return string Contenido binario de la imagen (bytes PNG).
     */
    public function render(string $code, Config $config): string;

    /**
     * Tipo MIME de la imagen renderizada ("image/png").
     */
    public function mimeType(): string;
}
