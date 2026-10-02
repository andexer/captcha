<?php

declare(strict_types=1);

namespace Captcha\Request;

use Captcha\Verification\Status;

/**
 * Estado del flujo de verificación con ámbito de petición, fuente única de
 * verdad.
 *
 * Empaqueta lo que los métodos de flujo (valid(), message(), check())
 * necesitan sobre la verificación del envío actual. La fachada posee UN
 * value object de flujo por petición y cada método responde desde él; la
 * proyección Check se memoiza para que llamadas check() repetidas compartan
 * la misma instancia.
 *
 * Fiel a las semánticas legadas que unifica: check() filtra por el método
 * POST (idle en GET, nada verificado), mientras que valid()/message()
 * verifican lo que haya sido enviado cuando se les pregunta.
 *
 * @internal
 */
final class RequestFlow
{
    public readonly Status $status;

    public readonly string $message;

    private ?Check $check = null;

    public function __construct(Status $status, string $message)
    {
        $this->status = $status;
        $this->message = $message;
    }

    /**
     * Si el captcha enviado fue correcto.
     */
    public function passed(): bool
    {
        return $this->status === Status::Ok;
    }

    /**
     * Check listo para renderizar, para Captcha::check(). Memoizado: las
     * llamadas check() repetidas devuelven LA MISMA instancia. Solo se
     * consulta en POST (la fachada filtra antes de construir el flujo), así
     * que submitted es siempre true.
     */
    public function toCheck(): Check
    {
        return $this->check ??= new Check(true, $this->passed(), $this->passed() ? null : $this->message);
    }
}
