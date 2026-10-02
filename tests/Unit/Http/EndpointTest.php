<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Http;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Contract\RateLimiterInterface;
use Captcha\Contract\RendererInterface;
use Captcha\Http\Endpoint;
use Captcha\Storage\ArrayStorage;
use Captcha\Storage\IpRateLimiter;
use PHPUnit\Framework\TestCase;

final class EndpointTest extends TestCase
{
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

    private function endpoint(): Endpoint
    {
        return new Endpoint(
            new Captcha(
                storage: new ArrayStorage(),
                config: new Config(),
                renderer: $this->rendererStub(),
            ),
        );
    }

    public function testGenerateActionReturnsImageAndDimensions(): void
    {
        $response = $this->endpoint()->dispatch('generate');

        self::assertSame(200, $response['status']);

        $payload = $response['payload'];
        self::assertTrue($payload['ok']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $payload['id']);
        self::assertStringStartsWith('data:image/png;base64,', (string) $payload['image']);
        self::assertSame('image/png', (string) $payload['mime']);
        self::assertSame(Config::DEFAULT_WIDTH, (int) $payload['width']);
        self::assertSame(Config::DEFAULT_HEIGHT, (int) $payload['height']);
    }

    public function testGenerateActionPersistsTheChallenge(): void
    {
        $storage = new ArrayStorage();
        $endpoint = new Endpoint(
            new Captcha(storage: $storage, renderer: $this->rendererStub()),
        );

        $payload = $endpoint->dispatch('generate')['payload'];
        $stored = $storage->get((string) $payload['id']);

        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $stored);
    }

    public function testUnknownActionReturns400Error(): void
    {
        $response = $this->endpoint()->dispatch('verify');

        self::assertSame(400, $response['status']);
        self::assertFalse($response['payload']['ok']);
        self::assertStringContainsString('Acción desconocida', (string) $response['payload']['error']);
    }

    public function testGenerateActionReturns429WhenRateLimited(): void
    {
        $endpoint = new Endpoint(
            new Captcha(
                storage: new ArrayStorage(),
                config: new Config(generateAttempts: 2, rateLimitWindow: 300),
                renderer: $this->rendererStub(),
                rateLimiter: new class implements RateLimiterInterface {
                    public function allow(string $key, int $limit, int $windowSeconds): bool
                    {
                        return false;
                    }
                },
            ),
        );

        $response = $endpoint->dispatch('generate');

        self::assertSame(429, $response['status']);
        self::assertFalse($response['payload']['ok']);
        self::assertStringContainsString('Demasiados captchas generados', (string) $response['payload']['error']);
    }

    /**
     * El mismo contrato 429 pero a través del contador REAL respaldado por
     * ficheros: la tercera generación debe bloquearse porque el recuento
     * persistido por clave (2) excede generateAttempts (2). Vela por la
     * semántica de allow() y el mapeo 429 del Endpoint juntos, no solo un
     * limitador de mentira. (rateLimitByIp está desactivado para que la CLI
     * pueda ejercitar el contador: sin REMOTE_ADDR la clave por IP por
     * defecto es "ilimitable" y falla cerrada antes de que el limitador
     * llegue a ejecutarse.)
     */
    public function testGenerateActionRateLimitsAgainstTheRealFileCounter(): void
    {
        $directory = sys_get_temp_dir() . '/captcha-limits-endpoint-' . bin2hex(random_bytes(4));
        $endpoint = new Endpoint(
            new Captcha(
                storage: new ArrayStorage(),
                config: new Config(generateAttempts: 2, rateLimitWindow: 300, rateLimitByIp: false),
                renderer: $this->rendererStub(),
                rateLimiter: new IpRateLimiter($directory),
            ),
        );

        try {
            self::assertSame(200, $endpoint->dispatch('generate')['status']);
            self::assertSame(200, $endpoint->dispatch('generate')['status']);
            self::assertSame(429, $endpoint->dispatch('generate')['status'], 'La tercera generación debe disparar el contador real');
        } finally {
            foreach (glob($directory . '/*.limit.json') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }
}
