<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Generator;

use Captcha\Config\Operation;
use Captcha\Generator\Operands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperandsTest extends TestCase
{
    public function testExposesTheTwoOperandsAndTheAnswer(): void
    {
        $operands = new Operands(left: 3, right: 4, answer: 7);

        self::assertSame(3, $operands->left);
        self::assertSame(4, $operands->right);
        self::assertSame(7, $operands->answer);
    }

    #[DataProvider('expresiones')]
    public function testBuildsTheExpressionWithTheAsciiSymbolOfTheOperation(
        Operands $operands,
        Operation $operacion,
        string $esperada,
    ): void {
        self::assertSame($esperada, $operands->expressionFor($operacion));
    }

    /** @return iterable<string, array{Operands, Operation, string}> */
    public static function expresiones(): iterable
    {
        $operands = new Operands(left: 12, right: 5, answer: 17);

        yield 'suma' => [$operands, Operation::Add, '12+5'];
        yield 'resta' => [$operands, Operation::Subtract, '12-5'];
        yield 'producto' => [$operands, Operation::Multiply, '12*5'];
        yield 'division' => [$operands, Operation::Divide, '12/5'];
    }

    public function testNegativeAndZeroOperandsAreRepresentable(): void
    {
        $operands = new Operands(left: 0, right: 0, answer: 0);

        self::assertSame('0+0', $operands->expressionFor(Operation::Add));
    }

    public function testRefusesToBeRewrittenAfterConstruction(): void
    {
        $operands = new Operands(left: 1, right: 2, answer: 3);

        $this->expectException(\Error::class);

        /** @phpstan-ignore-next-line Escribiendo a propósito para probar la inmutabilidad. */
        $operands->answer = 99;
    }
}
