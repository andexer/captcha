<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Storage;

use Captcha\Storage\ArrayStorage;
use PHPUnit\Framework\TestCase;

final class ArrayStorageTest extends TestCase
{
    public function testPutAndGetRoundTrip(): void
    {
        $storage = new ArrayStorage();
        $storage->put('id-1', '12345', 300);

        self::assertSame('12345', $storage->get('id-1'));
        self::assertTrue($storage->has('id-1'));
    }

    public function testGetReturnsNullForMissingId(): void
    {
        $storage = new ArrayStorage();

        self::assertNull($storage->get('nope'));
        self::assertFalse($storage->has('nope'));
    }

    public function testForgetRemovesEntry(): void
    {
        $storage = new ArrayStorage();
        $storage->put('id-1', '12345', 300);
        $storage->forget('id-1');

        self::assertNull($storage->get('id-1'));
        self::assertFalse($storage->has('id-1'));
    }

    public function testForgetMissingEntryIsNoOp(): void
    {
        $storage = new ArrayStorage();
        $storage->forget('never-stored');

        self::assertFalse($storage->has('never-stored'));
    }

    public function testEntryExpiresWithInjectedClock(): void
    {
        $now = 1_000_000;
        $storage = new ArrayStorage(clock: static function () use (&$now): int {
            return $now;
        });

        $storage->put('id-1', '12345', 60);
        $now += 59;
        self::assertSame('12345', $storage->get('id-1'), 'La entrada debe seguir viva antes de que venza el TTL');

        $now += 1;
        self::assertNull($storage->get('id-1'), 'La entrada debe caducar exactamente en el TTL');
        self::assertFalse($storage->has('id-1'), 'La entrada caducada debe recogerse perezosamente en get');
    }

    public function testExpiredEntryIsCollectedOnGet(): void
    {
        $now = 1_000_000;
        $storage = new ArrayStorage(clock: static function () use (&$now): int {
            return $now;
        });

        $storage->put('id-1', '12345', 1);
        $now += 2;

        self::assertNull($storage->get('id-1'));
        self::assertFalse($storage->has('id-1'), 'get() debe eliminar perezosamente la entrada caducada');
    }

    public function testTtlZeroExpiresImmediately(): void
    {
        $storage = new ArrayStorage();
        $storage->put('id-1', '12345', 0);

        self::assertNull($storage->get('id-1'));
    }

    public function testConsumeReturnsCodeAndRemovesEntry(): void
    {
        $storage = new ArrayStorage();
        $storage->put('id-1', '12345', 300);

        self::assertSame('12345', $storage->consume('id-1'));
        self::assertFalse($storage->has('id-1'), 'consume() debe eliminar la entrada');
    }

    public function testConsumeIsIdempotent(): void
    {
        $storage = new ArrayStorage();
        $storage->put('id-1', '12345', 300);

        self::assertSame('12345', $storage->consume('id-1'));
        self::assertNull($storage->consume('id-1'), 'Un segundo consume no debe obtener el código otra vez');
    }

    public function testConsumeReturnsNullForMissingId(): void
    {
        $storage = new ArrayStorage();

        self::assertNull($storage->consume('missing'));
        self::assertFalse($storage->has('missing'));
    }

    public function testConsumeExpiredEntryReturnsNullAndCollects(): void
    {
        $now = 1_000_000;
        $storage = new ArrayStorage(clock: static function () use (&$now): int {
            return $now;
        });

        $storage->put('id-1', '12345', 60);
        $now += 61;

        self::assertNull($storage->consume('id-1'));
        self::assertFalse($storage->has('id-1'), 'La entrada caducada debe recogerse en consume()');
    }
}
