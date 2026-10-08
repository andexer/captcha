<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Laravel;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Laravel\CaptchaServiceProvider;
use Captcha\Runtime\StaticLayer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * El proveedor de auto-discovery es la única pieza del paquete que cuelga de
 * una API de framework, así que estas pruebas fijan las dos vías que importan:
 * con config del anfitrión fija lo que este preparó, y sin él no toca nada
 * (manda el descubrimiento de la capa estática, que encontraría el mismo
 * fichero). La tercera comprueba el manifiesto: un extra.laravel.providers mal
 * apuntado no falla en ningún test del paquete, solo en la app que lo instala.
 *
 * El stub de Illuminate (tests/stubs) se carga en tests/bootstrap.php con la
 * clase-guarda, de modo que el helper config() del anfitrión existe aquí sin
 * instalar illuminate/*.
 */
final class ServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        Captcha::reset();
        Captcha::configure([]);
        unset($GLOBALS['captcha_config_prueba']);
    }

    protected function tearDown(): void
    {
        Captcha::reset();
        Captcha::configure([]);
        unset($GLOBALS['captcha_config_prueba']);
    }

    public function testBootFixesTheHostConfigInTheStaticLayer(): void
    {
        $GLOBALS['captcha_config_prueba'] = ['length' => 9, 'noise' => false];

        (new CaptchaServiceProvider())->boot();

        $config = Captcha::instance()->config();

        self::assertTrue(StaticLayer::hasManualConfig());
        self::assertSame(9, $config->length);
        self::assertFalse($config->noise);
    }

    public function testBootWithoutHostConfigLeavesDiscoveryInCharge(): void
    {
        (new CaptchaServiceProvider())->boot();

        self::assertFalse(StaticLayer::hasManualConfig(), 'sin config del anfitrión no se fija nada');
        self::assertSame(Config::DEFAULT_LENGTH, Captcha::instance()->config()->length);
    }

    /**
     * El auto-discovery de Composer lee extra.laravel.providers, así que el
     * manifiesto es la puerta: un apuntador equivocado solo estalla en la app
     * de quien instala el paquete.
     */
    public function testTheManifestPointsToTheDiscoveredProvider(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

        self::assertIsArray($manifest);
        self::assertSame([CaptchaServiceProvider::class], $manifest['extra']['laravel']['providers'] ?? null);
        self::assertTrue((new ReflectionClass(CaptchaServiceProvider::class))->isFinal(), 'regla de producción: final');
    }
}
