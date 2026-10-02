<?php

declare(strict_types=1);

namespace Captcha\Storage;

use Captcha\Contract\RateLimiterInterface;

/**
 * Rate limiter "todos deben conceder": el intento pasa solo cuando cada
 * backend lo permite.
 *
 * La pila por defecto empareja esto con el limiter de sesión y el
 * IpRateLimiter basado en ficheros: los contadores de fichero frenan las
 * inundaciones sin cookie mientras los contadores de sesión siguen throttling
 * a un único navegador que borra cookies. Ninguno por separado ve el cuadro
 * completo; el compuesto aplica ambos presupuestos al mismo intento.
 *
 * Los backends siempre se consultan, incluso cuando uno ya rechazó: un
 * cortocircuito permitiría a un atacante quemar solo la pata que rechaza
 * mientras la otra queda fresca (a un borrado de cookies de tener un
 * presupuesto limpio en ambas).
 */
final class CompositeRateLimiter implements RateLimiterInterface
{
    /**
     * @param list<RateLimiterInterface> $limiters Todos se consultan en cada intento.
     */
    public function __construct(
        private readonly array $limiters,
    ) {}

    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $granted = true;

        foreach ($this->limiters as $limiter) {
            if (!$limiter->allow($key, $limit, $windowSeconds)) {
                $granted = false;
            }
        }

        return $granted;
    }
}
