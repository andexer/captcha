<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Config;

use Captcha\Config\Config;
use Captcha\Config\ConfigBuilder;
use Captcha\Config\Difficulty;
use Captcha\Exception\InvalidConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigBuilderTest extends TestCase
{
    public function testEmptyBuilderBuildsTheConstructorDefaults(): void
    {
        self::assertEquals(new Config(), Config::builder()->build());
    }

    public function testBuildMatchesFromArrayOfTheSameOptions(): void
    {
        $builder = Config::builder()
            ->length(5)
            ->difficulty('low')
            ->operations(['+', '-'])
            ->between([2, 20])
            ->verifyAttempts(5)
            ->generateAttempts(30)
            ->honeypot(true)
            ->honeypotField('website');

        $expected = Config::fromArray([
            'length' => 5,
            'difficulty' => 'low',
            'operations' => ['+', '-'],
            'between' => [2, 20],
            'verifyAttempts' => 5,
            'generateAttempts' => 30,
            'honeypot' => true,
            'honeypotField' => 'website',
        ]);

        self::assertEquals($expected, $builder->build());
    }

    public function testBuildWithPresetAppliesTheShortcut(): void
    {
        $config = Config::builder()->preset('strict')->length(8)->build();

        self::assertSame(8, $config->length);
        self::assertTrue($config->honeypot);
        self::assertSame(5, $config->verifyAttempts);
    }

    public function testBuildValidatesRanges(): void
    {
        $this->expectException(InvalidConfigException::class);

        Config::builder()->length(100)->build();
    }

    public function testBuildRejectsUnknownSeedKeysStrictly(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Claves de configuración no reconocidas');

        Config::builder()->from(['verifyATempts' => 5])->build();
    }

    public function testSettersAreTyped(): void
    {
        $this->expectException(\TypeError::class);

        Config::builder()->length('6');
    }

    public function testFromSeedsFromAConfigInstance(): void
    {
        $config = Config::builder()->from(Config::forLogin())->length(8)->build();

        self::assertSame(8, $config->length);
        self::assertSame(5, $config->verifyAttempts);
    }

    public function testFromSeedsFromAnotherBuilder(): void
    {
        $builder = Config::builder()->from(ConfigBuilder::from(['length' => 4]))->length(6);

        self::assertSame(['length' => 6], $builder->toArray());
    }

    public function testToArrayMirrorsTheAccumulatedOptions(): void
    {
        $builder = Config::builder()->honeypot(true)->honeypotField('website');

        self::assertSame(['honeypot' => true, 'honeypotField' => 'website'], $builder->toArray());
    }

    public function testFromDoesNotShareStateWithItsSeed(): void
    {
        $seed = Config::builder()->length(3);
        $copy = ConfigBuilder::from($seed)->length(9);

        self::assertSame(3, $seed->build()->length);
        self::assertSame(9, $copy->build()->length);
    }

    public function testReferenceFactoriesExitOnTheConfig(): void
    {
        self::assertInstanceOf(Config::class, Config::defaults());
        self::assertInstanceOf(Config::class, Config::forLogin());
        self::assertInstanceOf(Config::class, Config::forStrict());
    }

    /**
     * Cada setter escribe en la clave que Config entiende: si uno se renombra
     * o se equivoca de nombre, el array acumulado deja de ser una configuración
     * y el fallo aparece como "opción no reconocida" en el build() de otro.
     */
    public function testEverySetterLandsInItsConfigKey(): void
    {
        $builder = ConfigBuilder::from()
            ->preset('default')
            ->length(7)
            ->width(200)
            ->height(70)
            ->ttl(120)
            ->difficulty(Difficulty::High)
            ->font(4)
            ->fontSize(22)
            ->noise(false)
            ->distortion(false)
            ->output('png')
            ->idField('cid')
            ->inputField('codigo')
            ->injectAssets(false)
            ->operations(['add'])
            ->between([3, 12])
            ->verifyAttempts(4)
            ->generateAttempts(9)
            ->rateLimitWindow(45)
            ->honeypot(true)
            ->honeypotField('sitio')
            ->rateLimitByIp(false)
            ->storage('array')
            ->trustedProxies(['203.0.113.5']);

        self::assertSame([
            'preset' => 'default',
            'length' => 7,
            'width' => 200,
            'height' => 70,
            'ttl' => 120,
            'difficulty' => Difficulty::High,
            'font' => 4,
            'fontSize' => 22,
            'noise' => false,
            'distortion' => false,
            'output' => 'png',
            'idField' => 'cid',
            'inputField' => 'codigo',
            'injectAssets' => false,
            'operations' => ['add'],
            'between' => [3, 12],
            'verifyAttempts' => 4,
            'generateAttempts' => 9,
            'rateLimitWindow' => 45,
            'honeypot' => true,
            'honeypotField' => 'sitio',
            'rateLimitByIp' => false,
            'storage' => 'array',
            'trustedProxies' => ['203.0.113.5'],
        ], $builder->toArray());

        $config = $builder->build();

        self::assertSame(7, $config->length);
        self::assertSame(Difficulty::High, $config->difficulty);
        self::assertSame([3, 12], $config->between);
        self::assertSame(['203.0.113.5'], $config->trustedProxies);
    }

    public function testFromWithoutSeedStartsFromTheDefaults(): void
    {
        self::assertSame([], ConfigBuilder::from()->toArray());
        self::assertEquals(new Config(), ConfigBuilder::from()->build());
    }

    public function testFontSizeAndBetweenAcceptNull(): void
    {
        $config = ConfigBuilder::from()->fontSize(null)->between(null)->build();

        self::assertNull($config->fontSize);
        self::assertNull($config->between);
    }
}
