<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Storage;

use Captcha\Exception\StorageException;
use Captcha\Storage\IpRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpRateLimiterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/captcha-limits-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        /*
        *  Cada test usa su propio directorio temporal; limpiarlo evita
        *  acumular basura entre ejecuciones.
        */
        foreach (glob($this->directory . '/*.limit.json') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testGrantsUpToTheLimit(): void
    {
        $limiter = new IpRateLimiter($this->directory);

        self::assertTrue($limiter->allow('203.0.113.9', 3, 300));
        self::assertTrue($limiter->allow('203.0.113.9', 3, 300));
        self::assertTrue($limiter->allow('203.0.113.9', 3, 300));
        self::assertFalse($limiter->allow('203.0.113.9', 3, 300), 'El cuarto intento debe quedar bloqueado');
    }

    public function testKeysAreIsolated(): void
    {
        $limiter = new IpRateLimiter($this->directory);

        self::assertFalse($limiter->allow('banned', 0, 300));

        self::assertTrue($limiter->allow('fresh', 1, 300), 'Una clave nueva no debe heredar el límite de otra');
    }

    public function testWindowResetsTheCounter(): void
    {
        $limiter = new IpRateLimiter($this->directory);

        self::assertTrue($limiter->allow('203.0.113.9', 1, 300));
        self::assertFalse($limiter->allow('203.0.113.9', 1, 300));

        /*
        *  La ventana expiró: mover "start" hacia atrás reabre el límite
        *  (time() real, sin mock ni sleep()).
        */
        $path = $this->directory . '/' . hash('sha256', '203.0.113.9') . '.limit.json';
        $entry = json_decode((string) file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);
        $entry['start'] = time() - 301;
        file_put_contents($path, json_encode($entry, JSON_THROW_ON_ERROR));

        self::assertTrue($limiter->allow('203.0.113.9', 1, 300), 'La ventana caducada debe reiniciar el contador');
    }

    public function testBlockedAttemptsKeepCounting(): void
    {
        $limiter = new IpRateLimiter($this->directory);

        self::assertTrue($limiter->allow('203.0.113.9', 1, 300));
        self::assertFalse($limiter->allow('203.0.113.9', 1, 300));

        /*
        *  El contador sigue creciendo aunque el intento se rechace: el
        *  atacante no recupera su ventana fallando de propósito.
        */
        $path = $this->directory . '/' . hash('sha256', '203.0.113.9') . '.limit.json';
        $entry = json_decode((string) file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);

        self::assertSame(2, $entry['count'], 'Los intentos rechazados deben contar igualmente');
    }

    public function testSurvivesRequestsThatCarryNoCookie(): void
    {
        /*
        *  El punto entero del limiter por ficheros: el contador vive fuera
        *  de la sesión, así que "arrancar de cero" (como un request sin
        *  cookie) no reabre el límite.
        */
        $limiter = new IpRateLimiter($this->directory);

        self::assertTrue($limiter->allow('203.0.113.9', 2, 300));
        self::assertTrue($limiter->allow('203.0.113.9', 2, 300));
        self::assertFalse($limiter->allow('203.0.113.9', 2, 300), 'Un proceso nuevo sin cookie debe seguir limitado');
    }

    public function testFailsClosedOnCorruptedCounterFile(): void
    {
        $limiter = new IpRateLimiter($this->directory);
        $path = $this->directory . '/' . hash('sha256', '203.0.113.9') . '.limit.json';
        file_put_contents($path, 'not-json{{');

        self::assertFalse($limiter->allow('203.0.113.9', 5, 300), 'Los contadores corruptos deben fallar cerrados');

        /*
        *  Sin "autocuración": el fichero corrupto no se sobrescribe con un
        *  contador fresco (eso sería fail-open con pasos extra).
        */
        self::assertSame('not-json{{', file_get_contents($path));
    }

    public function testFailsClosedWhenTheCounterPathIsADirectory(): void
    {
        $limiter = new IpRateLimiter($this->directory);
        $path = $this->directory . '/' . hash('sha256', '203.0.113.9') . '.limit.json';
        mkdir($path);

        self::assertFalse($limiter->allow('203.0.113.9', 5, 300), 'Un contador no escribible debe fallar cerrado');

        rmdir($path);
    }

    public function testRejectsNegativeLimits(): void
    {
        $limiter = new IpRateLimiter($this->directory);

        self::assertFalse($limiter->allow('203.0.113.9', -1, 300));
    }

    #[DataProvider('ventanasNoPositivas')]
    public function testRejectsANonPositiveWindowInsteadOfResettingEveryTime(int $windowSeconds): void
    {
        /*
        *  Una ventana de 0 o negativa se cumpliría en el acto, así que el
        *  contador se reiniciaría en cada intento y el límite no existiría.
        *  La puerta se cierra en vez de fingir que limita.
        */
        $limiter = new IpRateLimiter($this->directory);

        self::assertFalse($limiter->allow('203.0.113.9', 5, $windowSeconds));
    }

    /** @return iterable<string, array{int}> */
    public static function ventanasNoPositivas(): iterable
    {
        yield 'ventana cero' => [0];
        yield 'ventana negativa' => [-30];
    }

    public function testConstructorRejectsAFilePathAsDirectory(): void
    {
        $file = $this->directory . '-file';
        file_put_contents($file, 'x');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('existe pero no es un directorio');

        try {
            new IpRateLimiter($file);
        } finally {
            unlink($file);
        }
    }

    public function testConstructorRejectsAnUnwritableDirectory(): void
    {
        mkdir($this->directory, 0o500);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('no es escribible');

        try {
            new IpRateLimiter($this->directory);
        } finally {
            chmod($this->directory, 0o700);
        }
    }
}
