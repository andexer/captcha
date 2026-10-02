<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Http;

use Captcha\Http\Globals;
use PHPUnit\Framework\TestCase;

final class GlobalsTest extends TestCase
{
    private ?string $previousMethod = null;
    private array $previousPost = [];
    private mixed $previousRemoteAddr = null;
    private mixed $previousHttps = null;
    private mixed $previousServerPort = null;

    protected function setUp(): void
    {
        $this->previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $this->previousPost = $_POST;
        $this->previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->previousHttps = $_SERVER['HTTPS'] ?? null;
        $this->previousServerPort = $_SERVER['SERVER_PORT'] ?? null;
    }

    protected function tearDown(): void
    {
        // Las superglobales son globales; restaurar evita contaminar otros tests.
        if ($this->previousMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->previousMethod;
        }
        $_POST = $this->previousPost;
        if ($this->previousRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->previousRemoteAddr;
        }
        if ($this->previousHttps === null) {
            unset($_SERVER['HTTPS']);
        } else {
            $_SERVER['HTTPS'] = $this->previousHttps;
        }
        if ($this->previousServerPort === null) {
            unset($_SERVER['SERVER_PORT']);
        } else {
            $_SERVER['SERVER_PORT'] = $this->previousServerPort;
        }
    }

    public function testIsPostIsFalseWithoutRequestMethod(): void
    {
        unset($_SERVER['REQUEST_METHOD']);

        self::assertFalse(Globals::isPost());
    }

    public function testIsPostIsFalseForGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertFalse(Globals::isPost());
    }

    public function testIsPostIsTrueForPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        self::assertTrue(Globals::isPost());
    }

    public function testIsHttpsIsFalseForAPlainHttpRequest(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);

        self::assertFalse(Globals::isHttps());
    }

    public function testIsHttpsIsTrueWhenHttpsIsOn(): void
    {
        $_SERVER['HTTPS'] = 'on';

        self::assertTrue(Globals::isHttps());
    }

    public function testIsHttpsIsFalseWhenHttpsIsOffOrEmpty(): void
    {
        unset($_SERVER['SERVER_PORT']);

        $_SERVER['HTTPS'] = 'off';
        self::assertFalse(Globals::isHttps());

        $_SERVER['HTTPS'] = '';
        self::assertFalse(Globals::isHttps());
    }

    public function testIsHttpsIsTrueOnPort443(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = '443';

        self::assertTrue(Globals::isHttps());
    }

    public function testPostReturnsTheGlobalPostFields(): void
    {
        $_POST = ['captcha_id' => 'abc', 'captcha' => '12345'];

        self::assertSame(['captcha_id' => 'abc', 'captcha' => '12345'], Globals::post());
    }

    public function testPostDefaultsToEmptyArray(): void
    {
        $_POST = [];

        self::assertSame([], Globals::post());
    }

    public function testPostNormalizesANonArraySuperglobal(): void
    {
        $_POST = null;

        self::assertSame([], Globals::post());
    }

    public function testIpReturnsRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        self::assertSame('203.0.113.9', Globals::ip());
    }

    public function testIpDefaultsToEmptyStringWithoutRemoteAddr(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        self::assertSame('', Globals::ip());
    }

    /**
     * Establece X-Forwarded-For y devuelve el valor previo para restaurarlo.
     */
    private function setForwardedFor(?string $value): ?string
    {
        $previous = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        if ($value === null) {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $value;
        }

        return $previous;
    }

    public function testClientIpIgnoresForwardedForWithoutTrustedProxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $previous = $this->setForwardedFor('1.2.3.4, 10.0.0.1');

        try {
            self::assertSame('203.0.113.9', Globals::clientIp(), 'X-Forwarded-For nunca debe confiarse por defecto');
        } finally {
            $this->setForwardedFor($previous);
        }
    }

    public function testClientIpUsesForwardedForOnlyBehindTrustedProxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $previous = $this->setForwardedFor('203.0.113.9');

        try {
            self::assertSame('203.0.113.9', Globals::clientIp(['10.0.0.1']));
            self::assertSame('10.0.0.1', Globals::clientIp(), 'Sin proxies declarados gana REMOTE_ADDR');
        } finally {
            $this->setForwardedFor($previous);
        }
    }

    public function testClientIpWalksTheForwardedChainRightToLeft(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $previous = $this->setForwardedFor('203.0.113.9, 10.0.0.2');

        try {
            self::assertSame('203.0.113.9', Globals::clientIp(['10.0.0.1', '10.0.0.2']));
        } finally {
            $this->setForwardedFor($previous);
        }
    }

    public function testClientIpFallsBackWhenTheForwardedChainIsUnusable(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        // Basura sin una sola IP válida: la cadena no aporta nada.
        $previous = $this->setForwardedFor('garbage, ,, ');
        try {
            self::assertSame('10.0.0.1', Globals::clientIp(['10.0.0.1']));
        } finally {
            $this->setForwardedFor($previous);
        }

        // Una cadena formada solo por proxies de confianza tampoco aporta.
        $previous = $this->setForwardedFor('10.0.0.1, 10.0.0.2');
        try {
            self::assertSame('10.0.0.1', Globals::clientIp(['10.0.0.1', '10.0.0.2']));
        } finally {
            $this->setForwardedFor($previous);
        }
    }

    public function testClientIpNormalizesIpv4MappedAddresses(): void
    {
        $_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.9';

        self::assertSame('203.0.113.9', Globals::clientIp(), 'Un IPv6 mapeado a IPv4 debe compartir el cubo IPv4');
    }

    public function testClientIpMatchesTrustedProxiesAcrossSpellings(): void
    {
        /*
        *  El proxy declarado y REMOTE_ADDR pueden usar grafías distintas de la
        *  misma IP (::ffff:10.0.0.1 vs 10.0.0.1): sin normalizar cuentan como
        *  hosts diferentes y el X-Forwarded-For quedaría ignorado en silencio.
        */
        $_SERVER['REMOTE_ADDR'] = '::ffff:10.0.0.1';
        $previous = $this->setForwardedFor('203.0.113.42, ::ffff:10.0.0.1');

        try {
            self::assertSame('203.0.113.42', Globals::clientIp(['10.0.0.1']));
        } finally {
            $this->setForwardedFor($previous);
        }
    }

    public function testClientIpNormalizesTrustedProxiesDeclaredAsMapped(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $previous = $this->setForwardedFor('198.51.100.7, 10.0.0.1');

        try {
            self::assertSame('198.51.100.7', Globals::clientIp(['::ffff:10.0.0.1']));
        } finally {
            $this->setForwardedFor($previous);
        }
    }

    public function testClientIpWithoutRemoteAddrIsEmpty(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        self::assertSame('', Globals::clientIp());
    }
}
