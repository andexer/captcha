<?php

declare(strict_types=1);

namespace Captcha\Http;

use Captcha\Runtime\Host;

/**
 * Parámetros de cookie endurecidos para las sesiones que el propio paquete
 * arranca.
 *
 * Solo se tocan los hosts de PHP plano: bajo un framework propietario de la
 * sesión PHP la regla es "jamás preemptar al host", así que harden()
 * devuelve un array vacío. También se respeta a un host de PHP plano que ya
 * configuró sus propios parámetros de cookie (que aparezcan httponly y/o
 * SameSite significa que la app es dueña del ajuste). En los demás casos el
 * paquete pide HttpOnly + SameSite=Lax en cada sesión que arranca, más
 * Secure cuando el cliente habla HTTPS (el flag Secure sobre una conexión
 * HTTP plana rompería la sesión sin más).
 */
final class SessionCookie
{
    /**
     * Los parámetros de cookie que aplicar justo antes de un session_start()
     * que el paquete va a ejecutar por sí mismo, o [] cuando nada debe
     * cambiar.
     *
     * Pura (sin efectos secundarios) para que la decisión sea testeable por
     * unidades; quienes llaman, en SessionStorage / SessionRateLimiter,
     * aplican el resultado.
     *
     * El contrato del host manda: bajo un framework propietario de la sesión
     * el paquete nunca reconfigura las cookies.
     *
     * @return array{httponly?: true, samesite?: 'Lax', secure?: true}
     */
    public static function harden(): array
    {
        if (Host::frameworkSessionManaged() || self::hostHasHardened()) {
            return [];
        }

        $params = [
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        return Globals::isHttps() ? $params + ['secure' => true] : $params;
    }

    /**
     * Si el anfitrión ya endureció la cookie: sus decisiones se respetan.
     */
    private static function hostHasHardened(): bool
    {
        $current = session_get_cookie_params();

        return ($current['httponly'] ?? false) || ($current['samesite'] ?? '') !== '';
    }
}
