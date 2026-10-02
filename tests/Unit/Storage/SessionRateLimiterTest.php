<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Storage;

use Captcha\Storage\SessionRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testGrantsUpToTheLimit(): void
    {
        $limiter = new SessionRateLimiter('test_ns');

        self::assertTrue($limiter->allow('203.0.113.9', 3, 300));
        self::assertTrue($limiter->allow('203.0.113.9', 3, 300));
        self::assertTrue($limiter->allow('203.0.113.9', 3, 300));
        self::assertFalse($limiter->allow('203.0.113.9', 3, 300), 'El cuarto intento debe quedar bloqueado');
    }

    public function testKeysAreIsolated(): void
    {
        $limiter = new SessionRateLimiter('test_ns');

        self::assertFalse($limiter->allow('banned', 0, 300));

        self::assertTrue($limiter->allow('fresh', 1, 300), 'Una clave nueva no debe heredar el límite de otra');
    }

    #[DataProvider('contaminacionDeSesion')]
    public function testAClobberedCounterInTheSessionStartsOverInsteadOfCrashing(mixed $basura): void
    {
        /*
        *  Nada garantiza la forma de lo que el integrador haya dejado en la
        *  sesión: si no es un contador legible, la clave empieza de cero en
        *  vez de romper.
        */
        $limiter = new SessionRateLimiter('test_ns');
        $_SESSION['test_ns'] = ['203.0.113.9' => $basura];

        self::assertTrue($limiter->allow('203.0.113.9', 3, 300));
    }

    /** @return iterable<string, array{mixed}> */
    public static function contaminacionDeSesion(): iterable
    {
        yield 'texto' => ['no soy un contador'];
        yield 'entero' => [42];
        yield 'bool' => [true];
        yield 'array sin claves' => [[1, 2, 3]];
        yield 'array con tipos erroneos' => [['start' => '10', 'count' => '4']];
    }

    public function testTheWholeNamespaceBeingNonArrayDoesNotBreakTheLimiter(): void
    {
        // El namespace entero puede estar pisado por otra parte de la app.
        $limiter = new SessionRateLimiter('test_ns');
        $_SESSION['test_ns'] = 'invadido';

        self::assertTrue($limiter->allow('203.0.113.9', 3, 300));
        self::assertIsArray($_SESSION['test_ns']);
    }

    #[DataProvider('ventanasNoPositivas')]
    public function testRejectsANonPositiveWindowInsteadOfResettingEveryTime(int $windowSeconds): void
    {
        /*
        *  Una ventana de 0 o negativa se cumpliría en el acto, así que el
        *  contador se reiniciaría en cada intento y el límite no existiría.
        *  La puerta se cierra en vez de fingir que limita.
        */
        $limiter = new SessionRateLimiter('test_ns');

        self::assertFalse($limiter->allow('203.0.113.9', 5, $windowSeconds));
    }

    public function testRejectsNegativeLimits(): void
    {
        $limiter = new SessionRateLimiter('test_ns');

        self::assertFalse($limiter->allow('203.0.113.9', -1, 300));
    }

    /** @return iterable<string, array{int}> */
    public static function ventanasNoPositivas(): iterable
    {
        yield 'ventana cero' => [0];
        yield 'ventana negativa' => [-30];
    }

    public function testWindowResetsTheCounter(): void
    {
        $limiter = new SessionRateLimiter('test_ns');

        self::assertTrue($limiter->allow('203.0.113.9', 1, 300));
        self::assertFalse($limiter->allow('203.0.113.9', 1, 300));

        // La ventana expiró: se reabre el límite (time() real, sin mock).
        $_SESSION['test_ns']['203.0.113.9']['start'] = time() - 301;

        self::assertTrue($limiter->allow('203.0.113.9', 1, 300), 'La ventana caducada debe reiniciar el contador');
    }

    public function testRecoversFromCorruptedNamespace(): void
    {
        // Deliberadamente un namespace corrupto para el limiter.
        $_SESSION['test_ns'] = 'not-an-array';

        $limiter = new SessionRateLimiter('test_ns');

        self::assertTrue($limiter->allow('203.0.113.9', 1, 300));
    }

    public function testConstructorDoesNotStartSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            session_write_close();
        }

        new SessionRateLimiter('test_ns');

        self::assertSame(PHP_SESSION_NONE, session_status(), 'El constructor no arranca la sesión.');
    }

    public function testStartsSessionLazilyOnFirstUse(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            session_write_close();
        }
        $_SESSION = [];

        $limiter = new SessionRateLimiter('test_ns');

        self::assertTrue($limiter->allow('203.0.113.9', 3, 300));
        self::assertSame(PHP_SESSION_ACTIVE, session_status(), 'El primer allow() arranca la sesión.');
    }

    public function testFailsClosedWithoutAnActiveSession(): void
    {
        /*
        *  Se parte de una sesión cerrada: setUp() la abre, y cambiar el save
        *  path solo es posible con la sesión inactiva.
        */
        if (session_status() !== PHP_SESSION_NONE) {
            session_write_close();
        }
        $_SESSION = [];

        $savePath = ini_get('session.save_path');
        ini_set('session.save_path', '/nonexistent-dir-captcha-123');

        try {
            $limiter = new SessionRateLimiter('test_ns');

            /*
            *  Fail-closed deliberado: si la sesión no puede arrancarse (save
            *  path inutilizable, cabeceras ya enviadas...), no hay contador
            *  fiable y el intento se rechaza en lugar de conceder (antes era
            *  fail-open). El handler propio silencia el warning de PHP de la
            *  sesión que no puede abrir, sin tocar la cadena de PHPUnit.
            */
            set_error_handler(static fn(int $severity, string $message): bool => true);
            try {
                $blocked = $limiter->allow('203.0.113.9', 3, 300);
            } finally {
                restore_error_handler();
            }

            self::assertFalse($blocked, 'Sin sesión el contador no es fiable');
        } finally {
            ini_set('session.save_path', $savePath);
        }
    }
}
