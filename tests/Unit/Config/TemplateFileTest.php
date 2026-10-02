<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * La plantilla de config distribuida hace a la vez de semilla del install
 * CLI y de config de la demo: debe seguir siendo un fichero PHP sintácticamente
 * válido que devuelve un array (el comando install reescribe su directiva
 * return como comentarios).
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
}
