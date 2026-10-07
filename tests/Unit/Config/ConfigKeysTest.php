<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Config;

use Captcha\Config\Config;
use Captcha\Config\ConfigBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Puerta de sincronía entre `Config::KEYS` (el contrato de lectura) y lo que
 * el config realmente expone. Cubre los dos sentidos que ningún test de
 * comportamiento afirma por separado:
 *
 *  - una clave de KEYS que ningún lector consume sobreviviría al validador y
 *    desaparecería al exportar (leída en silencio por nadie);
 *  - una opción exportada que no está en KEYS sería rechazada la siguiente
 *    vez que se leyera el mismo array (ida y vuelta rota).
 *
 * Cada clave se lleva además por `fromArray()` con un valor distinto de su
 * default para que un lector que la descarte a mano salte ruidosamente.
 */
final class ConfigKeysTest extends TestCase
{
    public function testKeysAreExactlyTheExportedOptionsPlusThePreset(): void
    {
        $exportadas = array_keys(Config::defaults()->toArray());

        self::assertSame(
            [],
            array_diff(Config::KEYS, [...$exportadas, 'preset']),
            'Claves de KEYS que ni se leen ni se exportan: no las consume nadie.',
        );
        self::assertSame(
            [],
            array_diff($exportadas, Config::KEYS),
            'Opciones exportadas que no están en KEYS: fromArray() las rechazaría.',
        );
    }

    /**
     * El `operations` de base evita que `between` choque con el requisito de
     * operaciones habilitadas; la propia clave bajo prueba pisa ese valor.
     *
     * @param mixed $valor Entrada pasada a fromArray()
     * @param mixed $esperado Valor que debe sobrevivir en toArray()
     */
    #[DataProvider('keyProvider')]
    public function testEveryKeyIsReadAndExported(string $clave, mixed $valor, mixed $esperado): void
    {
        $opciones = ['operations' => ['+']];
        $opciones[$clave] = $valor;

        $exportado = Config::fromArray($opciones)->toArray();

        self::assertArrayHasKey($clave, $exportado, sprintf('La clave "%s" no llega al config exportado.', $clave));
        self::assertSame($esperado, $exportado[$clave], sprintf('La clave "%s" se descarta en silencio.', $clave));
    }

    /**
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function keyProvider(): iterable
    {
        yield 'length' => ['length', 8, 8];
        yield 'width' => ['width', 320, 320];
        yield 'height' => ['height', 90, 90];
        yield 'ttl' => ['ttl', 60, 60];
        yield 'difficulty' => ['difficulty', 'high', 'high'];
        yield 'font' => ['font', 3, 3];
        yield 'fontSize' => ['fontSize', 40, 40];
        yield 'noise' => ['noise', false, false];
        yield 'distortion' => ['distortion', false, false];
        yield 'output' => ['output', 'png', 'png'];
        yield 'idField' => ['idField', 'identificador', 'identificador'];
        yield 'inputField' => ['inputField', 'codigo', 'codigo'];
        yield 'injectAssets' => ['injectAssets', false, false];
        yield 'operations' => ['operations', ['*'], ['multiply']];
        yield 'between' => ['between', [0, 50], [0, 50]];
        yield 'verifyAttempts' => ['verifyAttempts', 9, 9];
        yield 'generateAttempts' => ['generateAttempts', 9, 9];
        yield 'rateLimitWindow' => ['rateLimitWindow', 60, 60];
        yield 'honeypot' => ['honeypot', true, true];
        yield 'honeypotField' => ['honeypotField', 'web', 'web'];
        yield 'rateLimitByIp' => ['rateLimitByIp', false, false];
        yield 'storage' => ['storage', 'file', 'file'];
        yield 'trustedProxies' => ['trustedProxies', ['203.0.113.7'], ['203.0.113.7']];
    }

    /**
     * El dataset no puede crecer solo: si mañana aparece una clave nueva en
     * KEYS sin fila propia, esta puerta lo dice antes de que el lector huérfano
     * pase desapercibido. El preset se salva porque no se lee como opción: se
     * expande antes del parseo (ver el test de abajo).
     */
    public function testProviderCoversEveryKeyExceptThePreset(): void
    {
        $cubiertas = array_column(iterator_to_array(self::keyProvider(), false), 0);
        $esperadas = array_values(array_diff(Config::KEYS, ['preset']));

        sort($cubiertas);
        sort($esperadas);

        self::assertSame($esperadas, $cubiertas, 'Faltan filas en keyProvider() para claves de KEYS.');
    }

    /*
    *  El builder es la otra cara de KEYS: un setter que falte deja una
    *  opción sin forma de fijarse por código y nada lo diría (hoy cuadran
    *  24 y 24, pero por casualidad, no por una puerta).
    */
    public function testBuilderExposesASetterForEachKey(): void
    {
        $setters = array_diff(get_class_methods(ConfigBuilder::class), ['build', 'from', 'toArray']);

        self::assertSame([], array_diff(Config::KEYS, $setters), 'Claves de KEYS sin setter en el builder.');
        self::assertSame([], array_diff($setters, Config::KEYS), 'Setter del builder sin clave en KEYS.');
    }

    /*
    *  Un preset solo puede componerse con claves que el config acepte:
    *  una clave inventada dentro de PRESETS pasaría el validador de
    *  assertKnownKeys() (que mira las opciones de entrada) y moriría más
    *  adelante como argumento desconocido del constructor.
    */
    public function testPresetsOnlyUseKnownKeys(): void
    {
        foreach (Config::PRESETS as $nombre => $opciones) {
            self::assertSame(
                [],
                array_diff(array_keys($opciones), Config::KEYS),
                sprintf('El preset "%s" usa claves que KEYS no conoce.', $nombre),
            );
        }
    }

    public function testPresetExpandsInsteadOfBeingExported(): void
    {
        $exportado = Config::fromArray(['preset' => 'strict'])->toArray();

        self::assertArrayNotHasKey('preset', $exportado);
        self::assertSame(6, $exportado['length']);
        self::assertTrue($exportado['honeypot']);
    }
}
