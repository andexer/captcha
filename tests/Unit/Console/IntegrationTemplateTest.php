<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Console;

use Captcha\Console\Integration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Puerta de las plantillas de integración que emite `captcha install`.
 *
 * Estas plantillas llevan extensión .tpl a propósito, y esa extensión es
 * exactamente lo que las esconde de PHPStan y de las puertas por token: si
 * fueran .php, el análisis intentaría resolver Illuminate\*, Symfony\Component\*,
 * Cake\* y Yii\* y fallaría por clases que el paquete no instala por diseño.
 *
 * El hueco deja a este test como única red sobre código que va a escribirse en
 * la aplicación de otra gente, así que compensa con lo que el análisis
 * estático ya no cubre: que el fichero exista, que compile, que el manifiesto no
 * apunte a un destino absurdo y que toda plantilla orientada por el manifiesto
 * exista de verdad y al revés.
 *
 * php -l es una comprobación débil a propósito: dice que el fichero es PHP
 * válido, no que la clase que declara exista en el framework de destino. Esa
 * segunda mitad no se puede automatizar aquí porque el paquete no instala
 * ninguno de esos frameworks; la cubre la documentación de cada plantilla.
 */
final class IntegrationTemplateTest extends TestCase
{
    /**
     * Raíz de las plantillas, relativa a la raíz del repositorio.
     */
    private const TEMPLATES = 'src/app/Integration/templates';
    public function testEveryDeclaredTemplateExistsOnDisk(): void
    {
        foreach ($this->entries() as $framework => $artefactos) {
            foreach ($artefactos as $artefacto) {
                self::assertFileExists($this->templatePath($artefacto['template']), $framework);
            }
        }
    }

    public function testEveryTemplateOnDiskIsDeclaredInTheManifest(): void
    {
        $declaradas = [];

        foreach ($this->entries() as $artefactos) {
            foreach ($artefactos as $artefacto) {
                $declaradas[] = $artefacto['template'];
            }
        }

        $enDisco = array_map(
            static fn(string $ruta): string => substr($ruta, strlen(self::TEMPLATES) + 1),
            $this->templateFiles(),
        );

        self::assertSame([], array_diff($enDisco, $declaradas), 'plantillas huérfanas: nadie las emite');
        self::assertSame([], array_diff($declaradas, $enDisco), 'plantillas declaradas que no existen');
    }

    public function testEveryPhpTemplateCompiles(): void
    {
        foreach ($this->templateFiles() as $ruta) {
            if (!str_ends_with($ruta, '.php.tpl')) {
                continue;
            }

            exec('php -l ' . escapeshellarg($ruta), $salida, $codigo);

            self::assertSame(0, $codigo, sprintf('%s no compila: %s', basename($ruta), implode(' ', $salida)));
        }
    }

    public function testEveryPhpTemplateDeclaresStrictTypes(): void
    {
        foreach ($this->entries() as $framework => $artefactos) {
            foreach ($artefactos as $artefacto) {
                if (!str_ends_with($artefacto['template'], '.php.tpl')) {
                    continue;
                }

                $contenido = (string) file_get_contents($this->templatePath($artefacto['template']));

                self::assertStringContainsString(
                    'declare(strict_types=1);',
                    $contenido,
                    $framework . ': ' . $artefacto['path'] . ' sin declare(strict_types=1)',
                );
            }
        }
    }

    public function testDestinationsStayInsideTheHostProject(): void
    {
        foreach ($this->entries() as $framework => $artefactos) {
            foreach ($artefactos as $artefacto) {
                $path = $artefacto['path'];

                self::assertStringStartsNotWith('/', $path, $framework . ': destino absoluto');
                self::assertStringStartsNotWith('..', $path, $framework . ': destino fuera del proyecto');
                self::assertStringNotContainsString('/../', $path, $framework . ': destino fuera del proyecto');
                self::assertNotSame('', basename($path), $framework . ': destino sin nombre de fichero');
            }
        }
    }

    #[DataProvider('templateProvider')]
    public function testEmittedCodeStaysWithinItsLengthBudget(string $template): void
    {
        $bytes = strlen((string) file_get_contents($this->templatePath($template)));

        self::assertLessThan(4096, $bytes, $template . ' se ha ido de tamaño para un pegamento');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templateProvider(): iterable
    {
        foreach (Integration::PER_FRAMEWORK as $artefactos) {
            foreach ($artefactos as $artefacto) {
                yield $artefacto['template'] => [$artefacto['template']];
            }
        }
    }

    /**
     * @return array<string, list<array{path: string, template: string, notes: list<string>}>>
     */
    private function entries(): array
    {
        return Integration::PER_FRAMEWORK;
    }

    private function templatePath(string $template): string
    {
        return dirname(__DIR__, 3) . '/' . self::TEMPLATES . '/' . $template;
    }

    /**
     * @return list<string> Rutas relativas a la raíz del repositorio.
     */
    private function templateFiles(): array
    {
        $base = dirname(__DIR__, 3) . '/' . self::TEMPLATES;
        $encontradas = glob($base . '/*/*') ?: [];
        $rutas = [];

        foreach ($encontradas as $ruta) {
            $rutas[] = self::TEMPLATES . '/' . basename(dirname($ruta)) . '/' . basename($ruta);
        }

        sort($rutas);

        return $rutas;
    }
}
