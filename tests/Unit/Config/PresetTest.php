<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Config;

use Captcha\Config\Config;
use Captcha\Config\Difficulty;
use Captcha\Exception\InvalidConfigException;
use PHPUnit\Framework\TestCase;

final class PresetTest extends TestCase
{
    public function testLoginPresetAppliesItsBundle(): void
    {
        $config = Config::fromArray(['preset' => 'login']);

        self::assertSame(5, $config->length);
        self::assertSame(200, $config->width);
        self::assertSame(60, $config->height);
        self::assertSame(120, $config->ttl);
        self::assertSame(Difficulty::Low, $config->difficulty);
        self::assertFalse($config->noise);
        self::assertFalse($config->distortion);
        self::assertSame(5, $config->verifyAttempts);
        self::assertSame(30, $config->generateAttempts);
    }

    public function testStrictPresetAppliesItsBundle(): void
    {
        $config = Config::fromArray(['preset' => 'strict']);

        self::assertSame(6, $config->length);
        self::assertSame(220, $config->width);
        self::assertSame(64, $config->height);
        self::assertSame(120, $config->ttl);
        self::assertSame(Difficulty::High, $config->difficulty);
        self::assertTrue($config->noise);
        self::assertTrue($config->distortion);
        self::assertSame(5, $config->verifyAttempts);
        self::assertSame(20, $config->generateAttempts);
        self::assertTrue($config->honeypot);
        self::assertSame('website', $config->honeypotField);
    }

    public function testDefaultPresetKeepsConstructorDefaults(): void
    {
        $config = Config::fromArray(['preset' => 'default']);
        $expected = Config::fromArray([]);

        self::assertSame($expected->length, $config->length);
        self::assertSame($expected->width, $config->width);
        self::assertSame($expected->height, $config->height);
        self::assertSame($expected->ttl, $config->ttl);
        self::assertSame($expected->difficulty, $config->difficulty);
        self::assertSame($expected->noise, $config->noise);
        self::assertSame($expected->verifyAttempts, $config->verifyAttempts);
    }

    public function testExplicitKeysWinOverThePreset(): void
    {
        $config = Config::fromArray([
            'preset' => 'login',
            'length' => 7,
            'noise' => true,
        ]);

        self::assertSame(7, $config->length);
        self::assertTrue($config->noise);
        // El resto del preset sigue aplicando.
        self::assertSame(200, $config->width);
        self::assertSame(Difficulty::Low, $config->difficulty);
        self::assertSame(30, $config->generateAttempts);
    }

    public function testPresetAcceptsNumericStringKeysAfterwards(): void
    {
        /*
        *  El merge ocurre antes del parsing: una clave del preset puede
        *  sobrescribirse con un string numérico como cualquier otra.
        */
        $config = Config::fromArray(['preset' => 'strict', 'ttl' => '120']);

        self::assertSame(120, $config->ttl);
        self::assertSame(Difficulty::High, $config->difficulty);
    }

    public function testUnknownPresetIsRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Preset "balanced" no reconocido; usa "default", "login", "strict".');

        Config::fromArray(['preset' => 'balanced']);
    }

    public function testNonStringPresetIsRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("'preset' debe ser texto.");

        Config::fromArray(['preset' => true]);
    }

    public function testConstructorStillHasNoPreset(): void
    {
        /*
        *  El preset es atajo de fromArray (config file / configure());
        *  el constructor se queda igual.
        */
        $config = new Config();

        self::assertSame(Config::DEFAULT_LENGTH, $config->length);
        self::assertSame(Config::DEFAULT_VERIFY_ATTEMPTS, $config->verifyAttempts);
        self::assertFalse($config->honeypot);
    }
}
