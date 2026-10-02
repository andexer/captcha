<?php

declare(strict_types=1);

namespace Captcha\Console;

use Captcha\Exception\CaptchaException;

/**
 * Fallo de la capa de consola: línea de comandos mal formada.
 *
 * No es un error de configuración del captcha sino de lo que alguien escribió
 * en la terminal, así que no reutiliza InvalidConfigException: quien la
 * captura espera un config inválido, y aquí lo que falla es el argv. Comparte
 * la base CaptchaException para que un único catch cubra el paquete entero.
 */
final class ConsoleException extends CaptchaException
{
    /**
     * @param string $message Texto ya redactado para mostrar en pantalla.
     * @param string|null $hint Sugerencia adicional, en su propia línea.
     * @param int $exitCode Código de salida con el que debe terminar el proceso.
     */
    public function __construct(
        string $message,
        private readonly ?string $hint = null,
        private readonly int $exitCode = 1,
    ) {
        parent::__construct($message);
    }

    /**
     * Sugerencia adicional para acompañar al error, o null si no la hay.
     */
    public function hint(): ?string
    {
        return $this->hint;
    }

    /**
     * Código de salida con el que debe terminar el proceso.
     */
    public function exitCode(): int
    {
        return $this->exitCode;
    }
}
