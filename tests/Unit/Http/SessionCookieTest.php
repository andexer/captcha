<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Http;

use Captcha\Http\SessionCookie;
use Captcha\Runtime\Host;
use PHPUnit\Framework\TestCase;

final class SessionCookieTest extends TestCase
{
    private array $serverBackup = [];
    private array $iniBackup = [];

    protected function setUp(): void
    {
        Host::forceFrameworkSession(false);

        /*
        *  Tests previos pueden dejar una sesión abierta; las opciones de
        *  sesión no se pueden cambiar mientras haya una activa.
        */
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->serverBackup = $_SERVER;
        $this->iniBackup = [
            'session.cookie_httponly' => (string) ini_get('session.cookie_httponly'),
            'session.cookie_samesite' => (string) ini_get('session.cookie_samesite'),
        ];

        ini_set('session.cookie_httponly', '0');
        ini_set('session.cookie_samesite', '');
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
    }

    protected function tearDown(): void
    {
        Host::forceFrameworkSession(null);
        $_SERVER = $this->serverBackup;
        ini_set('session.cookie_httponly', $this->iniBackup['session.cookie_httponly']);
        ini_set('session.cookie_samesite', $this->iniBackup['session.cookie_samesite']);
    }

    public function testHardenIsEmptyUnderAFrameworkManagedSession(): void
    {
        Host::forceFrameworkSession(true);

        self::assertSame([], SessionCookie::harden());
    }

    public function testHardenIsEmptyWhenTheHostAlreadyHardenedTheCookie(): void
    {
        ini_set('session.cookie_httponly', '1');

        self::assertSame([], SessionCookie::harden());
    }

    public function testHardenAsksForHttpOnlyAndSameSiteLaxOnPlainHttp(): void
    {
        /*
        *  El Secure se omite en HTTP plano: una cookie Secure no llegaría a
        *  enviarse y rompería la sesión del visitante.
        */
        self::assertSame(['httponly' => true, 'samesite' => 'Lax'], SessionCookie::harden());
    }

    public function testHardenAddsSecureWhenTheClientSpeaksHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';

        self::assertSame(['httponly' => true, 'samesite' => 'Lax', 'secure' => true], SessionCookie::harden());
    }
}
