<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Generator;

use Captcha\Config\Difficulty;
use Captcha\Config\Operation;
use Captcha\Exception\InvalidConfigException;
use Captcha\Generator\NumericGenerator;
use PHPUnit\Framework\TestCase;

final class NumericGeneratorTest extends TestCase
{
    public function testGeneratesCorrectLength(): void
    {
        $code = (new NumericGenerator())->generate(5);

        self::assertSame(5, strlen($code));
        self::assertMatchesRegularExpression('/^\d{5}$/', $code);
    }

    public function testGeneratesDigitsOnlyAtBounds(): void
    {
        $generator = new NumericGenerator();

        self::assertMatchesRegularExpression('/^\d{3}$/', $generator->generate(3));
        self::assertMatchesRegularExpression('/^\d{10}$/', $generator->generate(10));
    }

    public function testRejectsTooShortLength(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('La longitud debe estar entre 3 y 10');

        (new NumericGenerator())->generate(2);
    }

    public function testRejectsTooLongLength(): void
    {
        $this->expectException(InvalidConfigException::class);

        (new NumericGenerator())->generate(11);
    }

    public function testCodesAreNotConstant(): void
    {
        $generator = new NumericGenerator();
        $codes = [];

        for ($i = 0; $i < 20; $i++) {
            $codes[] = $generator->generate(8);
        }

        self::assertGreaterThan(1, count(array_unique($codes)));
    }

    public function testNoOperationsExposesEmptyExpression(): void
    {
        self::assertSame('', (new NumericGenerator())->expression());
    }

    public function testAddBuildsValidExpressionAndAnswer(): void
    {
        $generator = new NumericGenerator([Operation::Add]);
        $code = $generator->generate(5);

        self::assertMatchesRegularExpression('/^\d+\+\d+$/', $generator->expression());
        self::assertSame(self::evalOperands($generator->expression(), Operation::Add), $code);
        self::assertMatchesRegularExpression('/^\d{1,5}$/', $code);
        self::assertNotSame('', $generator->expression());
    }

    public function testSubtractIsNeverNegative(): void
    {
        $generator = new NumericGenerator([Operation::Subtract]);

        for ($i = 0; $i < 25; $i++) {
            $code = $generator->generate(5);

            self::assertSame(self::evalOperands($generator->expression(), Operation::Subtract), $code);
            self::assertGreaterThanOrEqual(0, (int) $code);
            self::assertMatchesRegularExpression('/^\d+-\d+$/', $generator->expression());
        }
    }

    public function testMultiplyBuildsValidExpressionAndAnswer(): void
    {
        $generator = new NumericGenerator([Operation::Multiply], Difficulty::Low);
        $code = $generator->generate(5);

        self::assertMatchesRegularExpression('/^\d+\*\d+$/', $generator->expression());
        self::assertSame(self::evalOperands($generator->expression(), Operation::Multiply), $code);
        self::assertLessThanOrEqual(5, strlen($code));
    }

    public function testDivideIsAlwaysExact(): void
    {
        $generator = new NumericGenerator([Operation::Divide]);

        for ($i = 0; $i < 25; $i++) {
            $code = $generator->generate(5);
            $expression = $generator->expression();

            self::assertMatchesRegularExpression('/^\d+\/\d+$/', $expression);
            self::assertSame(self::evalOperands($expression, Operation::Divide), $code);

            $divisor = (int) explode('/', $expression)[1];
            self::assertGreaterThanOrEqual(2, $divisor);
        }
    }

    public function testEnabledSetPicksOnlyEnabledOperations(): void
    {
        $generator = new NumericGenerator([Operation::Add, Operation::Subtract]);

        for ($i = 0; $i < 40; $i++) {
            $generator->generate(5);
            self::assertMatchesRegularExpression('/^\d+[+-]\d+$/', $generator->expression());
        }
    }

    public function testResultNeverExceedsConfiguredLengthAcrossDifficulties(): void
    {
        $operations = [Operation::Add, Operation::Subtract, Operation::Multiply, Operation::Divide];

        foreach ([Difficulty::Low, Difficulty::Medium, Difficulty::High] as $difficulty) {
            foreach ($operations as $operation) {
                foreach ([3, 5, 10] as $length) {
                    $generator = new NumericGenerator([$operation], $difficulty);

                    for ($i = 0; $i < 8; $i++) {
                        $code = $generator->generate($length);

                        self::assertLessThanOrEqual($length, strlen($code));
                        self::assertMatchesRegularExpression('/^\d+$/', $code);
                    }
                }
            }
        }
    }

    /**
     * Low mantiene los operandos pequeños y legibles, pero la banda ahora
     * sigue la $length pedida en lugar de un dígito fijo.
     */
    public function testLowDifficultyKeepsOperandsReadable(): void
    {
        $generator = new NumericGenerator([Operation::Add], Difficulty::Low);

        for ($i = 0; $i < 15; $i++) {
            $generator->generate(5);
            [$a, $b] = sscanf($generator->expression(), '%d+%d');

            self::assertGreaterThanOrEqual(1, $a);
            self::assertGreaterThanOrEqual(1, $b);
            /*
            *  sqrt(99999) ≈ 316: en Low los operandos se quedan en cientos
            *  con longitud 5.
            */
            self::assertLessThanOrEqual(316, max($a, $b));
        }
    }

    /**
     * High alcanza mucho más allá del viejo techo de 999, acotado solo por
     * $length.
     */
    public function testHighDifficultyOperandsFollowLength(): void
    {
        $generator = new NumericGenerator([Operation::Add], Difficulty::High);

        for ($i = 0; $i < 15; $i++) {
            $generator->generate(6);
            [$a, $b] = sscanf($generator->expression(), '%d+%d');

            self::assertLessThanOrEqual(499999, max($a, $b));
        }

        // Un código de 3 dígitos conserva el techo histórico de 999.
        $short = new NumericGenerator([Operation::Add], Difficulty::High);

        for ($i = 0; $i < 15; $i++) {
            $short->generate(3);
            [$a, $b] = sscanf($short->expression(), '%d+%d');

            self::assertLessThanOrEqual(999, max($a, $b));
        }
    }

    public function testMultiplyResultIsBoundedByLength(): void
    {
        $generator = new NumericGenerator([Operation::Multiply], Difficulty::High);

        for ($i = 0; $i < 15; $i++) {
            $code = $generator->generate(3);

            self::assertLessThanOrEqual(999, (int) $code);
            self::assertMatchesRegularExpression('/^[1-9]\d{0,2}$/', $code);
        }
    }

    public function testBetweenBoundsAdditionResults(): void
    {
        $generator = new NumericGenerator([Operation::Add], Difficulty::Low, [2, 20]);

        for ($i = 0; $i < 30; $i++) {
            $code = $generator->generate(5);
            [$a, $b] = sscanf($generator->expression(), '%d+%d');

            self::assertSame((string) ($a + $b), $code);
            self::assertGreaterThanOrEqual(2, (int) $code);
            self::assertLessThanOrEqual(20, (int) $code);
        }
    }

    public function testBetweenBoundsSubtractionResults(): void
    {
        $generator = new NumericGenerator([Operation::Subtract], Difficulty::Low, [2, 20]);

        for ($i = 0; $i < 30; $i++) {
            $code = $generator->generate(5);
            [$a, $b] = sscanf($generator->expression(), '%d-%d');

            self::assertSame((string) ($a - $b), $code);
            self::assertGreaterThanOrEqual(2, (int) $code);
            self::assertLessThanOrEqual(20, (int) $code);
        }
    }

    public function testBetweenOverridesTheLengthResultCeiling(): void
    {
        $generator = new NumericGenerator([Operation::Add], Difficulty::Low, [5, 9]);

        for ($i = 0; $i < 20; $i++) {
            $code = (int) $generator->generate(3);

            self::assertGreaterThanOrEqual(5, $code);
            self::assertLessThanOrEqual(9, $code);
        }
    }

    public function testBetweenWithMinRaisesTheNaturalFloor(): void
    {
        $generator = new NumericGenerator([Operation::Subtract], Difficulty::Low, [3, 5]);

        for ($i = 0; $i < 20; $i++) {
            $code = (int) $generator->generate(5);

            self::assertGreaterThanOrEqual(3, $code);
            self::assertLessThanOrEqual(5, $code);
            self::assertSame(self::evalOperands($generator->expression(), Operation::Subtract), (string) $code);
        }
    }

    /**
     * Un rango "between" más estrecho que un paso de operando no puede
     * satisfacerse, así que el generador se rinde de forma ruidosa en lugar
     * de devolver una respuesta errónea.
     */
    public function testBetweenTooTightForTheOperandsThrows(): void
    {
        $generator = new NumericGenerator([Operation::Add], Difficulty::High, [999998, 999999]);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('No se pudo generar una operación aritmética');

        $generator->generate(6);
    }

    public function testBetweenOutsideLengthRangeThrows(): void
    {
        $generator = new NumericGenerator([Operation::Add], Difficulty::Low, [1000, 1001]);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('no cabe en un código de');

        $generator->generate(3);
    }

    public function testRejectsMalformedBetweenShape(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'between' debe ser un array de dos enteros");

        new NumericGenerator([Operation::Add], Difficulty::Low, [1, 2, 3]);
    }

    public function testRejectsInvertedBetweenBounds(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'between' requiere 0 <= min <= max");

        new NumericGenerator([Operation::Add], Difficulty::Low, [2, 1]);
    }

    public function testRejectsNegativeBetween(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'between' requiere 0 <= min <= max");

        new NumericGenerator([Operation::Add], Difficulty::Low, [-5, 20]);
    }

    /**
     * Con "between", los operandos de la resta quedan confinados al entorno
     * del rango: [0, 20] muestra operandos de hasta 25, no 3962-3962, que era
     * el bug (resultado válido de sorteos dimensionados solo por $length).
     */
    public function testBetweenConfinesSubtractionOperandsNearTheRange(): void
    {
        $generator = new NumericGenerator([Operation::Subtract], Difficulty::Medium, [0, 20]);

        for ($i = 0; $i < 60; $i++) {
            $code = $generator->generate(5);
            [$a, $b] = sscanf($generator->expression(), '%d-%d');

            self::assertSame((string) ($a - $b), $code);
            self::assertLessThanOrEqual(25, max((int) $a, (int) $b));
            self::assertLessThanOrEqual(20, (int) $code);
        }
    }

    /**
     * La misma contención para la suma: con [0, 20] ningún sumando puede
     * pasarse del techo del rango. Es redundante si la resta queda confinada
     * (comparten techo), pero el comportamiento se promete aparte: ambos
     * operandos se leen en la misma banda que el resultado a teclear.
     */
    public function testBetweenConfinesAdditionOperandsNearTheRange(): void
    {
        $generator = new NumericGenerator([Operation::Add], Difficulty::Medium, [0, 20]);

        for ($i = 0; $i < 60; $i++) {
            $code = $generator->generate(5);
            [$a, $b] = sscanf($generator->expression(), '%d+%d');

            self::assertSame((string) ($a + $b), $code);
            self::assertLessThanOrEqual(25, max((int) $a, (int) $b));
        }
    }

    /**
     * Sin "between" el techo de operandos no se repliega: la resta sigue
     * dimensionándose por $length y dificultad (medium con 6 dígitos = 99999).
     */
    public function testWithoutBetweenTheOperandCeilingFollowsLength(): void
    {
        $generator = new NumericGenerator([Operation::Subtract], Difficulty::Medium);

        for ($i = 0; $i < 30; $i++) {
            $generator->generate(6);
            [$a] = sscanf($generator->expression(), '%d-%d');

            self::assertLessThanOrEqual(99999, (int) $a);
        }

        $grande = false;
        for ($i = 0; $i < 400; $i++) {
            $generator->generate(6);
            [$a] = sscanf($generator->expression(), '%d-%d');
            $grande = $grande || (int) $a > 9999;
        }

        self::assertTrue($grande, 'Sin between el minuendo debe poder superar el techo "de banda estrecha" por diseño.');
    }

    /**
     * El espacio de respuestas aritméticas debe seguir la $length pedida, no
     * solo el nivel de dificultad.
     *
     * Los rangos de operandos se acotaban solo por dificultad (Low 9 /
     * Medium 99 / High 999), de modo que un código de 6 dígitos respondía
     * con valores en 2..198: todo el espacio eran ~197 candidatos, es decir
     * ~8 bits, y un barrido a ciegas lo rompía en un par de decenas de
     * renders. $length ahora dimensiona los operandos, de modo que el rango
     * de respuestas crece con la longitud de código que pidió quien llama y
     * la promesa de $length dígitos de entropía es real.
     */
    public function testArithmeticAnswerSpaceGrowsWithLength(): void
    {
        $spaceFor = static function (int $length): int {
            $answers = [];

            for ($i = 0; $i < 4000; $i++) {
                $answers[(new NumericGenerator([Operation::Add]))->generate($length)] = true;
            }

            return count($answers);
        };

        /*
        *  length=3 ya era ~197 bajo el techo antiguo de solo dificultad, así
        *  que debe seguir compatible; los códigos más largos deben abrirse muy
        *  por encima.
        */
        self::assertGreaterThan($spaceFor(3), $spaceFor(4), 'length debe ampliar el espacio de respuestas');
        self::assertGreaterThan(2000, $spaceFor(6), 'un código de 6 dígitos no cabe en ~200 respuestas');
        self::assertGreaterThan($spaceFor(4), $spaceFor(6));
    }

    /**
     * El punto central: un código aritmético de 6 dígitos no debe poder
     * romperse por fuerza bruta barriendo unos cientos de suposiciones. Toda
     * operación mantiene la respuesta dentro del techo de $length Y alcanza
     * mucho más allá de un conjunto pequeño de intentos.
     */
    public function testArithmeticAnswersAreNotSweepable(): void
    {
        foreach ([Operation::Add, Operation::Subtract, Operation::Multiply, Operation::Divide] as $operation) {
            $generator = new NumericGenerator([$operation]);
            $answers = [];

            for ($i = 0; $i < 6000; $i++) {
                $answers[(int) $generator->generate(6)] = true;
            }

            self::assertGreaterThan(
                2000,
                count($answers),
                sprintf('La operación "%s" deja un espacio de respuestas adivinable.', $operation->value),
            );
        }
    }

    /**
     * El techo de longitud sigue en pie: la respuesta jamás lleva más dígitos
     * de los que el campo del código puede contener.
     */
    public function testArithmeticAnswerNeverExceedsLength(): void
    {
        foreach ([Operation::Add, Operation::Subtract, Operation::Multiply, Operation::Divide] as $operation) {
            $generator = new NumericGenerator([$operation]);

            for ($i = 0; $i < 2000; $i++) {
                $code = $generator->generate(5);

                self::assertLessThanOrEqual(5, strlen($code));
                self::assertSame($code, self::evalOperands($generator->expression(), $operation));
            }
        }
    }

    /**
     * Evalúa la expresión ASCII que el generador acaba de producir para su
     * operación.
     */
    private static function evalOperands(string $expression, Operation $operation): string
    {
        $parts = explode($operation->symbol(), $expression);
        $a = (int) $parts[0];
        $b = (int) $parts[1];

        $answer = match ($operation) {
            Operation::Add => $a + $b,
            Operation::Subtract => $a - $b,
            Operation::Multiply => $a * $b,
            Operation::Divide => intdiv($a, $b),
        };

        return (string) $answer;
    }
}
