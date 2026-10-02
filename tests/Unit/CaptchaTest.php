<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Config\Operation;
use Captcha\Contract\RateLimiterInterface;
use Captcha\Contract\RendererInterface;
use Captcha\Contract\StorageInterface;
use Captcha\Exception\RateLimitException;
use Captcha\Exception\StorageException;
use Captcha\Result\CaptchaResult;
use Captcha\Runtime\Host;
use Captcha\Storage\ArrayStorage;
use Captcha\Verification\Status;
use PHPUnit\Framework\TestCase;

final class CaptchaTest extends TestCase
{
    /**
     * El rate limiting está activo por defecto ahora, y cada instancia
     * construida aquí comparte el cubo del proceso (una corrida CLI no tiene
     * IP de cliente), así que un test que ejercite otra cosa — límites
     * aritméticos, el markup del widget, el honeypot — agotaría el
     * presupuesto del test que corrió antes. Los tests que verifican el
     * comportamiento del rate limit pasan su propio limiter y no se afectan.
     */
    private function rateLimiterOff(): RateLimiterInterface
    {
        return $this->limiterStub(true);
    }

    private function rendererStub(): RendererInterface
    {
        return new class implements RendererInterface {
            public function render(string $code, Config $config): string
            {
                return "\x89PNG\r\n\x1a\nfake";
            }

            public function mimeType(): string
            {
                return 'image/png';
            }
        };
    }

    /**
     * Limiter falso controlado por el test: grant=true siempre permite,
     * grant=false siempre bloquea (los contadores no importan aquí).
     */
    private function limiterStub(bool $grant): RateLimiterInterface
    {
        return new class ($grant) implements RateLimiterInterface {
            public function __construct(private readonly bool $grant) {}

            public function allow(string $key, int $limit, int $windowSeconds): bool
            {
                return $this->grant;
            }
        };
    }

    /**
     * Limiter que registra cada clave por la que se le pregunta y siempre
     * concede. Las claves capturadas permiten a los tests verificar en qué
     * cubo de operación cayó un intento (generate vs verify) sin tocar el
     * sistema de ficheros.
     *
     * @return array{limiter: RateLimiterInterface, keys: array<int, string>}
     */
    private function recordingLimiter(): array
    {
        $keys = [];

        $limiter = new class ($keys) implements RateLimiterInterface {
            /** @param array<int, string> $keys */
            public function __construct(private array &$keys) {}

            public function allow(string $key, int $limit, int $windowSeconds): bool
            {
                $this->keys[] = $key;

                return true;
            }
        };

        return ['limiter' => $limiter, 'keys' => &$keys];
    }

    public function testGenerateReturnsIdImageAndMimeType(): void
    {
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            renderer: $this->rendererStub(),
        );

        $result = $captcha->generate();

        self::assertInstanceOf(CaptchaResult::class, $result);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result->getId());
        self::assertSame('image/png', $result->getMimeType());
        self::assertStringStartsWith("\x89PNG", $result->getImage());
    }

    public function testGenerateStoresCodeInStorage(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $result = $captcha->generate();
        $stored = $storage->get($result->getId());

        self::assertNotNull($stored);
        self::assertMatchesRegularExpression('/^\d{6}$/', $stored);
    }

    public function testGenerateWithArithmeticWrapsDefaultGenerator(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            config: new Config(operations: [Operation::Add, Operation::Subtract], length: 5),
            rateLimiter: $this->rateLimiterOff(),
            renderer: $this->rendererStub(),
        );

        $result = $captcha->generate();
        $stored = $storage->get($result->getId());

        // El código persistido es el resultado numérico de la operación.
        self::assertNotNull($stored);
        self::assertMatchesRegularExpression('/^\d{1,5}$/', (string) $stored);
    }

    public function testVerifyAcceptsArithmeticAnswer(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            config: new Config(operations: [Operation::Add], length: 5),
            rateLimiter: $this->rateLimiterOff(),
            renderer: $this->rendererStub(),
        );

        $result = $captcha->generate();
        $stored = $storage->get($result->getId());

        self::assertSame(Status::Ok, $captcha->verify($result->getId(), (string) $stored)->getStatus());
    }

    public function testGenerateWithBetweenBoundsTheArithmeticAnswer(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            config: new Config(operations: [Operation::Add, Operation::Subtract], length: 5, between: [2, 20]),
            rateLimiter: $this->rateLimiterOff(),
            renderer: $this->rendererStub(),
        );

        for ($i = 0; $i < 15; $i++) {
            $result = $captcha->generate();
            $stored = (int) $storage->get($result->getId());

            self::assertGreaterThanOrEqual(2, $stored);
            self::assertLessThanOrEqual(20, $stored);
        }
    }

    public function testVerifyCorrectCodeReturnsOk(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $storage->put('id-1', '47391', 300);
        $result = $captcha->verify('id-1', '47391');

        self::assertTrue($result->isValid());
        self::assertSame(Status::Ok, $result->getStatus());
    }

    public function testVerifyTrimsInput(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $storage->put('id-1', '47391', 300);
        $result = $captcha->verify('id-1', "  47391\t\n");

        self::assertSame(Status::Ok, $result->getStatus());
    }

    public function testVerifyWrongCodeReturnsInvalidAndConsumesChallenge(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $storage->put('id-1', '47391', 300);
        $result = $captcha->verify('id-1', '00000');

        self::assertFalse($result->isValid());
        self::assertSame(Status::Invalid, $result->getStatus());
        self::assertFalse($storage->has('id-1'), 'Los intentos fallidos deben consumir el reto');
    }

    public function testVerifyUnknownIdReturnsMissing(): void
    {
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            renderer: $this->rendererStub(),
        );

        $result = $captcha->verify('does-not-exist', '12345');

        self::assertSame(Status::Missing, $result->getStatus());
    }

    public function testVerifyEmptyIdReturnsMissing(): void
    {
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            renderer: $this->rendererStub(),
        );

        $result = $captcha->verify('', '12345');

        self::assertSame(Status::Missing, $result->getStatus());
    }

    public function testVerifyEmptyIdIsNotARateLimitedAttempt(): void
    {
        $record = $this->recordingLimiter();
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            config: new Config(verifyAttempts: 3, rateLimitWindow: 300),
            renderer: $this->rendererStub(),
            rateLimiter: $record['limiter'],
        );

        $result = $captcha->verify('', '12345');

        self::assertSame(Status::Missing, $result->getStatus());
        self::assertSame([], $record['keys'], 'Un id vacío no es un intento de captcha: el limitador no debe consultarse');
    }

    public function testVerifyRejectsOversizedInputWithoutConsumingTheChallenge(): void
    {
        $storage = new ArrayStorage();
        $record = $this->recordingLimiter();
        $captcha = new Captcha(
            storage: $storage,
            config: new Config(verifyAttempts: 3, rateLimitWindow: 300),
            renderer: $this->rendererStub(),
            rateLimiter: $record['limiter'],
        );

        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';

        try {
            $storage->put('id-1', '47391', 300);
            $result = $captcha->verify('id-1', str_repeat('x', 33));
            $countAfterOversized = count($record['keys']);
            $reuse = $captcha->verify('id-1', '47391');
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }

        self::assertSame(Status::Invalid, $result->getStatus());
        self::assertSame(1, $countAfterOversized, 'El intento cuenta para verifyAttempts');
        self::assertSame(Status::Ok, $reuse->getStatus(), 'El reto sigue siendo utilizable');
    }

    public function testValidAndMessageOnGetDoNotBurnTheVerifyBudget(): void
    {
        $record = $this->recordingLimiter();
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            config: new Config(verifyAttempts: 2, rateLimitWindow: 300),
            renderer: $this->rendererStub(),
            rateLimiter: $record['limiter'],
        );

        self::assertFalse($captcha->valid());
        self::assertSame('Código captcha no encontrado.', $captcha->message());

        self::assertSame([], $record['keys'], 'Un render de página (GET) nunca debe gastar el cupo de verifyAttempts');
    }

    public function testVerifyExpiredChallengeReturnsExpired(): void
    {
        $now = 1_000_000;
        $storage = new ArrayStorage(clock: static function () use (&$now): int {
            return $now;
        });
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $storage->put('id-1', '47391', 60);
        $now += 61;

        $result = $captcha->verify('id-1', '47391');

        self::assertSame(Status::Expired, $result->getStatus());
    }

    public function testVerifyIsSingleUseEvenOnSuccess(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $storage->put('id-1', '47391', 300);

        self::assertSame(Status::Ok, $captcha->verify('id-1', '47391')->getStatus());
        self::assertSame(Status::Missing, $captcha->verify('id-1', '47391')->getStatus());
    }

    public function testVerifyNeverReturnsTheStoredCodeInMessage(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $storage->put('id-1', '47391', 300);
        $result = $captcha->verify('id-1', '00000');

        self::assertStringNotContainsString('47391', $result->getMessage());
    }

    public function testVerifyRequestValidatesPostedFields(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $result = $captcha->generate();
        $code = (string) $storage->get($result->getId());

        $verified = $captcha->verifyRequest([
            'captcha_id' => $result->getId(),
            'captcha' => "  {$code}  ",
        ]);

        self::assertTrue($verified->isValid());
    }

    public function testVerifyRequestUsesConfiguredFieldNames(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            config: new Config(idField: 'challenge_id', inputField: 'code'),
            rateLimiter: $this->rateLimiterOff(),
            renderer: $this->rendererStub(),
        );

        $result = $captcha->generate();
        $code = (string) $storage->get($result->getId());

        $verified = $captcha->verifyRequest([
            'challenge_id' => $result->getId(),
            'code' => $code,
        ]);

        self::assertTrue($verified->isValid());
    }

    public function testVerifyRequestReturnsMissingForEmptyData(): void
    {
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $result = $captcha->verifyRequest([]);

        self::assertSame(Status::Missing, $result->getStatus());
    }

    public function testVerifyRequestReturnsMissingForNonStringValues(): void
    {
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $result = $captcha->verifyRequest(['captcha_id' => 42, 'captcha' => ['x']]);

        self::assertSame(Status::Missing, $result->getStatus());
    }

    public function testSubmittedDetectsPostGlobals(): void
    {
        $previous = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $captcha = new Captcha(
                storage: new ArrayStorage(),
                renderer: $this->rendererStub(),
                rateLimiter: $this->rateLimiterOff(),
            );

            self::assertTrue($captcha->submitted());
        } finally {
            if ($previous === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previous;
            }
        }
    }

    public function testSubmittedFalseWithoutPost(): void
    {
        $previous = $_SERVER['REQUEST_METHOD'] ?? null;
        unset($_SERVER['REQUEST_METHOD']);
        try {
            $captcha = new Captcha(
                storage: new ArrayStorage(),
                renderer: $this->rendererStub(),
                rateLimiter: $this->rateLimiterOff(),
            );

            self::assertFalse($captcha->submitted());
        } finally {
            if ($previous !== null) {
                $_SERVER['REQUEST_METHOD'] = $previous;
            }
        }
    }

    public function testCheckIsIdleWithoutPost(): void
    {
        $this->withRequestMethod('', function (): void {
            $captcha = new Captcha(
                storage: new ArrayStorage(),
                renderer: $this->rendererStub(),
                rateLimiter: $this->rateLimiterOff(),
            );

            $check = $captcha->check();

            self::assertFalse($check->submitted);
            self::assertFalse($check->passed);
            self::assertNull($check->error);
        });
    }

    public function testCheckWhileIdleDoesNotConsumeTheChallenge(): void
    {
        $this->withRequestMethod('', function (): void {
            $storage = new ArrayStorage();
            $captcha = new Captcha(
                storage: $storage,
                renderer: $this->rendererStub(),
                rateLimiter: $this->rateLimiterOff(),
            );
            $challenge = $captcha->generate();
            $code = (string) $storage->get($challenge->getId());

            $captcha->check();

            self::assertTrue(
                $captcha->verify($challenge->getId(), $code)->isValid(),
                'Un check() en reposo no debe consumir el reto',
            );
        });
    }

    public function testCheckReportsPassedOnCorrectSubmission(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );
        $challenge = $captcha->generate();
        $code = (string) $storage->get($challenge->getId());

        $this->withRequestMethod('POST', function () use ($captcha, $challenge, $code): void {
            $this->withPost($captcha, ['captcha_id' => $challenge->getId(), 'captcha' => $code], function () use ($captcha): void {
                $check = $captcha->check();

                self::assertTrue($check->submitted);
                self::assertTrue($check->passed);
                self::assertNull($check->error);
            });
        });
    }

    public function testCheckReportsFailedWithEscapingLeftToTheView(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );
        $challenge = $captcha->generate();

        $this->withRequestMethod('POST', function () use ($captcha, $challenge): void {
            $this->withPost($captcha, ['captcha_id' => $challenge->getId(), 'captcha' => '00000'], function () use ($captcha): void {
                $check = $captcha->check();

                self::assertTrue($check->submitted);
                self::assertFalse($check->passed);
                self::assertSame('Código captcha incorrecto.', $check->error);
            });
        });
    }

    public function testCheckCachesTheResultWithinTheRequest(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );
        $challenge = $captcha->generate();
        $code = (string) $storage->get($challenge->getId());

        $this->withRequestMethod('POST', function () use ($captcha, $challenge, $code): void {
            $this->withPost($captcha, ['captcha_id' => $challenge->getId(), 'captcha' => $code], function () use ($captcha): void {
                $first = $captcha->check();
                $second = $captcha->check();

                self::assertSame($first, $second, 'check() debe cachear su resultado para la petición');
                self::assertTrue($captcha->valid(), 'valid() debe reutilizar el resultado que check() dejó en caché');
            });
        });
    }

    public function testValidAndMessageShareTheCachedResult(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );
        $challenge = $captcha->generate();
        $code = (string) $storage->get($challenge->getId());

        $this->withPost($captcha, [
            'captcha_id' => $challenge->getId(),
            'captcha' => "  {$code}  ",
        ], function () use ($captcha, $storage, $challenge, $code): void {
            self::assertTrue($captcha->valid());
            self::assertSame('Código captcha correcto.', $captcha->message());
            self::assertTrue($captcha->valid(), 'Un valid() repetido debe reutilizar la caché, no volver a verificar');
            self::assertFalse($storage->has($challenge->getId()), 'El reto debe consumirse exactamente una vez');
            self::assertSame(Status::Missing, $captcha->verify($challenge->getId(), $code)->getStatus());
        });
    }

    public function testValidAndMessageReportWrongCodeInSpanish(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );
        $challenge = $captcha->generate();

        $this->withPost($captcha, [
            'captcha_id' => $challenge->getId(),
            'captcha' => '00000',
        ], function () use ($captcha): void {
            self::assertFalse($captcha->valid());
            self::assertSame('Código captcha incorrecto.', $captcha->message());
        });
    }

    public function testWidgetIsIdempotentWithinTheRequest(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $first = $captcha->widget();
        $second = $captcha->widget();

        preg_match('/name="captcha_id" value="([a-f0-9]+)"/', $first, $a);
        preg_match('/name="captcha_id" value="([a-f0-9]+)"/', $second, $b);
        preg_match('/<img[^>]+src="([^"]+)"/', $first, $i);
        preg_match('/<img[^>]+src="([^"]+)"/', $second, $j);

        self::assertSame($a[1], $b[1], 'El segundo widget() debe reutilizar el mismo id de reto');
        self::assertSame($i[1], $j[1], 'El segundo widget() debe reutilizar la misma imagen');
        self::assertTrue($storage->has($a[1]), 'Debe almacenarse exactamente una entrada de reto');
    }

    public function testToStringRendersTheWidget(): void
    {
        $html = (string) (new Captcha(storage: new ArrayStorage(), renderer: $this->rendererStub()));

        self::assertStringContainsString('data-captcha', $html);
        self::assertStringContainsString('data-endpoint="/captcha/endpoint"', $html);
        self::assertStringContainsString('name="captcha_id"', $html);
    }

    public function testVerifyReturnsBlockedWhenRateLimited(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            config: new Config(verifyAttempts: 3, rateLimitWindow: 300),
            renderer: $this->rendererStub(),
            rateLimiter: $this->limiterStub(false),
        );

        $storage->put('id-1', '47391', 300);
        $result = $captcha->verify('id-1', '47391');

        self::assertFalse($result->isValid());
        self::assertSame(Status::Blocked, $result->getStatus());
        self::assertStringContainsString('Demasiados intentos', $result->getMessage());
        self::assertTrue($storage->has('id-1'), 'Un verify() bloqueado no debe consumir el reto');
    }

    public function testVerifySkippedWhenRateLimitingDisabled(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            config: new Config(verifyAttempts: 0),
            renderer: $this->rendererStub(),
            rateLimiter: $this->limiterStub(false),
        );

        $storage->put('id-1', '47391', 300);
        $result = $captcha->verify('id-1', '47391');

        self::assertSame(Status::Ok, $result->getStatus(), 'verifyAttempts=0 means no rate limiting at all');
    }

    public function testVerifyRequestReturnsBlockedWhenHoneypotTriggered(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            config: new Config(honeypot: true, honeypotField: 'website'),
            rateLimiter: $this->rateLimiterOff(),
            renderer: $this->rendererStub(),
        );

        $result = $captcha->verifyRequest(['website' => 'http://spam.example']);

        self::assertFalse($result->isValid());
        self::assertSame(Status::Blocked, $result->getStatus());
        self::assertStringContainsString('automatizada', $result->getMessage());
    }

    public function testVerifyRequestIgnoresHoneypotWhenDisabled(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(
            storage: $storage,
            renderer: $this->rendererStub(),
            rateLimiter: $this->rateLimiterOff(),
        );

        $result = $captcha->generate();

        // El campo está presente y relleno, pero honeypot=false: se ignora.
        $verified = $captcha->verifyRequest([
            'website' => 'http://spam.example',
            'captcha_id' => $result->getId(),
            'captcha' => (string) $storage->get($result->getId()),
        ]);

        self::assertSame(Status::Ok, $verified->getStatus());
    }

    public function testGenerateThrowsWhenRateLimited(): void
    {
        $this->expectException(RateLimitException::class);
        $this->expectExceptionMessage('Demasiados captchas generados');

        $captcha = new Captcha(
            storage: new ArrayStorage(),
            config: new Config(generateAttempts: 2, rateLimitWindow: 300),
            renderer: $this->rendererStub(),
            rateLimiter: $this->limiterStub(false),
        );

        $captcha->generate();
    }

    public function testGenerateSkippedWhenGenerationLimitingDisabled(): void
    {
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            config: new Config(generateAttempts: 0),
            renderer: $this->rendererStub(),
            rateLimiter: $this->limiterStub(false),
        );

        $result = $captcha->generate();

        self::assertInstanceOf(CaptchaResult::class, $result);
    }

    public function testGenerateAndVerifyUseSeparateRateLimitBuckets(): void
    {
        $storage = new ArrayStorage();
        $record = $this->recordingLimiter();
        $captcha = new Captcha(
            storage: $storage,
            config: new Config(verifyAttempts: 1, generateAttempts: 1, rateLimitWindow: 300),
            renderer: $this->rendererStub(),
            rateLimiter: $record['limiter'],
        );

        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';

        try {
            $result = $captcha->generate();
            $captcha->verify($result->getId(), '00000');
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }

        self::assertCount(2, $record['keys']);
        self::assertStringStartsWith('generate:', $record['keys'][0]);
        self::assertStringStartsWith('verify:', $record['keys'][1]);
        self::assertNotSame($record['keys'][0], $record['keys'][1], 'generate() y verify() no deben compartir cubo');
    }

    public function testWidgetDegradesGracefullyWhenGenerationIsRateLimited(): void
    {
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            config: new Config(generateAttempts: 2, rateLimitWindow: 300, injectAssets: false),
            renderer: $this->rendererStub(),
            rateLimiter: $this->limiterStub(false),
        );

        $html = $captcha->widget();

        self::assertStringContainsString('data-captcha', $html);
        self::assertStringContainsString('Demasiados captchas generados', $html);
        self::assertStringNotContainsString('<img', $html, 'Sin imagen de reto en el widget degradado');
        self::assertStringNotContainsString('<input', $html, 'Sin campo visible ni oculto en el widget degradado');
    }

    public function testWidgetDegradedStateIsIdempotentWithinTheRequest(): void
    {
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            config: new Config(generateAttempts: 2, rateLimitWindow: 300, injectAssets: false),
            renderer: $this->rendererStub(),
            rateLimiter: $this->limiterStub(false),
        );

        $first = $captcha->widget();
        $second = $captcha->widget();

        self::assertSame($first, $second, 'El widget degradado debe renderizarse una vez y reutilizarse');
    }

    public function testWidgetPropagatesErrorsThatAreNotRateLimits(): void
    {
        $storage = new class implements StorageInterface {
            public function put(string $id, string $code, int $ttl): void
            {
                throw new StorageException('No se pudo guardar el reto.');
            }

            public function get(string $id): ?string
            {
                return null;
            }

            public function consume(string $id): ?string
            {
                return null;
            }

            public function has(string $id): bool
            {
                return false;
            }

            public function forget(string $id): void {}
        };

        $this->expectException(StorageException::class);

        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';

        try {
            $captcha = new Captcha(
                storage: $storage,
                renderer: $this->rendererStub(),
                rateLimiter: $this->rateLimiterOff(),
            );
            $captcha->widget();
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }

    /**
     * Elimina los ficheros de contador que el limiter dual por defecto creó
     * para una IP (un cubo por operación: generate y verify), de modo que los
     * tests jamás dependan (ni contaminen) del directorio temporal
     * compartido.
     */
    private function clearIpLimit(string $ip): void
    {
        foreach (['generate:' . $ip, 'verify:' . $ip] as $key) {
            $path = sys_get_temp_dir() . '/captcha-limits/' . hash('sha256', $key) . '.limit.json';

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testVerifyFailsClosedWithoutClientIp(): void
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        unset($_SERVER['REMOTE_ADDR']);
        Host::forceWeb(true);

        try {
            $storage = new ArrayStorage();
            $captcha = new Captcha(
                storage: $storage,
                config: new Config(verifyAttempts: 3, rateLimitWindow: 300),
                renderer: $this->rendererStub(),
            );

            $storage->put('id-1', '47391', 300);
            $result = $captcha->verify('id-1', '47391');

            self::assertSame(Status::Blocked, $result->getStatus(), 'Una IP no utilizable debe fallar cerrada');
            self::assertTrue($storage->has('id-1'), 'El fallo cerrado sin IP no debe consumir el reto');
        } finally {
            Host::forceWeb(null);

            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }

    /**
     * Fuera del SAPI web no hay cliente no fiable al que throttlear: un
     * proceso local es código fiable, y un cubo indexado por PID se reiniciaría
     * en cada proceso nuevo (así que una inundación jamás alcanzaría el
     * límite) mientras throttlearía un uso legítimo de CLI de larga vida. El
     * limiter por defecto es por tanto un NullRateLimiter y el presupuesto
     * jamás se gasta. La política fail-closed pertenece al caso web (ver los
     * dos tests anteriores).
     */
    public function testCliIsNotMeteredAndIgnoresTheDefaultAttempts(): void
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        unset($_SERVER['REMOTE_ADDR']);
        Host::forceWeb(false);

        try {
            $storage = new ArrayStorage();
            $captcha = new Captcha(
                storage: $storage,
                config: new Config(rateLimitWindow: 300),
                renderer: $this->rendererStub(),
            );

            /*
            *  verifyAttempts vale 5 por defecto: aun así el proceso CLI no
            *  se bloquea a sí mismo, porque no hay presupuesto que gastar.
            */
            for ($i = 1; $i <= 8; $i++) {
                $storage->put('id-' . $i, '47391', 300);

                self::assertSame(
                    Status::Ok,
                    $captcha->verify('id-' . $i, '47391')->getStatus(),
                    sprintf('El verify %d no debe bloquearse en CLI.', $i),
                );
            }
        } finally {
            Host::forceWeb(null);

            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }

    public function testGenerateFailsClosedWithoutClientIp(): void
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        unset($_SERVER['REMOTE_ADDR']);
        Host::forceWeb(true);

        try {
            $captcha = new Captcha(
                storage: new ArrayStorage(),
                config: new Config(generateAttempts: 5, rateLimitWindow: 300),
                renderer: $this->rendererStub(),
            );

            $this->expectException(RateLimitException::class);
            $this->expectExceptionMessage('Demasiados captchas generados');

            $captcha->generate();
        } finally {
            Host::forceWeb(null);

            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }

    public function testDefaultDualLimiterCountsPerIpAcrossCookielessRequests(): void
    {
        $ip = '203.0.113.41';
        $this->clearIpLimit($ip);
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $ip;

        /*
        *  Los throttles solo aplican al SAPI web; phpunit corre bajo CLI, así
        *  que la rama se selecciona a mano.
        */
        Host::forceWeb(true);

        try {
            $captcha = new Captcha(
                storage: new ArrayStorage(),
                config: new Config(generateAttempts: 2, rateLimitWindow: 300),
                renderer: $this->rendererStub(),
            );

            // Sin limiter inyectado: el stack dual por defecto (IP + sesión).
            $captcha->generate();
            $captcha->generate();

            $this->expectException(RateLimitException::class);
            $this->expectExceptionMessage('Demasiados captchas generados');

            $captcha->generate();
        } finally {
            Host::forceWeb(null);

            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }

            $this->clearIpLimit($ip);
        }
    }

    public function testIpLimitingCanBeDisabledByConfig(): void
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

        try {
            $captcha = new Captcha(
                storage: new ArrayStorage(),
                config: new Config(generateAttempts: 1, rateLimitByIp: false),
                renderer: $this->rendererStub(),
                /*
                *  Con rateLimitByIp=false el limiter inyectado es la única
                *  pata: el stub siempre concede, así que no hay límite por IP.
                */
                rateLimiter: $this->limiterStub(true),
            );

            self::assertInstanceOf(CaptchaResult::class, $captcha->generate());
            self::assertInstanceOf(CaptchaResult::class, $captcha->generate(), 'rateLimitByIp=false no debe contar por IP');
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }

    public function testTrustedProxyRewritesTheLimitKey(): void
    {
        $proxied = '198.51.100.77';
        $this->clearIpLimit($proxied);
        $previousAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $previousXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '10.0.0.3';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $proxied;
        Host::forceWeb(true);

        try {
            $captcha = new Captcha(
                storage: new ArrayStorage(),
                config: new Config(
                    generateAttempts: 1,
                    rateLimitWindow: 300,
                    trustedProxies: ['10.0.0.3'],
                ),
                renderer: $this->rendererStub(),
            );

            $captcha->generate();

            $this->expectException(RateLimitException::class);

            $captcha->generate();
        } finally {
            Host::forceWeb(null);

            if ($previousAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousAddr;
            }
            if ($previousXff === null) {
                unset($_SERVER['HTTP_X_FORWARDED_FOR']);
            } else {
                $_SERVER['HTTP_X_FORWARDED_FOR'] = $previousXff;
            }

            $this->clearIpLimit($proxied);
        }
    }

    public function testUntrustedProxyHeaderIsIgnoredAsKey(): void
    {
        $ip = '203.0.113.43';
        $this->clearIpLimit($ip);
        $previousAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $previousXff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        Host::forceWeb(true);

        try {
            $captcha = new Captcha(
                storage: new ArrayStorage(),
                config: new Config(generateAttempts: 1, rateLimitWindow: 300),
                renderer: $this->rendererStub(),
            );

            $captcha->generate();

            /*
            *  Sin trustedProxies, el contador debe haber caído bajo REMOTE_ADDR
            *  (el spoof de XFF no abre una segunda bolsa).
            */
            $limitFile = sys_get_temp_dir() . '/captcha-limits/' . hash('sha256', 'generate:' . $ip) . '.limit.json';
            self::assertFileExists($limitFile, 'El contador debe indexarse por REMOTE_ADDR, no por la cabecera falsificada');
            self::assertFileDoesNotExist(
                sys_get_temp_dir() . '/captcha-limits/' . hash('sha256', 'generate:' . '1.2.3.4') . '.limit.json',
                'Un X-Forwarded-For falsificado no debe abrir su propio cubo',
            );
        } finally {
            Host::forceWeb(null);

            if ($previousAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousAddr;
            }
            if ($previousXff === null) {
                unset($_SERVER['HTTP_X_FORWARDED_FOR']);
            } else {
                $_SERVER['HTTP_X_FORWARDED_FOR'] = $previousXff;
            }

            $this->clearIpLimit($ip);
        }
    }

    private function withPost(Captcha $captcha, array $post, callable $fn): void
    {
        $previous = $_POST;
        $_POST = $post;
        try {
            $fn();
        } finally {
            $_POST = $previous;
        }
    }

    /** @param callable(): void $fn */
    private function withRequestMethod(string $method, callable $fn): void
    {
        $previous = $_SERVER['REQUEST_METHOD'] ?? null;

        if ($method === '') {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $method;
        }

        try {
            $fn();
        } finally {
            if ($previous === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previous;
            }
        }
    }

    public function testMagicMethodsAreSynchronizedWithInstances(): void
    {
        $reflection = new \ReflectionClass(Captcha::class);
        $magic = $reflection->getReflectionConstant('MAGIC_METHODS')?->getValue();
        self::assertIsArray($magic);
        $instances = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PRIVATE) as $method) {
            $name = $method->getName();
            if (str_ends_with($name, 'Instance')) {
                $instances[] = substr($name, 0, -8);
            }
        }
        sort($magic);
        sort($instances);
        self::assertSame(array_values($magic), $instances);
    }


    public function testEveryInstanceMethodHasADocumentedStaticFacadeTag(): void
    {
        $source = file_get_contents((string) (new \ReflectionClass(Captcha::class))->getFileName());
        preg_match_all('/@method static \S+ (\w+)\(/', $source, $matches);
        $documented = $matches[1];
        sort($documented);

        $reflection = new \ReflectionClass(Captcha::class);
        $instances = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PRIVATE) as $method) {
            $name = $method->getName();
            if (str_ends_with($name, 'Instance')) {
                $instances[] = substr($name, 0, -8);
            }
        }
        sort($instances);

        self::assertSame($instances, $documented, 'Cada método *Instance necesita su etiqueta @method static en el PHPDoc de Captcha.');
    }

}
