<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Storage;

use Captcha\Storage\WindowCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WindowCounterTest extends TestCase
{
    public function testAFreshCounterHasNotCountedAnythingYet(): void
    {
        $counter = WindowCounter::fresh(1_000);

        self::assertSame(1_000, $counter->startedAt);
        self::assertSame(0, $counter->count);
    }

    public function testTheWindowIsStillOpenJustBeforeItElapses(): void
    {
        $counter = new WindowCounter(startedAt: 1_000, count: 3);

        self::assertFalse($counter->isWindowOver(now: 1_000 + 4, windowSeconds: 5));
    }

    public function testTheWindowClosesExactlyWhenItElapses(): void
    {
        $counter = new WindowCounter(startedAt: 1_000, count: 3);

        self::assertTrue($counter->isWindowOver(now: 1_000 + 5, windowSeconds: 5));
    }

    public function testIncrementingInsideTheWindowKeepsTheStartAndAddsOne(): void
    {
        $counter = (new WindowCounter(startedAt: 1_000, count: 0))->afterAttempt(now: 1_002, windowSeconds: 5);

        self::assertSame(1_000, $counter->startedAt);
        self::assertSame(1, $counter->count);
    }

    public function testAnAttemptAfterTheWindowResetsTheBudgetInsteadOfAccumulating(): void
    {
        $counter = (new WindowCounter(startedAt: 1_000, count: 99))->afterAttempt(now: 2_000, windowSeconds: 5);

        self::assertSame(2_000, $counter->startedAt);
        self::assertSame(1, $counter->count);
    }

    public function testIncrementingReturnsANewCounterAndLeavesTheOriginalAlone(): void
    {
        $original = new WindowCounter(startedAt: 1_000, count: 0);
        $sumado = $original->afterAttempt(now: 1_001, windowSeconds: 5);

        self::assertNotSame($original, $sumado);
        self::assertSame(0, $original->count);
    }

    public function testBlockedAttemptsKeepSpendingTheBudget(): void
    {
        /*
        *  Un atacante que falla a propósito no recupera presupuesto: el
        *  contador solo se reinicia al vencer la ventana.
        */
        $counter = new WindowCounter(startedAt: 1_000, count: 4);

        for ($i = 0; $i < 10; $i++) {
            $counter = $counter->afterAttempt(now: 1_001, windowSeconds: 5);
        }

        self::assertSame(14, $counter->count);
    }

    // ── LECTURA DEFENSIVA ────────────────────────────────────────────────────

    #[DataProvider('basura')]
    public function testGarbageInStorageDegradesToAFreshCounterInsteadOfCrashing(array $basura): void
    {
        $counter = WindowCounter::fromStored($basura, now: 1_500);

        self::assertSame(1_500, $counter->startedAt);
        self::assertSame(0, $counter->count);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function basura(): iterable
    {
        yield 'array vacio' => [[]];
        yield 'sin start' => [['count' => 4]];
        yield 'sin count' => [['start' => 10]];
        yield 'tipos erroneos' => [['start' => '10', 'count' => '4']];
        yield 'start flotante' => [['start' => 10.5, 'count' => 1]];
        yield 'arrayindexado' => [[1, 2, 3]];
    }

    public function testUnknownKeysInTheStoredShapeAreTolerated(): void
    {
        /*
        *  Tolerar claves de más es a propósito: el storage es compartido con
        *  versiones viejas del propio paquete y con el integrador, y perder un
        *  contador por una clave extra sería reiniciar el presupuesto de
        *  golpe, que es justo lo que el límite existe para evitar.
        */
        $counter = WindowCounter::fromStored(['start' => 10, 'count' => 4, 'v1' => 'extra'], now: 1_500);

        self::assertSame(10, $counter->startedAt);
        self::assertSame(4, $counter->count);
    }

    public function testAbsentCounterIsReadAsAFreshWindow(): void
    {
        // El null es el camino normal de la primera petición de una clave.
        $counter = WindowCounter::fromStored(null, now: 1_500);

        self::assertSame(1_500, $counter->startedAt);
        self::assertSame(0, $counter->count);
    }

    public function testAWellFormedStoredCounterIsReadBackAsIs(): void
    {
        $counter = WindowCounter::fromStored(['start' => 10, 'count' => 4], now: 1_500);

        self::assertSame(10, $counter->startedAt);
        self::assertSame(4, $counter->count);
    }

    public function testTheStoredShapeSurvivesTheRoundTrip(): void
    {
        $guardado = (new WindowCounter(startedAt: 10, count: 4))->toArray();

        self::assertSame(['start' => 10, 'count' => 4], $guardado);
        self::assertEquals((new WindowCounter(startedAt: 10, count: 4)), WindowCounter::fromStored($guardado, 99));
    }
}
