<?php

declare(strict_types=1);

use Captcha\Captcha;
use Captcha\Security\CaptchaGuard as Guard;
use Captcha\Security\GuardDecision;

/**
 * Verificación del captcha en una aplicación PHP sin framework.
 *
 * Sin pipeline de middleware, la integración son dos llamadas estáticas:
 * decide() devuelve el veredicto completo y enforce() la tupla [status,
 * payload] lista para responder.
 *
 * Ambas pasan requireSubmission: true porque se llaman desde el manejador del
 * POST: sin ese argumento, el guard deja pasar una petición que no trae reto.
 *
 * El endpoint de recarga vive en public/captcha.php; indícalo al dibujar con
 * Captcha::widget(['endpoint' => '/captcha.php']).
 */
final class CaptchaGuard
{
    /**
     * El veredicto completo, para cuando la respuesta de un fallo dependa de
     * más que del código HTTP.
     *
     * @param array<string, mixed> $data Campos enviados por el cliente.
     */
    public static function decide(array $data): GuardDecision
    {
        return (new Guard(Captcha::instance()))->decide($data, requireSubmission: true);
    }

    /**
     * El veredicto como tupla lista para responder: 200 con ok cuando la
     * petición puede continuar, y el estado que el guard sugiere con el
     * mensaje en español cuando no.
     *
     * @param array<string, mixed> $data Campos enviados por el cliente.
     *
     * @return array{0: int, 1: array<string, scalar>}
     */
    public static function enforce(array $data): array
    {
        $decision = self::decide($data);

        return $decision->allowed
            ? [200, ['ok' => true]]
            : [$decision->httpStatus, ['ok' => false, 'error' => $decision->message]];
    }
}
