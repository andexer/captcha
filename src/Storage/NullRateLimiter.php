<?php

declare(strict_types=1);

namespace Captcha\Storage;

use Captcha\Contract\RateLimiterInterface;

/**
 * Limiter que jamás bloquea: el contrapunto en CLI de la política fail-closed.
 *
 * Los throttles existen para frenar a un llamador remoto *no fiable*, así
 * que solo se aplican a peticiones web. Un proceso CLI es código fiable del
 * propio operador (un comando de consola, un worker de cola, una corrida de
 * tests): no tiene cliente al que atribuir intentos, y la única
 * "identidad" disponible — el PID — no vale como cubo, porque un proceso
 * nuevo arranca con un contador fresco y por tanto nunca alcanza el límite.
 * Medir sobre él no añadiría nada a la postura de seguridad mientras
 * throttlearía un uso legítimo de CLI de larga vida (un worker que generó 20
 * captchas y 5 verificaciones dejaría de funcionar hasta reiniciarse).
 *
 * También mantiene la CLI lejos de $_SESSION por completo, en línea con la
 * regla de "jamás preemptar la sesión del host" que gobierna la selección
 * de almacenamiento.
 *
 * @internal
 */
final class NullRateLimiter implements RateLimiterInterface
{
    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        return true;
    }
}
