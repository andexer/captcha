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

    public function testSessionHostsIncludeCakePhpAndYiiKernels(): void
    {
        $constant = new \ReflectionClassConstant(Host::class, 'SESSION_HOSTS');
        $hosts = $constant->getValue();

        self::assertIsArray($hosts);
        self::assertContains(\Cake\Core\Application::class, $hosts);
        self::assertContains(\yii\BaseYii::class, $hosts);
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
