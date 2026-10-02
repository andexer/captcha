<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Storage;

use Captcha\Storage\SessionStorage;
use PHPUnit\Framework\TestCase;

final class SessionStorageTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testPutAndGetRoundTrip(): void
    {
        $storage = new SessionStorage();
        $storage->put('id-1', '12345', 300);

        self::assertSame('12345', $storage->get('id-1'));
        self::assertTrue($storage->has('id-1'));
    }

    public function testEntriesAreIsolatedByNamespace(): void
    {
        $a = new SessionStorage('ns_a');
        $b = new SessionStorage('ns_b');

        $a->put('id-1', '11111', 300);

        self::assertTrue($a->has('id-1'));
        self::assertFalse($b->has('id-1'));
    }

    public function testGetReturnsNullForMissingId(): void
    {
        $storage = new SessionStorage();

        self::assertNull($storage->get('missing'));
        self::assertFalse($storage->has('missing'));
    }

    public function testForgetRemovesEntry(): void
    {
        $storage = new SessionStorage();
        $storage->put('id-1', '12345', 300);
        $storage->forget('id-1');

        self::assertNull($storage->get('id-1'));
        self::assertFalse($storage->has('id-1'));
    }

    public function testForgetMissingEntryIsNoOp(): void
    {
        $storage = new SessionStorage();
        $storage->forget('never-stored');

        self::assertFalse($storage->has('never-stored'));
    }

    public function testTtlZeroExpiresImmediately(): void
    {
        $storage = new SessionStorage();
        $storage->put('id-1', '12345', 0);

        self::assertNull($storage->get('id-1'));
    }

    public function testConstructorDoesNotStartSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }

        new SessionStorage();

        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testFirstAccessStartsSessionLazily(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }

        $storage = new SessionStorage();
        self::assertSame(PHP_SESSION_NONE, session_status());

        $storage->put('id-1', '12345', 300);

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testConstructorKeepsAlreadyActiveSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        new SessionStorage();

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testRecoversFromCorruptedNamespace(): void
    {
        $_SESSION['_captcha'] = 'not-an-array';

        $storage = new SessionStorage();
        $storage->put('id-1', '12345', 300);

        self::assertSame('12345', $storage->get('id-1'));
    }

    public function testRecoversFromCorruptedEntry(): void
    {
        $_SESSION['_captcha'] = [
            'id-1' => 'not-an-array',
            'id-2' => ['nope'],
            'id-3' => ['code' => 42, 'expires' => 9_999_999_999],
            'id-4' => ['code' => '12345', 'expires' => 'soon'],
        ];

        $storage = new SessionStorage();

        foreach (['id-1', 'id-2', 'id-3', 'id-4'] as $id) {
            self::assertNull($storage->get($id), 'Una entrada corrupta no debe romper ni filtrar un código');
            self::assertFalse($storage->has($id), 'La entrada corrupta debe eliminarse');
        }
    }

    public function testConsumeReturnsCodeAndRemovesEntry(): void
    {
        $storage = new SessionStorage();
        $storage->put('id-1', '12345', 300);

        self::assertSame('12345', $storage->consume('id-1'));
        self::assertFalse($storage->has('id-1'), 'consume() debe eliminar la entrada');
    }

    public function testConsumeIsIdempotent(): void
    {
        $storage = new SessionStorage();
        $storage->put('id-1', '12345', 300);

        self::assertSame('12345', $storage->consume('id-1'));
        self::assertNull($storage->consume('id-1'), 'Un segundo consume no debe obtener el código otra vez');
    }

    public function testConsumeExpiredEntryReturnsNullAndCollects(): void
    {
        $storage = new SessionStorage();
        $storage->put('id-1', '12345', 0);

        self::assertNull($storage->consume('id-1'));
        self::assertFalse($storage->has('id-1'));
    }
}
