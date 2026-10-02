<?php

declare(strict_types=1);

use Captcha\Captcha;
use Captcha\Security\CaptchaGuard as Guard;
use Captcha\Security\GuardDecision;

/**
 * Verificación del captcha en una aplicación PHP sin framework.
 *
 * Aquí no hay pipeline de middleware donde colgar la decisión, así que el
 * paquete reduce su integración a dos llamadas estáticas: decide() devuelve el
 * veredicto completo para quien quiera imponer su propia política de respuesta,
 * y enforce() lo traduce a la tupla [status, payload] que espera un controlador
 * que solo pueda emitir JSON.
 *
 * Los dos métodos pasan requireSubmission: true porque se llaman desde el
 * manejador del POST, donde el campo oculto captcha_id tiene que estar. Sin ese
 * argumento el guard deja pasar una petición que no trae reto, y una app que
 * solo se olvide de llamar a enforce() quedaría sin protección en silencio.
 *
 * El widget dibuja el reto con Captcha::widget() y el endpoint que genera los
 * siguientes vive en public/captcha.php; indícalo con
 * Captcha::widget(['endpoint' => '/captcha.php']) para que la recarga AJAX
 * tenga destino.
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
