<?php

declare(strict_types=1);

namespace Captcha\Console;

use Captcha\Exception\InvalidConfigException;

/**
 * Manifiesto de integración: qué pegamento emite `install` por framework.
 *
 * Cada framework necesita un middleware, un provider o un listener distinto, y
 * ninguno pertenece a este paquete, que no depende de ninguno. Antes ese
 * pegamento era prosa dentro de un docblock bajo src/examples/, y el peso de
 * escribirlo recaía en quien instalaba. Esta tabla invierte el reparto: el
 * instalador escribe el código, y lo que no puede automatizar —registrar un
 * provider, etiquetar un listener, dejar una ruta— queda declarado en `notes`
 * para que se imprima al final de la instalación.
 *
 * Cada `notes` sigue la misma forma, pensada para leerse en terminal: la
 * primera línea es SIEMPRE la ruta del fichero que hay que tocar (el bin la
 * resalta sin sangrar), después una línea en blanco, después el snippet
 * literal a pegar y, solo si hace falta, como mucho un par de líneas de
 * aviso. Ningún párrafo largo: el porqué vive en docs/, aquí solo lo que hay
 * que hacer.
 *
 * El instalador nunca edita un fichero que ya pertenezca a la aplicación
 * anfitriona: esos cambios pueden colisionar con lo que ya había y dejar el
 * proyecto a medias. Escribe solo ficheros propios y explica el resto.
 *
 * Vive en el código y no como fichero de datos en src/app/ a propósito. Un
 * require de un array devuelve mixed, así que PHPStan no podría verificar que
 * `template`, `path` y `notes` tienen la forma que el instalador espera; una
 * constante con su @var sí se analiza. El contenido sí vive en src/app/,
 * porque son ficheros que deben viajar al dist tal cual.
 *
 * @internal
 */
final class Integration
{
    /**
     * Framework → artefactos de pegamento, en el orden en que se emiten.
     *
     * @var array<string, list<array{path: string, template: string, notes: list<string>}>>
     */
    public const PER_FRAMEWORK = [
        'plain' => [
            [
                'path' => 'src/CaptchaGuard.php',
                'template' => 'plain/guard.php.tpl',
                'notes' => [
                    'src/CaptchaGuard.php',
                    '',
                    '    require __DIR__ . \'/src/CaptchaGuard.php\';',
                    '',
                    '    [$status, $payload] = CaptchaGuard::enforce($_POST);',
                    '    if ($status !== 200) {',
                    '        http_response_code($status);',
                    '        exit;',
                    '    }',
                ],
            ],
            [
                'path' => 'public/captcha.php',
                'template' => 'plain/endpoint.php.tpl',
                'notes' => [
                    'public/captcha.php',
                    '',
                    '    Captcha::widget([\'endpoint\' => \'/captcha.php\']);',
                    '',
                    'El widget recarga el reto contra este endpoint: sin él, el',
                    'botón de recarga se queda sin destino.',
                ],
            ],
        ],
        'codeigniter' => [
            [
                'path' => 'app/Filters/CaptchaFilter.php',
                'template' => 'codeigniter/filter.php.tpl',
                'notes' => [
                    'app/Config/Filters.php',
                    '',
                    '    public array $aliases = [',
                    '        \'captcha\' => \\App\\Filters\\CaptchaFilter::class,',
                    '    ];',
                    '',
                    '    public array $methods = [',
                    '        \'POST\' => [\'captcha\'],',
                    '    ];',
                    '',
                    'El alias solo no prende nada: $methods es lo que aplica el',
                    'filtro. Para una sola ruta, usa $filters con su \'before\'.',
                ],
            ],
            [
                'path' => 'app/Controllers/Captcha.php',
                'template' => 'codeigniter/controller.php.tpl',
                'notes' => [
                    'app/Config/Routes.php',
                    '',
                    '    $routes->get(\'captcha/generate\', \'Captcha::generate\');',
                    '',
                    'El widget no apunta por defecto a esa ruta:',
                    '',
                    '    Captcha::widget([\'endpoint\' => \'/captcha/generate\']);',
                ],
            ],
        ],
        'laravel' => [
            [
                'path' => 'app/Providers/CaptchaServiceProvider.php',
                'template' => 'laravel/provider.php.tpl',
                'notes' => [
                    'bootstrap/providers.php',
                    '',
                    '    App\\Providers\\CaptchaServiceProvider::class,',
                    '',
                    'En Laravel 9 y 10 el sitio es config/app.php, clave providers.',
                ],
            ],
            [
                'path' => 'app/Http/Middleware/CaptchaGuardMiddleware.php',
                'template' => 'laravel/middleware.php.tpl',
                'notes' => [
                    'bootstrap/app.php',
                    '',
                    '    $middleware->append(CaptchaGuardMiddleware::class);',
                    '',
                    'Laravel 9 y 10: app/Http/Kernel.php, propiedad $middleware. Para',
                    'una sola ruta, ->middleware(\'captcha\') en ese POST.',
                ],
            ],
            [
                'path' => 'app/Http/Controllers/CaptchaController.php',
                'template' => 'laravel/controller.php.tpl',
                'notes' => [
                    'routes/web.php',
                    '',
                    '    Route::get(\'/captcha/generate\', [CaptchaController::class, \'generate\']);',
                ],
            ],
        ],
        'symfony' => [
            [
                'path' => 'src/EventListener/CaptchaGuardListener.php',
                'template' => 'symfony/listener.php.tpl',
                'notes' => [
                    'config/services.yaml',
                    '',
                    'Con autoconfigure por defecto no toques nada: el atributo',
                    '#[AsEventListener] registra solo. Solo si lo has desactivado,',
                    'etiqueta aquí la clase con kernel.request y quita el atributo:',
                    'el doble registro haría correr decide() dos veces y la segunda',
                    'fallaría.',
                ],
            ],
            [
                'path' => 'src/Controller/CaptchaController.php',
                'template' => 'symfony/controller.php.tpl',
                'notes' => [
                    'config/services.yaml',
                    '',
                    'Autowiring lo encuentra solo; márcalo public si la ruta no',
                    'puede referenciarlo.',
                ],
            ],
            [
                'path' => 'config/routes/captcha.yaml',
                'template' => 'symfony/routes.yaml.tpl',
                'notes' => [
                    'config/routes/captcha.yaml',
                    '',
                    'Entra sola en Symfony 5.3+ (el kernel importa config/routes/*).',
                    'Solo si has desactivado ese glob, importa en config/routes.yaml:',
                    '',
                    '    captcha:',
                    '        resource: \'routes/captcha.yaml\'',
                    '',
                    'Widget en la vista (no es la ruta por defecto):',
                    '',
                    '    Captcha::widget([\'endpoint\' => \'/captcha/generate\']);',
                ],
            ],
        ],
        'cakephp' => [
            [
                'path' => 'src/Middleware/CaptchaMiddleware.php',
                'template' => 'cakephp/middleware.php.tpl',
                'notes' => [
                    'src/Application.php',
                    '',
                    '    $middlewareQueue->add(new CaptchaMiddleware());',
                ],
            ],
            [
                'path' => 'src/Controller/CaptchaController.php',
                'template' => 'cakephp/controller.php.tpl',
                'notes' => [
                    'config/routes.php',
                    '',
                    '    $routes->scope(\'/captcha\', function (RouteBuilder $builder): void {',
                    '        $builder->connect(\'/generate\',',
                    '            [\'controller\' => \'Captcha\', \'action\' => \'generate\']);',
                    '    });',
                    '',
                    'Widget en la vista:',
                    '',
                    '    Captcha::widget([\'endpoint\' => \'/captcha/generate\']);',
                ],
            ],
        ],
        'yii' => [
            [
                'path' => 'filters/CaptchaFilter.php',
                'template' => 'yii/filter.php.tpl',
                'notes' => [
                    'config/web.php',
                    '',
                    '    \'as captcha\' => \\app\\filters\\CaptchaFilter::class,',
                    '',
                    'Nivel superior de config/web.php; \'behaviors\' a secas no es una',
                    'clave válida y lanza InvalidCallException. Aplica a toda acción:',
                    'acótalo con only o except si hace falta.',
                ],
            ],
            [
                'path' => 'controllers/CaptchaController.php',
                'template' => 'yii/controller.php.tpl',
                'notes' => [
                    'controllers/CaptchaController.php',
                    '',
                    'Acción inline (actionGenerate); @app la carga sin registro. Con',
                    'enablePrettyUrl (config/web.php) la ruta es /captcha/generate;',
                    'sin él, index.php?r=captcha/generate. Widget con esa misma ruta:',
                    '',
                    '    Captcha::widget([\'endpoint\' => \'/captcha/generate\']);',
                ],
            ],
        ],
        'yii3' => [
            [
                'path' => 'src/Web/Captcha/GuardMiddleware.php',
                'template' => 'yii3/middleware.php.tpl',
                'notes' => [
                    'config/web/di/application.php',
                    '',
                    '    App\\Web\\Captcha\\GuardMiddleware::class,',
                    '',
                    'Dentro de withMiddlewares(), justo antes de Router::class.',
                ],
            ],
            [
                'path' => 'src/Web/Captcha/GenerateAction.php',
                'template' => 'yii3/action.php.tpl',
                'notes' => [
                    'config/common/routes.php',
                    '',
                    '    Route::get(\'/captcha/generate\')',
                    '        ->action(App\\Web\\Captcha\\GenerateAction::class)',
                    '        ->name(\'captcha-generate\'),',
                    '',
                    'Widget en la vista:',
                    '',
                    '    Captcha::widget([\'endpoint\' => \'/captcha/generate\']);',
                ],
            ],
        ],
        'janssen' => [
            [
                'path' => 'app/Preprocessor/CaptchaGuard.php',
                'template' => 'janssen/preprocessor.php.tpl',
                'notes' => [
                    'app/Config/engine.php',
                    '',
                    '    \'preprocessors\' => [',
                    '        [\'\\App\\Preprocessor\\DecryptRoute\', \'POST\'],',
                    '        [\'\\App\\Preprocessor\\CaptchaGuard\', \'POST\'],',
                    '    ],',
                    '',
                    'Justo después de DecryptRoute (fija la acción) y solo en POST:',
                    'antes, el captcha se decide sin comparar credenciales; sin POST,',
                    'decide() corre en GET. Conserva \'json_encode_options\' => 0 en',
                    'este fichero: sin ella, cada recarga responde Deprecated. En la',
                    'constante ACCIONES de la clase, vacía corta todo el POST.',
                ],
            ],
            [
                'path' => 'app/Controller/CaptchaController.php',
                'template' => 'janssen/controller.php.tpl',
                'notes' => [
                    'app/Config/routes.php',
                    '',
                    '    [\'path\' => \'/captcha\',',
                    '     \'resolver\' => \'App\\Controller\\CaptchaController@generate\',',
                    '     \'guard\' => \'nobody\'],',
                    '    [\'path\' => \'/captcha/action/generate\',',
                    '     \'resolver\' => \'App\\Controller\\CaptchaController@generate\',',
                    '     \'guard\' => \'nobody\'],',
                    '',
                    'El widget recarga con ?action=generate y Janssen la convierte en',
                    'segmentos de ruta: por eso hacen falta las dos entradas.',
                ],
            ],
            [
                'path' => 'templates/captcha-test.php',
                'template' => 'janssen/captcha-test.php.tpl',
                'notes' => [
                    'app/Config/routes.php',
                    '',
                    '    // Banco de pruebas del widget.',
                    '    [\'path\' => \'/captcha-test\',',
                    '     \'resolver\' => \'captcha-test.php\',',
                    '     \'guard\' => \'nobody\'],',
                    '',
                    'Con esa ruta se abre templates/captcha-test.php: el banco de',
                    'pruebas del widget, con su CSS y JS inline y sin configuración.',
                ],
            ],
        ],
    ];

    /**
     * Los frameworks que traen integración declarada, en orden de definición.
     *
     * @return list<string>
     */
    public static function frameworks(): array
    {
        return array_keys(self::PER_FRAMEWORK);
    }

    /**
     * Los artefactos declarados para un framework, con su plantilla y sus
     * pasos de registro.
     *
     *
     * @throws InvalidConfigException Cuando el framework no trae integración.
     *
     * @return list<array{path: string, template: string, notes: list<string>}>
     */
    public static function for(string $framework): array
    {
        if (!isset(self::PER_FRAMEWORK[$framework])) {
            throw new InvalidConfigException(sprintf(
                'El framework "%s" no trae integración declarada.',
                $framework,
            ));
        }

        return self::PER_FRAMEWORK[$framework];
    }

    /**
     * La ruta absoluta de una plantilla de integración dentro de src/app/.
     *
     * @throws InvalidConfigException Cuando la plantilla no existe en disco.
     */
    public static function contents(string $template): string
    {
        $path = __DIR__ . '/../app/Integration/templates/' . $template;
        $contents = is_file($path) ? (string) file_get_contents($path) : false;

        if ($contents === false) {
            throw new InvalidConfigException(sprintf(
                'No se pudo leer la plantilla de integración "%s".',
                $template,
            ));
        }

        return $contents;
    }
}
