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
 *
 * Además congela la forma de cada bloque de notes: la primera línea es la
 * ruta del fichero host que hay que editar —sin espacios y con barra—, va
 * seguida de una línea en blanco, y el grupo entero cabe en 18 líneas: lo que
 * no cabe vive en docs/, no en la terminal.
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

    /**
     * La primera línea de cada bloque es SIEMPRE la ruta del fichero host que
     * hay que editar: sin espacios, con barra y sin puntuación final, para que
     * el renderer pueda pintarla en acento y el ojo la encuentre sin leer.
     */
    #[DataProvider('frameworkProvider')]
    public function testEveryNoteGroupStartsWithTheHostFilePathToEdit(string $framework): void
    {
        foreach (Integration::PER_FRAMEWORK[$framework] as $artefacto) {
            $primera = $artefacto['notes'][0] ?? '';

            self::assertMatchesRegularExpression(
                '#^[A-Za-z0-9_./-]+/[A-Za-z0-9_.-]+$#',
                $primera,
                $framework . ': la primera línea de ' . $artefacto['path'] . ' debe ser una ruta',
            );
            self::assertSame('', $artefacto['notes'][1] ?? '?', $framework . ': tras la ruta va una línea en blanco');
        }
    }

    /**
     * Presupuesto de las notas: lo que no cabe aquí vive en docs/, no en la
     * terminal. El grupo más largo hoy mide 12; 18 deja holgura sin permitir
     * que vuelvan los párrafos que este rediseño recortó.
     */
    #[DataProvider('frameworkProvider')]
    public function testNoteGroupsStayShortEnoughToReadOnATerminal(string $framework): void
    {
        $presupuesto = 18;

        foreach (Integration::PER_FRAMEWORK[$framework] as $artefacto) {
            self::assertLessThanOrEqual(
                $presupuesto,
                count($artefacto['notes']),
                $framework . ': ' . $artefacto['path'] . ' se pasa del presupuesto de notas',
            );
        }
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
