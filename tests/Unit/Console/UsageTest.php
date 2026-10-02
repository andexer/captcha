<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Console;

use Captcha\Console\Registry;
use Captcha\Console\Style;
use Captcha\Console\Usage;
use PHPUnit\Framework\TestCase;

final class UsageTest extends TestCase
{
    public function testTheOverviewListsEveryCommandWithItsSummary(): void
    {
        $texto = implode("\n", Usage::overview(Style::plain()));

        foreach (Registry::commands() as $command) {
            self::assertStringContainsString($command->name, $texto, $command->name);
            self::assertStringContainsString($command->summary, $texto, $command->name);
        }
    }

    public function testTheOverviewListsTheGlobalOptions(): void
    {
        $texto = implode("\n", Usage::overview(Style::plain()));

        self::assertStringContainsString('--help', $texto);
        self::assertStringContainsString('--version', $texto);
        self::assertStringContainsString('--quiet', $texto);
        self::assertStringContainsString('--no-ansi', $texto);
    }

    public function testTheOverviewNamesTheBin(): void
    {
        $texto = implode("\n", Usage::overview(Style::plain()));

        self::assertStringContainsString(Usage::BIN, $texto);
    }

    public function testCommandHelpShowsItsOwnOptionsAndExamples(): void
    {
        $install = Registry::find('install');

        self::assertNotNull($install);
        $texto = implode("\n", Usage::command($install, Style::plain()));

        self::assertStringContainsString('--framework', $texto);
        self::assertStringContainsString('laravel', $texto);
        self::assertStringContainsString('vendor/bin/captcha install --framework=symfony', $texto);
    }

    public function testCommandHelpNamesItsAliases(): void
    {
        $doctor = Registry::find('doctor');

        self::assertNotNull($doctor);
        $texto = implode("\n", Usage::command($doctor, Style::plain()));

        self::assertStringContainsString('d', $texto);
        self::assertStringContainsString('--strict', $texto);
    }

    public function testTheSynopsisUsesTheCompactFormOfTheOptions(): void
    {
        $doctor = Registry::find('doctor');

        self::assertNotNull($doctor);
        self::assertStringContainsString('-s|--strict', Usage::synopsis($doctor));
    }

    public function testTheSynopsisSpellsOutAFrameworkValue(): void
    {
        $install = Registry::find('install');

        self::assertNotNull($install);
        self::assertStringContainsString('--framework=<', Usage::synopsis($install));
    }

    public function testPlainStyleNeverEmitsEscapeCodes(): void
    {
        $texto = implode("\n", Usage::overview(Style::plain()));

        self::assertStringNotContainsString("\033", $texto);
    }

    public function testTheColoredStyleKeepsTheSameWordsAndAddsEscapes(): void
    {
        $style = new Style(true);
        $plano = implode("\n", Usage::overview(Style::plain()));
        $color = implode("\n", Usage::overview($style));

        self::assertStringContainsString("\033", $color);
        self::assertStringContainsString(
            (string) preg_replace('/\033\[[0-9;]*m/', '', $color),
            $plano,
        );
    }
}
