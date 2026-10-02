<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit;

use Captcha\Captcha;
use Captcha\Config\Config;
use PHPUnit\Framework\TestCase;

final class CaptchaConfigureTest extends TestCase
{
    /*
    *  Config hermético: un array vacío servido por la variable de entorno,
    *  para que la suite no dependa de los ficheros de configuración del repo.
    */
    private static ?string $sharedConfigFile = null;

    private ?string $requestMethodBackup = null;

    private array $postBackup = [];

    protected function setUp(): void
    {
        Captcha::reset();
        Captcha::configure([]);

        $file = self::$sharedConfigFile ??= (static function (): string {
            $file = (string) tempnam(sys_get_temp_dir(), 'captcha-empty-config-');
            file_put_contents($file, '<?php return [];');

            return $file;
        })();

        putenv('CAPTCHA_CONFIG=' . $file);

        $this->requestMethodBackup = $_SERVER['REQUEST_METHOD'] ?? null;
        $this->postBackup = $_POST;
        unset($_SERVER['REQUEST_METHOD']);
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Captcha::reset();
        Captcha::configure([]);

        if ($this->requestMethodBackup === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->requestMethodBackup;
        }
        $_POST = $this->postBackup;
    }

    public function testConfigureReplacesFileDiscovery(): void
    {
        Captcha::configure(['length' => 4, 'ttl' => 60]);

        $config = Captcha::instance()->config();

        self::assertSame(4, $config->length);
        self::assertSame(60, $config->ttl);
    }

    public function testConfigureAcceptsAConfigInstance(): void
    {
        Captcha::configure(Config::forStrict());

        $config = Captcha::instance()->config();

        self::assertSame(6, $config->length);
        self::assertTrue($config->honeypot);
        self::assertSame(220, $config->width);
    }

    public function testConfigureWithEmptyArrayFallsBackToDiscovery(): void
    {
        /*
        *  El config hermético del setUp devuelve []: tras liberar la
        *  configuración manual mandan los defaults del constructor.
        */
        Captcha::configure(['length' => 4]);
        Captcha::configure([]);

        self::assertSame(Config::DEFAULT_LENGTH, Captcha::instance()->config()->length);
    }

    public function testConfigureIsIdempotentUntilTheNextCall(): void
    {
        Captcha::configure(['preset' => 'login']);

        $first = Captcha::instance();
        $second = Captcha::instance();

        self::assertSame($first, $second);
        self::assertSame(5, $first->config()->length);
    }

    public function testConfigureSupportsPresetsAndOverrides(): void
    {
        Captcha::configure(['preset' => 'login', 'length' => 7]);

        $config = Captcha::instance()->config();

        self::assertSame(7, $config->length);
        self::assertSame(200, $config->width);
        self::assertFalse($config->noise);
    }

    public function testConfigureSurvivesReset(): void
    {
        Captcha::configure(['length' => 8]);
        Captcha::reset();

        self::assertSame(8, Captcha::instance()->config()->length);
    }

    public function testConfiguredWidgetAndFormShareTheInstance(): void
    {
        /*
        *  generateAttempts a 0: en CLI no hay REMOTE_ADDR y el limiter
        *  fail-closed rechazaría generate() (techo ya cubierto en PresetTest).
        */
        Captcha::configure(['preset' => 'login', 'generateAttempts' => 0]);

        $html = Captcha::widget();

        self::assertStringContainsString('maxlength="5"', $html);
        self::assertStringContainsString('width="200"', $html);
    }

    public function testConfigureRejectsInvalidOptions(): void
    {
        $this->expectException(\Captcha\Exception\InvalidConfigException::class);
        $this->expectExceptionMessage("'length' debe ser un entero");

        Captcha::configure(['length' => 'mucho']);
        Captcha::instance();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$sharedConfigFile !== null) {
            @unlink(self::$sharedConfigFile);
            self::$sharedConfigFile = null;
        }
    }
}
