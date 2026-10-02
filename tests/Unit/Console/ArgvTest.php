<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Console;

use Captcha\Console\Argv;
use Captcha\Console\ConsoleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArgvTest extends TestCase
{
    public function testResolvesTheCommandAndItsAlias(): void
    {
        self::assertSame('install', Argv::parse(['captcha', 'install'])->commandName());
        self::assertSame('install', Argv::parse(['captcha', 'i'])->commandName());
        self::assertSame('install', Argv::parse(['captcha', 'init'])->commandName());
        self::assertSame('doctor', Argv::parse(['captcha', 'd'])->commandName());
    }

    public function testWithoutArgumentsThereIsNoCommand(): void
    {
        $linea = Argv::parse(['captcha']);

        self::assertNull($linea->command);
        self::assertSame('', $linea->commandName());
        self::assertSame([], $linea->arguments);
    }

    /**
     * Las tres formas de dar un valor tienen que acabar en el mismo sitio, o
     * la ayuda estaría mintiendo sobre la sintaxis que ella misma imprime.
     */
    #[DataProvider('frameworkForms')]
    public function testAcceptsEveryFormOfTheFrameworkValue(array $tokens): void
    {
        $linea = Argv::parse(array_merge(['captcha', 'install'], $tokens));

        self::assertSame('laravel', $linea->value('framework'));
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function frameworkForms(): array
    {
        return [
            'larga con igual' => [['--framework=laravel']],
            'larga con espacio' => [['--framework', 'laravel']],
            'corta con espacio' => [['-f', 'laravel']],
            'corta con igual' => [['-f=laravel']],
        ];
    }

    public function testThePositionalArgumentIsNotAlsoTheOption(): void
    {
        $linea = Argv::parse(['captcha', 'install', 'laravel']);

        self::assertNull($linea->value('framework'));
        self::assertSame(['framework' => 'laravel'], $linea->argumentsByName());
    }

    public function testFlagsAreTrueAndValueOptionsAreStrings(): void
    {
        $linea = Argv::parse(['captcha', 'doctor', '--strict']);

        self::assertTrue($linea->flag('strict'));
        self::assertFalse($linea->flag('quiet'));
        self::assertNull($linea->value('strict'));
    }

    public function testGlobalOptionsAreAcceptedWithoutCommand(): void
    {
        $linea = Argv::parse(['captcha', '--no-ansi', '-q', '-V']);

        self::assertTrue($linea->flag('no-ansi'));
        self::assertTrue($linea->flag('quiet'));
        self::assertTrue($linea->flag('version'));
    }

    public function testDoubleDashEndsTheOptions(): void
    {
        $linea = Argv::parse(['captcha', 'install', '--', '--framework']);

        self::assertNull($linea->value('framework'));
        self::assertSame(['framework' => '--framework'], $linea->argumentsByName());
    }

    public function testUnknownCommandIsRejectedWithTheCanonicalList(): void
    {
        try {
            Argv::parse(['captcha', 'nope']);
            self::fail('Un comando desconocido debería lanzar ConsoleException.');
        } catch (ConsoleException $exception) {
            self::assertSame('Comando desconocido: "nope".', $exception->getMessage());
            self::assertSame('Comandos disponibles: install, doctor, list, help.', $exception->hint());
        }
    }

    public function testUnknownCommandSuggestsTheClosestOne(): void
    {
        try {
            Argv::parse(['captcha', 'instal']);
            self::fail('Un comando casi correcto debería lanzar ConsoleException.');
        } catch (ConsoleException $exception) {
            self::assertSame('¿Querías decir "install"?', $exception->hint());
        }
    }

    public function testUnknownOptionSuggestsTheClosestOne(): void
    {
        try {
            Argv::parse(['captcha', 'doctor', '--quie']);
            self::fail('Una opción casi correcta debería lanzar ConsoleException.');
        } catch (ConsoleException $exception) {
            self::assertSame('Opción desconocida: "--quie".', $exception->getMessage());
            self::assertSame('¿Querías decir "--quiet"?', $exception->hint());
        }
    }

    public function testAnOptionOfAnotherCommandIsNotAccepted(): void
    {
        /*
        *  `--framework` es de install: desde doctor solo existen las suyas y
        *  las globales, y el error debe decirlo en vez de aceptarla en
        *  silencio, que es lo que haría un parser indulgente.
        */
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('Opción desconocida: "--framework".');

        Argv::parse(['captcha', 'doctor', '--framework=laravel']);
    }

    public function testAMissingValueNamesTheOption(): void
    {
        try {
            Argv::parse(['captcha', 'install', '--framework']);
            self::fail('Una opción sin valor debería lanzar ConsoleException.');
        } catch (ConsoleException $exception) {
            self::assertSame('La opción --framework necesita un valor.', $exception->getMessage());
            self::assertSame('Ejemplo: --framework=laravel', $exception->hint());
        }
    }

    public function testAValueOptionConsumesTheNextToken(): void
    {
        /*
        *  Consumir el token siguiente es lo correcto: `--framework` pide un
        *  valor y `doctor` es un valor escrito. Lo que no puede pasar es que
        *  el valor se trague el comando, y eso se evita porque las opciones con
        *  valor no se admiten antes del comando.
        */
        $linea = Argv::parse(['captcha', 'install', '--framework', 'doctor']);

        self::assertSame('install', $linea->commandName());
        self::assertSame('doctor', $linea->value('framework'));
    }

    public function testAValueOptionBeforeTheCommandIsNotAccepted(): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('Opción desconocida: "--framework".');

        Argv::parse(['captcha', '--framework', 'laravel', 'install']);
    }

    public function testAValueGivenToAFlagIsRejected(): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('La opción --strict no admite un valor.');

        Argv::parse(['captcha', 'doctor', '--strict=yes']);
    }

    public function testAnExtraArgumentIsRejected(): void
    {
        try {
            Argv::parse(['captcha', 'doctor', 'extra'])->argumentsByName();
            self::fail('Un argumento de más debería lanzar ConsoleException.');
        } catch (ConsoleException $exception) {
            self::assertSame('Argumento inesperado: "extra".', $exception->getMessage());
            self::assertStringContainsString('no admite argumentos', (string) $exception->hint());
        }
    }

    public function testTooManyPositionalArgumentsAreRejected(): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('Argumento inesperado: "tercero".');

        Argv::parse(['captcha', 'install', 'laravel', 'tercero'])->argumentsByName();
    }

    public function testOptionalArgumentMayBeOmitted(): void
    {
        self::assertSame([], Argv::parse(['captcha', 'help'])->argumentsByName());
    }

    public function testTheErrorCarriesANonZeroExitCode(): void
    {
        try {
            Argv::parse(['captcha', 'nope']);
            self::fail('Debería lanzar ConsoleException.');
        } catch (ConsoleException $exception) {
            self::assertSame(1, $exception->exitCode());
        }
    }
}
