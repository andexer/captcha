<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Config;

use Captcha\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Las 8 plantillas de install se generan con tools/render-templates.php
 * (siete inyectan el fragmento compartido, src/app/Config/fragments/options.php,
 * y Janssen lleva el suyo): los ficheros distribuidos deben igualar la salida
 * del generador, de modo que editar el fragmento (o los descriptores por
 * framework) sin regenerar falle de forma ruidosa en lugar de distribuir
 * documentación obsoleta.
 *
 * La segunda puerta mide lo que la primera no puede ver: el generador solo
 * demuestra que el fragmento y las plantillas dicen lo mismo, no que digan
 * TODO lo que el paquete acepta. Sin esta comprobación, una opción nueva en
 * Config quedaría documentada en ninguna parte y seguiría en verde.
 */
final class TemplateRenderTest extends TestCase
{
    public function testShippedTemplatesMatchTheGenerator(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['plain', 'codeigniter', 'laravel', 'symfony', 'cakephp', 'yii', 'yii3', 'janssen'] as $framework) {
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

    public function testEveryConfigKeyIsDocumentedInTheFragment(): void
    {
        $fragment = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/app/Config/fragments/options.php',
        );

        preg_match_all("/^\\s*\\/\\/ '([A-Za-z]+)'\s*=>/m", $fragment, $coincidencias);

        $documentadas = $coincidencias[1];

        self::assertSame(
            [],
            array_values(array_diff(Config::KEYS, $documentadas)),
            'Claves de Config ausentes en el fragmento: la plantilla instalada deja de documentarlas.',
        );

        self::assertSame(
            [],
            array_values(array_diff($documentadas, Config::KEYS)),
            'Claves documentadas en el fragmento que Config no acepta: la opción ya no existe.',
        );
    }
}
