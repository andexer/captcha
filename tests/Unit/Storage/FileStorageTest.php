<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Storage;

use Captcha\Exception\StorageException;
use Captcha\Storage\FileStorage;
use PHPUnit\Framework\TestCase;

final class FileStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/captcha-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    public function testCreatesDirectoryWhenMissing(): void
    {
        $storage = new FileStorage($this->directory . '/nested');

        self::assertDirectoryExists($this->directory . '/nested');
        $storage->put('id-1', '12345', 300);
        self::assertSame('12345', $storage->get('id-1'));
    }

    public function testPutAndGetRoundTrip(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '98765', 300);

        self::assertSame('98765', $storage->get('id-1'));
        self::assertTrue($storage->has('id-1'));
    }

    public function testGetReturnsNullForMissingId(): void
    {
        $storage = new FileStorage($this->directory);

        self::assertNull($storage->get('missing'));
        self::assertFalse($storage->has('missing'));
    }

    public function testForgetRemovesEntry(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 300);
        $storage->forget('id-1');

        self::assertNull($storage->get('id-1'));
        self::assertFalse($storage->has('id-1'));
    }

    public function testForgetMissingEntryIsNoOp(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->forget('never-stored');

        self::assertFalse($storage->has('never-stored'));
    }

    public function testTtlZeroExpiresImmediately(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 0);

        self::assertNull($storage->get('id-1'));
        self::assertFalse($storage->has('id-1'), 'El fichero caducado debe borrarse al leer');
    }

    public function testCorruptFileIsCollected(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 300);

        // Se simula una corrupción en disco.
        $files = glob($this->directory . '/*.captcha.json');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);
        file_put_contents($files[0], 'not-json');

        self::assertNull($storage->get('id-1'));
        self::assertFalse($storage->has('id-1'));
    }

    public function testConsumeReturnsCodeAndRemovesEntry(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 300);

        self::assertSame('12345', $storage->consume('id-1'));
        self::assertFalse($storage->has('id-1'), 'consume() debe eliminar la entrada');
        self::assertEmpty(glob($this->directory . '/*.captcha.json') ?: []);
    }

    public function testConsumeIsIdempotent(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 300);

        self::assertSame('12345', $storage->consume('id-1'));
        self::assertNull($storage->consume('id-1'), 'Un segundo consume no debe obtener el código otra vez');
    }

    public function testConsumeReturnsNullForMissingId(): void
    {
        $storage = new FileStorage($this->directory);

        self::assertNull($storage->consume('missing'));
        self::assertFalse($storage->has('missing'));
    }

    public function testConsumeExpiredEntryReturnsNullAndCollects(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 0);

        self::assertNull($storage->consume('id-1'));
        self::assertFalse($storage->has('id-1'), 'La entrada caducada debe recogerse en consume()');
    }

    public function testConsumeCorruptFileReturnsNullAndCollects(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 300);

        $files = glob($this->directory . '/*.captcha.json');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);
        file_put_contents($files[0], 'not-json');

        self::assertNull($storage->consume('id-1'));
        self::assertFalse($storage->has('id-1'));
    }

    public function testStringExpiryShapeIsCollected(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 300);

        $files = glob($this->directory . '/*.captcha.json');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);
        file_put_contents($files[0], json_encode(['code' => '12345', 'expires' => 'soon']));

        self::assertNull($storage->get('id-1'));
        self::assertFalse($storage->has('id-1'));
    }

    public function testNonExpiringButMalformedShapeIsCollected(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 300);

        $files = glob($this->directory . '/*.captcha.json');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);
        file_put_contents($files[0], json_encode(['code' => 12345, 'expires' => 9_999_999_999]));

        self::assertNull($storage->get('id-1'));
        self::assertFalse($storage->has('id-1'));
    }

    public function testRejectsPathThatIsAFile(): void
    {
        $file = $this->directory . '-as-file';
        file_put_contents($file, 'x');

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessage('no es un directorio');

            new FileStorage($file);
        } finally {
            unlink($file);
        }
    }

    public function testPutFailsWhenDirectoryBecomesUnwritable(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Permission bits do not apply to root.');
        }

        $storage = new FileStorage($this->directory);
        chmod($this->directory, 0o555);

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessage('no es escribible');

            $storage->put('id-1', '12345', 300);
        } finally {
            chmod($this->directory, 0o755);
        }
    }

    public function testGetFailsWhenFileIsUnreadable(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Permission bits do not apply to root.');
        }

        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 300);

        $files = glob($this->directory . '/*.captcha.json');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);
        chmod($files[0], 0o000);

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessage('No se pudo leer');

            $storage->get('id-1');
        } finally {
            chmod($files[0], 0o644);
        }
    }

    /**
     * Las entradas caducadas no deben acumularse para siempre.
     *
     * Un reto se elimina cuando alguien lo verifica, pero un cliente que pide
     * un captcha y jamás lo envía deja su fichero ahí: la entrada caduca
     * lógicamente y nada la recolectó nunca. Sin barrido, eso es una fuga de
     * inodos sin límite en el directorio de almacenamiento — trivialmente
     * alcanzable a través del endpoint público, y un problema real de
     * memoria cuando /tmp es un tmpfs. put() ahora barre de forma oportunista.
     */
    public function testExpiredEntriesAreSweptInsteadOfAccumulating(): void
    {
        $storage = new FileStorage($this->directory);

        // Lote 1: retos ya caducados (TTL negativo) que nadie verificará.
        for ($i = 0; $i < 200; $i++) {
            $storage->put('stale-' . $i, '12345', -1);
        }

        self::assertGreaterThan(100, count($this->files()), 'el lote caducado debe existir antes de barrer');

        /*
        *  Lote 2: una instancia nueva (sin cooldown) dispara el barrido al
        *  superar el umbral, como haría la siguiente petición.
        */
        $storage = new FileStorage($this->directory);
        $storage->put('fresh', '54321', 300);

        $remaining = $this->files();

        // Los caducados se recogen al barrer, el vivo sobrevive.
        self::assertLessThan(
            20,
            count($remaining),
            'los retos caducados deben barrerse al escribir, no acumularse',
        );
        self::assertNotNull($this->codeOf('fresh'), 'el reto vigente nunca se borra por el barrido');
    }

    /**
     * El barrido jamás debe tocar un reto vivo, sea cual sea el volumen, y el
     * cooldown no debe dejar que el directorio crezca sin límite.
     */
    public function testSweepKeepsEveryLiveEntry(): void
    {
        $storage = new FileStorage($this->directory);

        for ($i = 0; $i < 100; $i++) {
            $storage->put('live-' . $i, '12345', 300);
        }

        for ($i = 0; $i < 100; $i++) {
            $storage->put('stale-' . $i, '12345', -1);
        }

        $storage = new FileStorage($this->directory);
        $storage->put('trigger', '12345', 300);

        for ($i = 0; $i < 100; $i++) {
            self::assertTrue($storage->has('live-' . $i), sprintf('el reto vivo live-%d no debe borrarse', $i));
        }
    }

    public function testForgetFailsWhenDirectoryUnwritable(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Permission bits do not apply to root.');
        }

        $storage = new FileStorage($this->directory);
        $storage->put('id-1', '12345', 300);
        chmod($this->directory, 0o555);

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessage('No se pudo eliminar');

            $storage->forget('id-1');
        } finally {
            chmod($this->directory, 0o755);
        }
    }

    public function testRejectsUnwritableDirectory(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Permission bits do not apply to root.');
        }

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('no es escribible');

        $dir = sys_get_temp_dir() . '/captcha-ro-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o555);

        try {
            new FileStorage($dir);
        } finally {
            chmod($dir, 0o755);
            rmdir($dir);
        }
    }

    /**
     * @return list<string> Rutas absolutas de los ficheros almacenados.
     */
    private function files(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = glob($this->directory . '/*');
        if ($files === false) {
            return [];
        }

        return $files;
    }

    /**
     * Lee de vuelta un código almacenado a través del fichero nombrado por
     * hash, o null.
     */
    private function codeOf(string $id): ?string
    {
        $path = $this->directory . '/' . hash('sha256', $id) . '.captcha.json';

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $entry = $raw === false ? null : json_decode($raw, true);

        return is_array($entry) ? ($entry['code'] ?? null) : null;
    }
}
