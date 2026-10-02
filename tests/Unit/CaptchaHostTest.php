<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit;

use Captcha\Captcha;
use Captcha\Runtime\Host;
use Captcha\Storage\FileStorage;
use Captcha\Storage\SessionStorage;
use PHPUnit\Framework\TestCase;

/**
 * El storage out-of-the-box nunca debe preemptar al gestor de sesiones de un
 * framework que es dueño de las sesiones PHP (CodeIgniter, Laravel,
 * Symfony...). El host se simula con Host::forceFrameworkSession(); una
 * ejecución PHP/CLI normal debe conservar el comportamiento histórico de
 * SessionStorage.
 */
final class CaptchaHostTest extends TestCase
{
    private static ?string $sharedConfigFile = null;

    protected function setUp(): void
    {
        if (self::$sharedConfigFile === null) {
            $file = (string) tempnam(sys_get_temp_dir(), 'captcha-host-config-');
            file_put_contents($file, '<?php return [];');
            self::$sharedConfigFile = $file;
        }

        /*
        *  La sesión puede haber quedado activa por otra clase del suite (p. ej.
        *  los tests de SessionStorage): cada test arranca desde PHP_SESSION_NONE.
        */
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }

        Host::forceFrameworkSession(null);
        Captcha::reset();
        Captcha::configure([]);
        putenv('CAPTCHA_CONFIG=' . self::$sharedConfigFile);
    }

    protected function tearDown(): void
    {
        // Una sesión abierta por el propio test no debe filtrarse al siguiente.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }

        Host::forceFrameworkSession(null);
        Captcha::reset();
        Captcha::configure([]);
        putenv('CAPTCHA_CONFIG=' . self::$sharedConfigFile);
    }

    public function testStorageIsFileBasedUnderFrameworkHost(): void
    {
        Host::forceFrameworkSession(true);

        self::assertInstanceOf(FileStorage::class, Captcha::instance()->storage());
    }

    public function testWidgetDoesNotStartSessionUnderFrameworkHost(): void
    {
        Host::forceFrameworkSession(true);

        Captcha::widget();

        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testWidgetPersistsChallengeToFilesUnderFrameworkHost(): void
    {
        Host::forceFrameworkSession(true);

        Captcha::widget();

        self::assertNotEmpty(
            glob(sys_get_temp_dir() . '/captcha/*.captcha.json') ?: [],
            'El reto generado por el widget debe persistirse en FileStorage.',
        );
    }

    public function testVerifyRoundTripOverAutoFileStorage(): void
    {
        Host::forceFrameworkSession(true);

        $html = Captcha::widget();

        self::assertMatchesRegularExpression(
            '/name="captcha_id" value="[a-f0-9]{32}"/',
            $html,
            'El widget debe transportar el id del reto en el campo oculto.',
        );

        preg_match('/name="captcha_id" value="([a-f0-9]{32})"/', $html, $matches);
        $id = $matches[1];

        $file = sys_get_temp_dir() . '/captcha/' . hash('sha256', $id) . '.captcha.json';
        self::assertFileExists($file, 'El reto debe existir como fichero JSON.');

        $payload = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($payload);
        self::assertIsString($payload['code'] ?? null);

        $verification = Captcha::instance()->verify($id, $payload['code']);

        self::assertTrue($verification->isValid());
    }

    public function testAutoStorageResolvesToSessionOutsideFramework(): void
    {
        Host::forceFrameworkSession(false);

        self::assertInstanceOf(SessionStorage::class, Captcha::instance()->storage());
    }

    public function testWidgetStartsSessionOutsideFramework(): void
    {
        Host::forceFrameworkSession(false);

        Captcha::widget();

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testExplicitFileStorageForcesFileBackendOutsideFramework(): void
    {
        Host::forceFrameworkSession(false);
        Captcha::configure(['storage' => 'file']);
        Captcha::reset();

        self::assertInstanceOf(FileStorage::class, Captcha::instance()->storage());
    }

    public function testExplicitSessionStorageForcesSessionUnderFramework(): void
    {
        Host::forceFrameworkSession(true);
        Captcha::configure(['storage' => 'session']);
        Captcha::reset();

        self::assertInstanceOf(SessionStorage::class, Captcha::instance()->storage());
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$sharedConfigFile !== null) {
            @unlink(self::$sharedConfigFile);
            self::$sharedConfigFile = null;
        }
    }
}
