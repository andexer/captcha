<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Runtime;

use Captcha\Runtime\Host;
use PHPUnit\Framework\TestCase;

final class HostTest extends TestCase
{
    protected function setUp(): void
    {
        Host::forceFrameworkSession(null);
        Host::forceWeb(null);
    }

    protected function tearDown(): void
    {
        Host::forceFrameworkSession(null);
        Host::forceWeb(null);
    }

    public function testAutoDetectionIsFalseWithoutAStackedFramework(): void
    {
        /*
        *  La suite corre en CLI/phpunit sin CodeIgniter, Laravel ni Symfony
        *  cargados: la detección (class_exists sin autoload) no debe dispararse.
        */
        self::assertFalse(Host::frameworkSessionManaged());
    }

    public function testOverrideForcesFrameworkSession(): void
    {
        Host::forceFrameworkSession(true);

        self::assertTrue(Host::frameworkSessionManaged());
    }

    public function testOverrideForcesPlainSessionHost(): void
    {
        Host::forceFrameworkSession(false);

        self::assertFalse(Host::frameworkSessionManaged());
    }

    public function testNullOverrideRestoresAutoDetection(): void
    {
        Host::forceFrameworkSession(true);
        Host::forceFrameworkSession(null);

        self::assertFalse(Host::frameworkSessionManaged());
    }

    public function testSessionHostsListEverySupportedFrameworkKernel(): void
    {
        /*
        *  Los seis kernels tienen que estar en la constante: si alguien
        *  borra uno de los tres "caros" (CodeIgniter, Laravel, Symfony) nada
        *  lo notaría, porque la suite no los tiene cargados — aquí se afirma
        *  la lista completa, no solo la parte que la detección puede ver.
        */
        $constant = new \ReflectionClassConstant(Host::class, 'SESSION_HOSTS');
        $hosts = $constant->getValue();

        self::assertIsArray($hosts);
        self::assertContains(\CodeIgniter\CodeIgniter::class, $hosts);
        self::assertContains(\Illuminate\Foundation\Application::class, $hosts);
        self::assertContains(\Symfony\Component\HttpKernel\Kernel::class, $hosts);
        self::assertContains(\Cake\Core\Application::class, $hosts);
        self::assertContains(\yii\BaseYii::class, $hosts);
        self::assertContains(\Yiisoft\Yii\Http\Application::class, $hosts);
        self::assertCount(6, $hosts);
    }

    public function testIsWebIsFalseUnderTheCliSapi(): void
    {
        /*
        *  La suite corre bajo CLI, así que la detección automática da false:
        *  es la rama que el paquete trata como "no hay cliente remoto".
        */
        self::assertFalse(Host::isWeb());
    }

    public function testForceWebOverridesTheSapiDetection(): void
    {
        Host::forceWeb(true);
        self::assertTrue(Host::isWeb());

        Host::forceWeb(false);
        self::assertFalse(Host::isWeb());
    }

    public function testNullWebOverrideRestoresAutoDetection(): void
    {
        Host::forceWeb(true);
        Host::forceWeb(null);

        self::assertFalse(Host::isWeb());
    }
}
