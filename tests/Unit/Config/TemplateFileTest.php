<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Config;

use Captcha\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * El config de la demo (src/app/Config/captcha.php) hace de escaparate del
 * formato: debe seguir siendo un fichero PHP sintácticamente válido que
 * devuelve un array, y ese array tiene que ser config aceptable en modo
 * estricto — una clave retirada o un valor fuera de rango en el escaparate
 * enseñaría a copiar un patrón que el paquete rechaza.
 *
 * @internal
 */
final class TemplateFileTest extends TestCase
{
    public function testShippedTemplateReturnsAnArray(): void
    {
        $path = dirname(__DIR__, 3) . '/src/app/Config/captcha.php';

        self::assertFileExists($path);

        $loaded = require $path;

        self::assertIsArray($loaded);
    }

    public function testActiveArrayIsAValidStrictConfig(): void
    {
        $path = dirname(__DIR__, 3) . '/src/app/Config/captcha.php';

        $loaded = require $path;

        self::assertIsArray($loaded);

        $config = Config::fromArray($loaded);

        self::assertSame($loaded['length'], $config->length);
    }
}
