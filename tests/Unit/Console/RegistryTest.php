<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Console;

use Captcha\Console\Command;
use Captcha\Console\Option;
use Captcha\Console\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    public function testTheCatalogHoldsTheFourDocumentedCommands(): void
    {
        $nombres = array_map(
            static fn(Command $command): string => $command->name,
            Registry::commands(),
        );

        self::assertSame(['install', 'doctor', 'list', 'help'], $nombres);
    }

    public function testEveryCommandHasASummaryAndAnExample(): void
    {
        foreach (Registry::commands() as $command) {
            self::assertNotSame('', $command->summary, $command->name);
            self::assertNotSame('', $command->description, $command->name);
            self::assertNotSame([], $command->examples, $command->name);
        }
    }

    public function testFindResolvesNamesAndAliases(): void
    {
        self::assertSame('install', Registry::find('install')?->name);
        self::assertSame('install', Registry::find('i')?->name);
        self::assertSame('install', Registry::find('init')?->name);
        self::assertSame('doctor', Registry::find('doctor')?->name);
        self::assertSame('doctor', Registry::find('d')?->name);
        self::assertSame('list', Registry::find('ls')?->name);
        self::assertSame('help', Registry::find('?')?->name);
    }

    public function testFindReturnsNullForAnUnknownToken(): void
    {
        self::assertNull(Registry::find('nope'));
        self::assertNull(Registry::find(''));
    }

    public function testGlobalOptionsAreTheFourDocumentedOnes(): void
    {
        $nombres = array_map(
            static fn(Option $opcion): string => $opcion->name,
            Registry::globalOptions(),
        );

        self::assertSame(['help', 'version', 'quiet', 'no-ansi'], $nombres);
    }

    public function testGlobalFlagsNeverTakeAValue(): void
    {
        foreach (Registry::globalOptions() as $opcion) {
            self::assertFalse($opcion->takesValue, $opcion->name);
        }
    }

    public function testCommandOptionsComeBeforeTheGlobalOnes(): void
    {
        $doctor = Registry::find('doctor');

        self::assertNotNull($doctor);
        self::assertSame('strict', $doctor->allOptions()[0]->name);
        self::assertSame('help', $doctor->allOptions()[1]->name);
    }

    public function testHelpTokensCoverBothTheCommandAndItsFlags(): void
    {
        /*
        *  `help` y `?` son alias del comando; `--help` y `-h` son opciones
        *  globales, y por eso no pasan por Registry::find().
        */
        self::assertSame('help', Registry::find('help')?->name);
        self::assertSame('help', Registry::find('?')?->name);
        self::assertContains('--help', Registry::helpTokens());
        self::assertContains('-h', Registry::helpTokens());
    }

    public function testSpellingsStartWithTheCanonicalName(): void
    {
        self::assertSame(['install', 'i', 'init'], Registry::find('install')?->spellings());
    }

    public function testInstallDeclaresTheFrameworkAsAnOptionAndAnArgument(): void
    {
        $install = Registry::find('install');

        self::assertNotNull($install);
        self::assertSame('framework', $install->options[0]->name);
        self::assertSame('f', $install->options[0]->short);
        self::assertTrue($install->options[0]->takesValue);
        self::assertSame('framework', $install->arguments[0]->name);
        self::assertFalse($install->arguments[0]->required);
    }

    public function testTheInstallOptionAdvertisesEveryFramework(): void
    {
        $opcion = Registry::find('install')?->options[0];

        self::assertNotNull($opcion);
        self::assertStringContainsString('laravel', (string) $opcion->valueLabel);
        self::assertStringContainsString('cakephp', (string) $opcion->valueLabel);
    }

    public function testVersionIsAStringEvenWhenComposerKnowsNothing(): void
    {
        self::assertNotSame('', Registry::version());
    }
}
