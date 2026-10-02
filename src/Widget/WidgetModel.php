<?php

declare(strict_types=1);

namespace Captcha\Widget;

use Captcha\Config\Config;
use Captcha\Result\CaptchaResult;

/**
 * View model para el widget: la porción de Config y CaptchaResult que el
 * markup necesita de verdad.
 *
 * Widget solía depender de la fachada Captcha entera para leer un puñado de
 * opciones; ahora depende de este DTO inmutable. La fachada (o cualquier
 * otro llamador) lo construye desde su propia Config; un modelo construido
 * a mano hace a Widget testeable por unidades sin la fachada. Cada campo se
 * escapa en tiempo de render — el modelo transporta valores crudos.
 *
 * @internal
 */
final readonly class WidgetModel
{
    /**
     * @param string $inputField Nombre del campo de entrada del código.
     * @param string $idField Nombre del campo oculto del id del reto.
     * @param int $length Longitud máxima del input del código (maxlength).
     * @param int $width Ancho de la imagen renderizada en píxeles.
     * @param int $height Alto de la imagen renderizada en píxeles.
     * @param bool $honeypot Si se renderiza el campo trampa oculto.
     * @param string $honeypotField Nombre del campo trampa.
     */
    public function __construct(
        public string $inputField,
        public string $idField,
        public int $length,
        public int $width,
        public int $height,
        public bool $honeypot,
        public string $honeypotField,
    ) {}

    /**
     * Construye el view model desde una Config (el único punto de llamada de
     * la fachada).
     */
    public static function fromConfig(Config $config): self
    {
        return new self(
            inputField: $config->inputField,
            idField: $config->idField,
            length: $config->length,
            width: $config->width,
            height: $config->height,
            honeypot: $config->honeypot,
            honeypotField: $config->honeypotField,
        );
    }
}
