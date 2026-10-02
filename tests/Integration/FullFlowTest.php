<?php

declare(strict_types=1);

namespace Captcha\Tests\Integration;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Storage\ArrayStorage;
use Captcha\Storage\SessionStorage;
use Captcha\Verification\Status;
use PHPUnit\Framework\TestCase;

/**
 * Flujo extremo a extremo con el renderer GD real y almacenamiento en memoria.
 */
final class FullFlowTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is required for integration tests.');
        }
    }

    public function testGenerateAndVerifyHappyPath(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(storage: $storage, config: new Config(length: 6));

        $result = $captcha->generate();

        // El código vive solo en el storage; el cliente nunca lo ve.
        $code = $storage->get($result->getId());
        self::assertNotNull($code);

        $verification = $captcha->verify($result->getId(), $code);

        self::assertTrue($verification->isValid());
        self::assertSame(Status::Ok, $verification->getStatus());
        self::assertSame($result->getId(), $verification->getId());
    }

    public function testGeneratedImageIsValidPngWithConfiguredSize(): void
    {
        $captcha = new Captcha(
            storage: new ArrayStorage(),
            config: new Config(width: 240, height: 90),
        );

        $png = $captcha->generate()->getImage();
        $info = getimagesizefromstring($png);

        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_PNG, $info[2]);
        self::assertSame(240, $info[0]);
        self::assertSame(90, $info[1]);
    }

    public function testChallengeCannotBeReplayed(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(storage: $storage);
        $result = $captcha->generate();
        $code = $storage->get($result->getId());
        self::assertNotNull($code);

        self::assertSame(Status::Ok, $captcha->verify($result->getId(), $code)->getStatus());
        self::assertSame(Status::Missing, $captcha->verify($result->getId(), $code)->getStatus());
    }

    public function testWrongAttemptConsumesChallenge(): void
    {
        $storage = new ArrayStorage();
        $captcha = new Captcha(storage: $storage);
        $result = $captcha->generate();

        self::assertSame(
            Status::Invalid,
            $captcha->verify($result->getId(), '99999')->getStatus(),
        );
        self::assertSame(
            Status::Missing,
            $captcha->verify($result->getId(), $result->getId())->getStatus(),
        );
    }

    public function testHtmlOutputEmbedsDataUri(): void
    {
        $captcha = new Captcha(storage: new ArrayStorage());
        $html = $captcha->generate()->getHtml('Soluciona el captcha');

        self::assertStringStartsWith('<img src="data:image/png;base64,', $html);
        self::assertStringContainsString('alt="Soluciona el captcha"', $html);
    }

    public function testSessionStorageBackedFlowIsSingleUse(): void
    {
        if (PHP_SAPI !== 'cli') {
            self::markTestSkipped('El flujo de sesión solo se ejercita desde el runner de CLI.');
        }

        /*
        *  Guard: sin save_path configurado session_start() es un no-op
        *  silencioso (status NONE) y el flujo fallaría antes de tocar el
        *  captcha. Si el runner ya abrió una sesión (p. ej. la suite Unit
        *  con SessionStorageTest la dejó activa) es porque hay un destino
        *  escribible: entonces baste con usarla; cambiar el save_path a
        *  mitad de vuelta es un warning que rompería failOnWarning.
        */
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.save_path', sys_get_temp_dir());
        }

        $captcha = new Captcha(storage: new SessionStorage(), config: new Config(length: 6));

        $result = $captcha->generate();
        $code = $captcha->storage()->get($result->getId());
        self::assertNotNull($code, 'El código debe persistir en la sesión real');

        self::assertSame(Status::Ok, $captcha->verify($result->getId(), (string) $code)->getStatus());
        self::assertSame(
            Status::Missing,
            $captcha->verify($result->getId(), (string) $code)->getStatus(),
            'La misma sesión no puede reutilizar un reto ya consumido',
        );
    }
}
