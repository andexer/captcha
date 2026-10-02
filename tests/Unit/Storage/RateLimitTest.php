<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Storage;

use Captcha\Storage\RateLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    public function testKeepsTheLimitAndTheWindowTogether(): void
    {
        $limit = new RateLimit(maxAttempts: 5, windowSeconds: 300);

        self::assertSame(5, $limit->maxAttempts);
        self::assertSame(300, $limit->windowSeconds);
    }

    public function testAZeroLimitDeniesEveryAttempt(): void
    {
        $limit = new RateLimit(maxAttempts: 0, windowSeconds: 60);

        self::assertTrue($limit->denies(attempts: 1));
    }

    public function testAllowsUpToTheLimitAndDeniesTheNextOne(): void
    {
        $limit = new RateLimit(maxAttempts: 3, windowSeconds: 60);

        self::assertFalse($limit->denies(attempts: 1));
        self::assertFalse($limit->denies(attempts: 3));
        self::assertTrue($limit->denies(attempts: 4));
    }

    #[DataProvider('invalidos')]
    public function testRejectsNonsensicalValues(int $maxAttempts, int $windowSeconds): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimit(maxAttempts: $maxAttempts, windowSeconds: $windowSeconds);
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidos(): iterable
    {
        yield 'intentos negativos' => [-1, 60];
        yield 'ventana cero' => [5, 0];
        yield 'ventana negativa' => [5, -10];
    }
}
