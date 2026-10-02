<?php

declare(strict_types=1);

namespace Captcha\Security;

use Captcha\Result\VerificationResult;
use Captcha\Verification\Status;

/**
 * Decisión inmutable de CaptchaGuard, la capa de seguridad agnóstica del
 * framework.
 *
 * Un middleware, filtro o controlador la mapea sobre la respuesta del host:
 * allowed → continuar el pipeline, idle → renderizar el formulario (GET /
 * primera visita), en los demás casos → cortocircuitar con $message y
 * $httpStatus. Las propiedades son public readonly a propósito; las fábricas
 * son la única vía para construir una instancia (el constructor queda
 * privado).
 */
final readonly class GuardDecision
{
    /**
     * Si la petición puede continuar (true para idle y ok verificado).
     */
    public bool $allowed;

    /**
     * Si la petición no traía reto alguno (primer render).
     */
    public bool $idle;

    /**
     * Resultado de la verificación (Missing también en la decisión idle).
     */
    public Status $status;

    /**
     * Estado HTTP sugerido para una respuesta cortocircuitada.
     */
    public int $httpStatus;

    /**
     * Retroalimentación en español para un envío denegado, literal — escápala
     * en la vista (es una cadena fija del propio paquete); vacío cuando se
     * permite.
     */
    public string $message;

    private function __construct(
        bool $allowed,
        bool $idle,
        Status $status,
        int $httpStatus,
        string $message,
    ) {
        $this->allowed = $allowed;
        $this->idle = $idle;
        $this->status = $status;
        $this->httpStatus = $httpStatus;
        $this->message = $message;
    }

    /**
     * Sin reto en la petición: el formulario se está renderizando y la
     * petición puede fluir (un GET o un formulario sin el campo oculto no
     * debe cortocircuitarse; el widget se encarga de la presentación).
     */
    public static function idle(): self
    {
        return new self(true, true, Status::Missing, 200, '');
    }

    /**
     * El envío omitió por completo el id de reto oculto.
     */
    public static function missing(): self
    {
        return new self(false, false, Status::Missing, self::httpStatus(Status::Missing), 'Código captcha no encontrado.');
    }    /**
     * Estado HTTP sugerido para cada resultado de verificación: 200 ok, 429
     * para una clave bloqueada (rate limit o honeypot) y 422 para toda
     * respuesta fallida o ausente (Unprocessable Entity, el estado de
     * validación de facto). Se conserva en la propia decisión para que el
     * mapeo sobreviva sin el guard (un mapa de estados no es una dependencia
     * de la capa de seguridad).
     */
    public static function httpStatus(Status $status): int
    {
        return match ($status) {
            Status::Ok => 200,
            Status::Invalid, Status::Expired, Status::Missing => 422,
            Status::Blocked => 429,
        };
    }

    /**
     * Mapea un resultado de verificación sobre una decisión.
     */
    public static function fromResult(VerificationResult $result): self
    {
        $ok = $result->isValid();

        return new self(
            $ok,
            false,
            $result->getStatus(),
            self::httpStatus($result->getStatus()),
            $ok ? '' : $result->getMessage(),
        );
    }
}
