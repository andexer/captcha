<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Storage;

use Captcha\Contract\RateLimiterInterface;
use Captcha\Storage\CompositeRateLimiter;
use PHPUnit\Framework\TestCase;

final class CompositeRateLimiterTest extends TestCase
{
    /**
     * Limiter scriptable: registra las llamadas y responde lo que el test
     * le pide, para poder asertar orden y ausencia de cortocircuito.
     *
     * @param list<string> $calls
     */
    private function scripted(bool $answer, array &$calls): RateLimiterInterface
    {
        $limiter = new class implements RateLimiterInterface {
            public bool $answer = true;

            /** @var list<string> */
            public array $calls = [];

            public function allow(string $key, int $limit, int $windowSeconds): bool
            {
                $this->calls[] = $key;

                return $this->answer;
            }
        };

        $limiter->answer = $answer;
        $limiter->calls = &$calls;

        return $limiter;
    }

    public function testGrantsOnlyWhenEveryBackendGrants(): void
    {
        $calls = [];
        $composite = new CompositeRateLimiter([
            $this->scripted(true, $calls),
            $this->scripted(true, $calls),
        ]);

        self::assertTrue($composite->allow('203.0.113.9', 5, 300));
    }

    public function testRejectsWhenAnyBackendRejects(): void
    {
        $calls = [];
        $composite = new CompositeRateLimiter([
            $this->scripted(true, $calls),
            $this->scripted(false, $calls),
        ]);

        self::assertFalse($composite->allow('203.0.113.9', 5, 300));
    }

    public function testEveryBackendIsConsultedEvenAfterOneRejects(): void
    {
        $calls = [];
        $composite = new CompositeRateLimiter([
            $this->scripted(false, $calls),
            $this->scripted(true, $calls),
        ]);

        self::assertFalse($composite->allow('203.0.113.9', 5, 300));
        self::assertCount(2, $calls, 'Sin cortocircuito: ambos cupos deben gastarse en el mismo intento');
    }

    public function testEmptyCompositeGrants(): void
    {
        $composite = new CompositeRateLimiter([]);

        self::assertTrue($composite->allow('203.0.113.9', 5, 300));
    }
}
