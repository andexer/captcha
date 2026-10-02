<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * Las 7 plantillas de install se generan desde el fragmento compartido
 * (src/app/Config/fragments/options.php) mediante tools/render-templates.php:
 * los ficheros distribuidos deben igualar la salida del generador, de modo
 * que editar el fragmento (o los descriptores por framework) sin regenerar
 * falle de forma ruidosa en lugar de distribuir documentación obsoleta.
 */
final class TemplateRenderTest extends TestCase
{
    public function testShippedTemplatesMatchTheGenerator(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['plain', 'codeigniter', 'laravel', 'symfony', 'cakephp', 'yii', 'janssen'] as $framework) {
            $path = $root . '/src/app/Config/templates/' . $framework . '.php';
            $before = (string) file_get_contents($path);

            // Regenera in situ y compara; restaura aunque falle.
            try {
                exec('php ' . escapeshellarg($root . '/tools/render-templates.php'), $out, $code);

                self::assertSame(0, $code, 'El generador de plantillas debe correr sin errores.');

                $after = (string) file_get_contents($path);

                self::assertSame(
                    $before,
                    $after,
                    "{$framework}.php está desactualizado: edita src/app/Config/fragments/options.php (o tools/render-templates.php) y corre `composer config:templates`.",
                );
            } finally {
                file_put_contents($path, $before);
            }
        }
    }
}
