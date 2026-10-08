<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Runtime;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Config\Operation;
use Captcha\Exception\InvalidConfigException;
use Captcha\Runtime\StaticLayer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las variables CAPTCHA_* son una vía de configuración por opción sobre el
 * descubrimiento, así que estas pruebas fijan su precedencia (configure() >
 * env > fichero > defaults), los formatos que cada familia admite (booleanos
 * como texto, arrays como JSON, preset y enteros como cadenas) y que un valor
 * inválido falla en el momento del arranque en lugar de desactivarse en
 * silencio.
 *
 * El aislamiento importa el doble que en otros tests: putenv() es un estado
 * global del proceso, y una CAPTCHA_* olvidada convertiría en env-dependiente
 * a toda la suite. Se limpian en setUp y tearDown, igual que el config manual
 * y la instancia.
 */
final class EnvOverridesTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporales = [];

    protected function setUp(): void
    {
        Captcha::reset();
        Captcha::configure([]);
        self::limpiarEnv();
    }

    protected function tearDown(): void
    {
        Captcha::reset();
        Captcha::configure([]);
        self::limpiarEnv();

        foreach ($this->temporales as $path) {
            unlink($path);
        }

        $this->temporales = [];
    }

    public function testEnvOverridesTheDiscoveredFile(): void
    {
        $file = $this->tempConfig();

        self::env('CAPTCHA_CONFIG', $file);
        self::env('CAPTCHA_LENGTH', '9');

        self::assertSame(9, Captcha::instance()->config()->length, 'ni el default 6 ni el fichero 4');
        self::assertSame($file, Captcha::configPath(), 'el fichero se sigue descubriendo');
    }

    public function testTheFileRulesWhenNoEnvIsSet(): void
    {
        self::env('CAPTCHA_CONFIG', $this->tempConfig());

        self::assertSame(4, Captcha::instance()->config()->length);
    }

    public function testDefaultsRuleWhenNothingIsSet(): void
    {
        self::assertSame(Config::DEFAULT_LENGTH, Captcha::instance()->config()->length);
    }

    public function testManualConfigWinsOverEnv(): void
    {
        self::env('CAPTCHA_LENGTH', '6');
        Captcha::configure(['length' => 4]);

        self::assertSame(4, Captcha::instance()->config()->length);
    }

    public function testArrayOptionsArriveAsJson(): void
    {
        self::env('CAPTCHA_BETWEEN', '[2, 20]');
        self::env('CAPTCHA_OPERATIONS', '["+", "-"]');
        self::env('CAPTCHA_TRUSTEDPROXIES', '["203.0.113.9"]');

        $config = Captcha::instance()->config();

        self::assertSame([2, 20], $config->between);
        self::assertSame(['+', '-'], array_map(
            static fn(Operation $operation): string => $operation->symbol(),
            $config->operations,
        ));
        self::assertSame(['203.0.113.9'], $config->trustedProxies);
    }

    public function testPresetArrivesFromEnv(): void
    {
        self::env('CAPTCHA_PRESET', 'login');

        self::assertSame(5, Captcha::instance()->config()->length);
    }

    public function testEmptyValueIsIgnored(): void
    {
        self::env('CAPTCHA_LENGTH', '');

        self::assertSame(Config::DEFAULT_LENGTH, Captcha::instance()->config()->length);
    }

    public function testUnknownSuffixIsIgnored(): void
    {
        self::env('CAPTCHA_XYZ', '9');

        self::assertInstanceOf(Captcha::class, Captcha::instance());
    }

    public function testAnInvalidBooleanEnvThrows(): void
    {
        self::env('CAPTCHA_NOISE', 'quizá');

        $this->expectException(InvalidConfigException::class);
        Captcha::instance();
    }

    public function testAnInvalidArrayEnvThrows(): void
    {
        self::env('CAPTCHA_BETWEEN', '2,20');

        $this->expectException(InvalidConfigException::class);
        Captcha::instance();
    }

    public function testAMalformedIntegerEnvThrows(): void
    {
        self::env('CAPTCHA_LENGTH', 'abc');

        $this->expectException(InvalidConfigException::class);
        Captcha::instance();
    }

    /**
     * La forma guionada existe para que un despliegue escriba el nombre que
     * cabría esperar de una env 12-factor (CAPTCHA_RATE_LIMIT_BY_IP) sin que
     * el silencio del sufijo desconocido apague un dial de seguridad.
     */
    public function testSnakeCaseAliasOverridesAMultiwordDial(): void
    {
        self::env('CAPTCHA_RATE_LIMIT_BY_IP', '0');

        self::assertFalse(Captcha::instance()->config()->rateLimitByIp);
        self::assertSame(['rateLimitByIp'], StaticLayer::envOptionNames());
    }

    public function testCanonicalNameWinsWhenBothSpellingsAreSet(): void
    {
        self::env('CAPTCHA_RATELIMITBYIP', '1');
        self::env('CAPTCHA_RATE_LIMIT_BY_IP', '0');

        self::assertTrue(Captcha::instance()->config()->rateLimitByIp);
    }

    public function testSnakeCaseAliasAlsoCarriesJsonArrays(): void
    {
        self::env('CAPTCHA_TRUSTED_PROXIES', '["203.0.113.7"]');

        self::assertSame(['203.0.113.7'], Captcha::instance()->config()->trustedProxies);
    }

    public function testHasEnvOverridesAndEnvOptionNamesReportTheSameSet(): void
    {
        self::assertFalse(StaticLayer::hasEnvOverrides());
        self::assertSame([], StaticLayer::envOptionNames());

        self::env('CAPTCHA_HONEYPOT', '1');
        self::env('CAPTCHA_LENGTH', '5');

        self::assertTrue(StaticLayer::hasEnvOverrides());
        self::assertSame(['length', 'honeypot'], StaticLayer::envOptionNames(), 'en el orden de Config::KEYS');
    }

    #[DataProvider('provideTrueSpellings')]
    public function testTrueSpellingsEnableABooleanDial(string $raw): void
    {
        self::env('CAPTCHA_HONEYPOT', $raw);

        self::assertTrue(Captcha::instance()->config()->honeypot);
    }

    #[DataProvider('provideFalseSpellings')]
    public function testFalseSpellingsDisableABooleanDial(string $raw): void
    {
        self::env('CAPTCHA_NOISE', $raw);

        self::assertFalse(Captcha::instance()->config()->noise);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTrueSpellings(): iterable
    {
        yield 'uno' => ['1'];
        yield 'true' => ['true'];
        yield 'yes' => ['yes'];
        yield 'on' => ['on'];
        yield 'true mayúscula' => ['TRUE'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideFalseSpellings(): iterable
    {
        yield 'cero' => ['0'];
        yield 'false' => ['false'];
        yield 'no' => ['no'];
        yield 'off' => ['off'];
    }

    /**
     * Un config que devuelve 4 —distinto del DEFAULT_LENGTH, para que un
     * fallo de precedencia no pase desapercibido—.
     */
    private function tempConfig(): string
    {
        $path = sys_get_temp_dir() . '/captcha-env-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, '<?php return ["length" => 4];');
        $this->temporales[] = $path;

        return $path;
    }

    private static function env(string $name, string $value): void
    {
        putenv($name . '=' . $value);
    }

    /**
     * Deja el entorno sin ninguna CAPTCHA_* ni CAPTCHA_CONFIG, para que ningún
     * test herede una variable que fijó otro. Cada opción se limpia en sus dos
     * grafías, la pegada y la guionada.
     */
    private static function limpiarEnv(): void
    {
        foreach (Config::KEYS as $key) {
            putenv('CAPTCHA_' . strtoupper($key));
            putenv('CAPTCHA_' . strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key));
        }

        putenv('CAPTCHA_CONFIG');
    }
}
