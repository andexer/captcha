<?php

declare(strict_types=1);

namespace Captcha\Tests\Unit\Console;

use Captcha\Console\Integration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Puerta del pegamento que imprime `captcha install`.
 *
 * El bin solo escribe sus ficheros y delega en quien lo instaló: lo que dice
 * en las notas es toda la ayuda que va a recibir quien instala, así que una
 * instrucción equivocada aquí es un captcha que no llega a filtrar ni a
 * responder en la app anfitriona. Congela tres verdades que ya han costado:
 * el filtro de CodeIgniter necesita $methods además del alias, el middleware
 * de CakePHP cuelga de $middlewareQueue (no de $middleware) y el
 * comportamiento de Yii2 va con prefijo 'as' ('behaviors' a secas lanza
 * InvalidCallException en Component::__set).
 *
 * La cuarta es la del widget: su endpoint por defecto es /captcha/endpoint y
 * ninguna ruta que emitimos la ocupa, así que quien dibuja el widget tiene
 * que declarar la suya, sea en las notas o en la plantilla que recibe.
 */
final class IntegrationNotesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function notesProvider(): iterable
    {
        yield 'plain' => ['plain', ['CaptchaGuard::enforce', "Captcha::widget(['endpoint' => '/captcha.php'])"]];
        yield 'codeigniter' => ['codeigniter', ['$aliases', '$methods', "'POST' => ['captcha']"]];
        yield 'laravel' => ['laravel', ['$middleware->append', 'Route::get']];
        yield 'symfony' => ['symfony', ['config/routes/*', "Captcha::widget(['endpoint' => '/captcha/generate'])"]];
        yield 'cakephp' => ['cakephp', ['$middlewareQueue->add', "'controller' => 'Captcha'"]];
        yield 'yii' => ['yii', ["'as captcha'", 'enablePrettyUrl']];
        yield 'yii3' => ['yii3', ['Route::get', "Captcha::widget(['endpoint' => '/captcha/generate'])"]];
        yield 'janssen' => ['janssen', ["'preprocessors'", "'\\App\\Preprocessor\\CaptchaGuard', 'POST'"]];
    }

    /**
     * @param list<string> $esperados
     */
    #[DataProvider('notesProvider')]
    public function testNotesCarryTheRegistrationSnippetsTheFrameworkNeeds(string $framework, array $esperados): void
    {
        $notas = self::notes($framework);

        foreach ($esperados as $fragmento) {
            self::assertStringContainsString($fragmento, $notas, $framework . ': falta ' . $fragmento);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frameworkProvider(): iterable
    {
        foreach (array_keys(Integration::PER_FRAMEWORK) as $framework) {
            yield $framework => [$framework];
        }
    }

    #[DataProvider('frameworkProvider')]
    public function testNotesAndTemplatesNeverSuggestWaysThatDoNotWork(string $framework): void
    {
        $texto = self::notes($framework) . "\n" . self::templates($framework);

        self::assertStringNotContainsString('$middleware->add(', $texto, $framework);
        self::assertStringNotContainsString("'behaviors' =>", $texto, $framework);
    }

    #[DataProvider('frameworkProvider')]
    public function testEveryFrameworkShowsHowToAimTheWidgetAtItsRoute(string $framework): void
    {
        $texto = self::notes($framework) . "\n" . self::templates($framework);

        self::assertStringContainsString("endpoint' => ", $texto, $framework);
    }

    private static function notes(string $framework): string
    {
        $lineas = [];

        foreach (Integration::PER_FRAMEWORK[$framework] as $artefacto) {
            $lineas = [...$lineas, ...$artefacto['notes']];
        }

        return implode("\n", $lineas);
    }

    private static function templates(string $framework): string
    {
        $contenido = '';

        foreach (Integration::PER_FRAMEWORK[$framework] as $artefacto) {
            $ruta = dirname(__DIR__, 3) . '/src/app/Integration/templates/' . $artefacto['template'];
            $contenido .= (string) file_get_contents($ruta);
        }

        return $contenido;
    }
}
