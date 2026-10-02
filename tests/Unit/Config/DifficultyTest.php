<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Config;

use Captcha\Config\Difficulty;
use Captcha\Config\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DifficultyTest extends TestCase
{
    #[DataProvider('rotationProvider')]
    public function testRotationRangeGrowsWithTheDifficulty(Difficulty $difficulty, int $esperado): void
    {
        self::assertSame($esperado, $difficulty->rotationRange());
    }

    /** @return iterable<string, array{Difficulty, int}> */
    public static function rotationProvider(): iterable
    {
        yield 'baja no rota' => [Difficulty::Low, 0];
        yield 'media rota hasta 8 grados' => [Difficulty::Medium, 8];
        yield 'alta rota hasta 12 grados' => [Difficulty::High, 12];
    }

    #[DataProvider('jitterProvider')]
    public function testJitterFactorAndFloorGrowWithTheDifficulty(
        Difficulty $difficulty,
        float $factor,
        int $piso,
    ): void {
        self::assertSame($factor, $difficulty->jitterFactor());
        self::assertSame($piso, $difficulty->jitterFloor());
    }

    /** @return iterable<string, array{Difficulty, float, int}> */
    public static function jitterProvider(): iterable
    {
        yield 'baja no desplaza' => [Difficulty::Low, 0.0, 0];
        yield 'media desplaza un 6 % con piso de 2' => [Difficulty::Medium, 0.06, 2];
        yield 'alta desplaza un 12 % con piso de 4' => [Difficulty::High, 0.12, 4];
    }

    public function testEveryDifficultyIsReachableFromItsOwnStringValue(): void
    {
        foreach (Difficulty::cases() as $caso) {
            self::assertSame($caso, Difficulty::from($caso->value));
        }
    }

    public function testEveryOperationExposesItsAsciiSymbol(): void
    {
        $simbolos = array_map(
            static fn(Operation $operacion): string => $operacion->symbol(),
            Operation::cases(),
        );

        self::assertSame(['+', '-', '*', '/'], $simbolos);
    }
}
