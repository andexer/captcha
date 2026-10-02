<?php

declare(strict_types=1);

namespace Captcha\Result;

use Captcha\Verification\Status;

/**
 * Resultado inmutable de un intento de verificación de captcha.
 */
final readonly class VerificationResult
{
    /**
     * @param string $id Identificador del reto que se verificó.
     * @param Status $status Resultado legible por máquina.
     * @param string $message Mensaje legible por humanos (seguro para mostrar a usuarios).
     */
    public function __construct(
        private string $id,
        private Status $status,
        private string $message,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isValid(): bool
    {
        return $this->status === Status::Ok;
    }
}
