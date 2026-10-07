<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Storage;

use Captcha\Config\Config;
use Captcha\Runtime\Host;
use Captcha\Storage\CompositeRateLimiter;
use Captcha\Storage\IpRateLimiter;
use Captcha\Storage\NullRateLimiter;
use Captcha\Storage\RateLimiterFactory;
use Captcha\Storage\SessionRateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        Host::forceFrameworkSession(null);
        Host::forceWeb(null);
    }

    protected function tearDown(): void
    {
        Host::forceFrameworkSession(null);
        Host::forceWeb(null);
    }

    public function testOutsideTheWebSapiNothingIsMetered(): void
    {
        /*
        *  Un proceso local es código de confianza y no tiene cliente al que
        *  atribuir intentos; además una bolsa por PID se reiniciaría en cada
        *  proceso nuevo, así que no frenaría ni a un atacante real.
        */
        Host::forceWeb(false);

        self::assertInstanceOf(NullRateLimiter::class, RateLimiterFactory::forConfig(new Config()));
    }

    public function testCliDoesNotFallBackToTheSessionLimiter(): void
    {
        /*
        *  Sin rateLimitByIp la bolsa sería de sesión, y en CLI no hay nada
        *  que meter en ella: tocarla solo abriría warnings de session.
        */
        Host::forceWeb(false);

        $limiter = RateLimiterFactory::forConfig(new Config(rateLimitByIp: false));

        self::assertInstanceOf(NullRateLimiter::class, $limiter);
    }

    public function testWebWithoutIpLimitingUsesTheSessionLimiter(): void
    {
        Host::forceWeb(true);

        self::assertInstanceOf(SessionRateLimiter::class, RateLimiterFactory::forConfig(new Config(rateLimitByIp: false)));
    }

    public function testWebWithIpLimitingUsesTheDualStack(): void
    {
        Host::forceWeb(true);

        $limiter = RateLimiterFactory::forConfig(new Config());

        self::assertInstanceOf(CompositeRateLimiter::class, $limiter);
    }

    public function testWebWithAFrameworkOwnedSessionDropsTheSessionHalf(): void
    {
        /*
        *  "Nunca preemptar la sesión del host": con el framework gestionándola
        *  solo puede quedar la bolsa por IP.
        */
        Host::forceWeb(true);
        Host::forceFrameworkSession(true);

        $limiter = RateLimiterFactory::forConfig(new Config());

        self::assertInstanceOf(IpRateLimiter::class, $limiter);
    }

    public function testWebWithAFrameworkOwnedSessionAndNoIpLimitingHasNothingLeftToMeter(): void
    {
        /*
        *  La mitad de sesión se descarta siempre bajo un framework propietario
        *  y la bolsa por IP está deshabilitada: no queda nada que medir y el
        *  cubo no debe tocar una sesión (`SessionRateLimiter` preemptaría).
        */
        Host::forceWeb(true);
        Host::forceFrameworkSession(true);

        $limiter = RateLimiterFactory::forConfig(new Config(rateLimitByIp: false));

        self::assertInstanceOf(NullRateLimiter::class, $limiter);
    }
}
