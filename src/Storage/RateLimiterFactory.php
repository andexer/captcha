<?php

declare(strict_types=1);

namespace Captcha\Storage;

use Captcha\Config\Config;
use Captcha\Contract\RateLimiterInterface;
use Captcha\Runtime\Host;

/**
 * Rate limiter por defecto para una instancia, extraído de la fachada.
 *
 * La pila dual (ficheros IP + sesión) cuando Config::$rateLimitByIp está
 * activo, solo sesión en caso contrario. Bajo un framework que gestiona la
 * sesión PHP, el cubo de reintento descarta la mitad de sesión (solo
 * ficheros IP), respetando la misma regla de "jamás tocar la sesión del
 * host" que el backend de almacenamiento. Fuera del SAPI web no hay cliente
 * no fiable al que throttlear, de modo que el cubo es un NullRateLimiter.
 * Un limiter inyectado a mano se salta esta fábrica por completo.
 *
 * @internal
 */
final class RateLimiterFactory
{
    /**
     * Los directorios de auto-storage son rutas estables por host compartidas
     * por formulario y endpoint; los ficheros del limiter siguen la misma
     * convención.
     */
    private const LIMIT_DIR = '/captcha-limits';

    public static function forConfig(Config $config): RateLimiterInterface
    {
        if (!Host::isWeb()) {
            return new NullRateLimiter();
        }

        if (!$config->rateLimitByIp) {
            return new SessionRateLimiter();
        }

        return Host::frameworkSessionManaged() ? self::soloIp() : self::ipYSession();
    }

    private static function soloIp(): RateLimiterInterface
    {
        return new IpRateLimiter(sys_get_temp_dir() . self::LIMIT_DIR);
    }

    private static function ipYSession(): RateLimiterInterface
    {
        return new CompositeRateLimiter([self::soloIp(), new SessionRateLimiter()]);
    }
}
