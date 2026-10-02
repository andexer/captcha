<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * El filtro de cobertura es una decisión, no un detalle de configuración: es lo
 * que separa "el paquete está probado" de "el paquete tiene recetas y
 * plantillas en la carpeta de al lado". En cuanto deja de apuntar a lo que
 * dice, la puerta de tools/coverage.php sigue verde midiendo otra cosa, y el
 * número que sale en el pull request miente sin que nadie lo note.
 *
 * Por eso se comprueba que el alcance sea el pactado. src/examples y src/public
 * ya no se distribuyen (excluidos en .gitattributes) pero se mantienen en el
 * repo para desarrollo, por eso siguen excluidos de la cobertura.
 */
final class CoverageScopeTest extends TestCase
{
    private const INCLUIDOS = ['src'];

    private const EXCLUIDOS = [
        'src/app',        // plantillas y fragmentos de configuración: se copian, no se ejecutan
        'src/examples',   // recetas y demos: ya no se distribuyen (excluidos en .gitattributes)
        'src/public',     // drop-in del endpoint: ya no se distribuye (excluido en .gitattributes)
    ];

    public function testTheCoverageSourceIsScopedToProduction(): void
    {
        $xml = $this->configXml();

        self::assertSame(self::INCLUIDOS, $xml['incluidos'] ?? [], 'la cobertura debe entrar por src');
        self::assertSame(self::EXCLUIDOS, $xml['excluidos'] ?? [], 'el alcance de producción cambió');
    }

    public function testTheCoverageGateIsWiredToComposerAndIsNotSilentlyDisabled(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        self::assertIsArray($composer);
        self::assertArrayHasKey('test:coverage', $composer['scripts']);
        self::assertSame(
            ['@php vendor/bin/phpunit --coverage-text --coverage-clover coverage/clover.xml', '@php tools/coverage.php coverage/clover.xml 90'],
            $composer['scripts']['test:coverage'],
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private static function configXml(): array
    {
        $xml = new \SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 2) . '/phpunit.xml.dist'));

        $paths = static function (\SimpleXMLElement $node): array {
            $paths = [];

            foreach ($node->directory as $directory) {
                $paths[] = (string) $directory;
            }

            sort($paths);

            return $paths;
        };

        return [
            'incluidos' => $paths($xml->source->include),
            'excluidos' => $paths($xml->source->exclude),
        ];
    }
}
