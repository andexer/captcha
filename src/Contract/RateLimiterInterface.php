<?php

declare(strict_types=1);

namespace Captcha\Contract;

/**
 * Limita los intentos repetidos (fuerza bruta e inundación del endpoint).
 *
 * La pila por defecto es doble: IpRateLimiter (respaldo en ficheros,
 * sobrevive a peticiones sin cookies) Y SessionRateLimiter (por sesión de
 * navegador), combinados por CompositeRateLimiter. Cualquier otra cosa
 * (Redis, APCu, base de datos...) puede respaldar la misma interfaz.
 *
 * Las implementaciones DEBEN ser fail-closed: un limitador que no puede
 * contar de forma fiable debe rechazar el intento en lugar de concederlo,
 * porque la duda delata el límite y el atacante solo tiene que insistir.
 *
 * Las implementaciones DEBEN ser baratas, atómicas bajo concurrencia y libres
 * de estado global por petición: allow() registra el intento y responde si la
 * petición puede pasar.
 */
interface RateLimiterInterface
{
    /**
     * Registra un intento para una clave e informa si aún está permitido.
     *
     * El contador se reinicia cuando transcurre la ventana. Una petición
     * bloqueada también se registra (así el atacante sigue consumiendo su
     * ventana), lo que evita el bucle ingenuo de "reintenta tras el reinicio".
     *
     * @param string $key Cubo del limitador, p. ej. la IP del cliente o un
     *                    identificador de sesión.
     * @param int $limit Máximo de intentos permitidos dentro de la ventana.
     * @param int $windowSeconds Amplitud de la ventana en segundos.
     */
    public function allow(string $key, int $limit, int $windowSeconds): bool;
}
