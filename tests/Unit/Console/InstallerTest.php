<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Console;

use Captcha\Config\Config;
use Captcha\Console\Installer;
use Captcha\Exception\InvalidConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    public function testFrameworksListTheSupportedIdentifiers(): void
    {
        self::assertSame(
            ['plain', 'codeigniter', 'laravel', 'symfony', 'cakephp', 'yii', 'janssen'],
            Installer::frameworks(),
        );
    }

    public function testTargetPathPerFramework(): void
    {
        $cases = [
            'plain' => 'app/Config/captcha.php',
            'codeigniter' => 'app/Config/captcha.php',
            'laravel' => 'config/captcha.php',
            'symfony' => 'config/packages/captcha.php',
            'cakephp' => 'config/captcha.php',
            'yii' => 'config/captcha.php',
            'janssen' => 'app/Config/captcha.php',
        ];

        foreach ($cases as $framework => $expected) {
            self::assertSame("/tmp/project/{$expected}", Installer::targetPath('/tmp/project', $framework), $framework);
        }
    }

    public function testTargetPathHandlesATrailingSlashInTheRoot(): void
    {
        self::assertSame('/tmp/project/app/Config/captcha.php', Installer::targetPath('/tmp/project/', 'plain'));
    }

    public function testUnknownFrameworkRejects(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('no reconocido');

        Installer::targetPath('/tmp/project', 'drupal');
    }

    #[DataProvider('frameworkProvider')]
    public function testEachTemplateIsAValidConfigFile(string $framework): void
    {
        $target = tempnam(sys_get_temp_dir(), 'captcha-install-');
        (string) file_put_contents($target, Installer::templateContents($framework));

        try {
            $loaded = require $target;

            self::assertIsArray($loaded, $framework);
            Config::fromArray($loaded);
        } finally {
            @unlink($target);
        }
    }

    public function testCakePhpTemplateIsFlatLikeTheRest(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'captcha-install-');
        (string) file_put_contents($target, Installer::templateContents('cakephp'));

        try {
            $loaded = require $target;

            self::assertIsArray($loaded);
            self::assertArrayNotHasKey('Captcha', $loaded, 'Cake no anida: el descubrimiento lo lee plano');
        } finally {
            @unlink($target);
        }
    }

    #[DataProvider('frameworkProvider')]
    public function testArtifactsStartWithTheConfigAndCarryItsNotesNone(): void
    {
        $artefactos = Installer::artifacts('/tmp/project', 'laravel');

        self::assertSame('/tmp/project/config/captcha.php', $artefactos[0]->path);
        self::assertSame([], $artefactos[0]->notes, 'el config no deja nada por registrar');
        self::assertFalse($artefactos[0]->hasNotes());
    }

    #[DataProvider('frameworkProvider')]
    public function testEveryFrameworkEmitsConfigPlusIntegration(string $framework): void
    {
        $artefactos = Installer::artifacts('/tmp/project', $framework);

        self::assertGreaterThan(1, count($artefactos), $framework . ': se esperaba más allá del config');

        foreach ($artefactos as $artefacto) {
            self::assertStringStartsWith('/tmp/project/', $artefacto->path, $framework);
            self::assertNotSame('', trim($artefacto->contents), $framework . ': ' . $artefacto->path);
        }
    }

    #[DataProvider('frameworkProvider')]
    public function testIntegrationArtifactsDeclareHowToRegisterThem(string $framework): void
    {
        $artefactos = array_slice(Installer::artifacts('/tmp/project', $framework), 1);

        foreach ($artefactos as $artefacto) {
            self::assertTrue($artefacto->hasNotes(), $framework . ': ' . $artefacto->path . ' sin notas de registro');
            self::assertNotSame('', trim(implode('', $artefacto->notes)), $framework . ': notas vacías');
        }
    }

    #[DataProvider('frameworkProvider')]
    public function testNoFrameworkWritesTheSamePathTwice(string $framework): void
    {
        $paths = array_map(
            static fn($artefacto): string => $artefacto->path,
            Installer::artifacts('/tmp/project', $framework),
        );

        self::assertSame(array_values(array_unique($paths)), $paths, $framework . ': destinos duplicados');
    }

    public function testArtifactsRejectsAnUnknownFramework(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('no reconocido');

        Installer::artifacts('/tmp/project', 'wordpress');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frameworkProvider(): iterable
    {
        foreach (Installer::frameworks() as $framework) {
            yield $framework => [$framework];
        }
    }
}
