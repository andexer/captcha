<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit;

use Captcha\Captcha;
use PHPUnit\Framework\TestCase;

/**
 * El descubrimiento del config es el contrato del que depende la capa
 * estática: gana env, luego los candidatos de raíz del proyecto anclados en
 * la ubicación de instalación del paquete (vendor/ o un symlink de un
 * path-repository), luego el directorio de trabajo.
 *
 * La suite evita aserciones dependientes del entorno: nunca asume que "no
 * exista config en ninguna parte", porque un checkout anidado (paquete dentro
 * de un proyecto con su propio app/Config) resuelve legítimamente su ancla de
 * raíz.
 */
final class CaptchaDiscoveryTest extends TestCase
{
    private ?string $configFile = null;

    protected function setUp(): void
    {
        /*
        *  Aislamiento: cualquier opción manual o instancia previa de otras
        *  clases del suite (ejecución en el mismo proceso) no debe filtrarse.
        */
        Captcha::reset();
        Captcha::configure([]);
        putenv('CAPTCHA_CONFIG');
    }

    protected function tearDown(): void
    {
        Captcha::reset();
        Captcha::configure([]);
        putenv('CAPTCHA_CONFIG');

        if ($this->configFile !== null) {
            @unlink($this->configFile);
            $this->configFile = null;
        }
    }

    public function testConfigPathFollowsEnvironmentVariable(): void
    {
        $file = $this->tempConfig('<?php return [];');

        putenv('CAPTCHA_CONFIG=' . $file);

        self::assertSame($file, Captcha::configPath());
    }

    public function testConfigPathIgnoresMissingEnvironmentFile(): void
    {
        $missing = sys_get_temp_dir() . '/captcha-does-not-exist.php';

        putenv('CAPTCHA_CONFIG=' . $missing);

        $path = Captcha::configPath();

        self::assertNotSame($missing, $path);
        self::assertTrue($path === null || is_file($path));
    }

    public function testStaticLayerLoadsEnvironmentProvidedConfig(): void
    {
        $file = $this->tempConfig('<?php return ["length" => 8];');

        putenv('CAPTCHA_CONFIG=' . $file);

        self::assertSame(8, Captcha::instance()->config()->length);
    }

    public function testBrokenConfigFileThrowsInvalidConfig(): void
    {
        $file = $this->tempConfig('<?php return "no-array";');

        putenv('CAPTCHA_CONFIG=' . $file);

        $this->expectException(\Captcha\Exception\InvalidConfigException::class);

        Captcha::instance();
    }

    private function tempConfig(string $body): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'captcha-discovery-');
        file_put_contents($file, $body);
        $this->configFile = $file;

        return $file;
    }
}
