<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Runtime;

use Captcha\Config\Config;
use Captcha\Runtime\Host;
use Captcha\Runtime\StorageResolver;
use Captcha\Storage\ArrayStorage;
use Captcha\Storage\FileStorage;
use Captcha\Storage\SessionStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La elección del backend de retos vive en un único punto (StorageResolver)
 * que comparten la capa estática y el armado a mano de Captcha: aquí se
 * cubre la tabla completa, incluida la rama "auto" según el host, que antes
 * estaba oculta dentro de StaticLayer y solo se alcanzaba por la fachada.
 */
final class StorageResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Host::forceFrameworkSession(null);
    }

    #[DataProvider('forcedProvider')]
    public function testForcedStorageIgnoresTheHost(string $valor, string $esperado): void
    {
        Host::forceFrameworkSession(true);

        self::assertInstanceOf($esperado, StorageResolver::resolve(new Config(storage: $valor)));
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function forcedProvider(): iterable
    {
        yield 'session' => ['session', SessionStorage::class];
        yield 'array' => ['array', ArrayStorage::class];
        yield 'file' => ['file', FileStorage::class];
    }

    public function testAutoResolvesToFilesUnderAFrameworkSessionHost(): void
    {
        Host::forceFrameworkSession(true);

        self::assertInstanceOf(FileStorage::class, StorageResolver::resolve(new Config()));
    }

    public function testAutoResolvesToSessionWithoutAFrameworkSessionHost(): void
    {
        Host::forceFrameworkSession(false);

        self::assertInstanceOf(SessionStorage::class, StorageResolver::resolve(new Config()));
    }
}
