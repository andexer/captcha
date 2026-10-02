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
                    'Para proteger el POST que procesa el formulario:',
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
                    'El widget recarga el reto contra este endpoint; indícalo al',
                    'dibujarlo o el botón de recarga no tendrá destino:',
                    '',
                    '    Captcha::widget([\'endpoint\' => \'/captcha.php\']);',
                ],
            ],
        ],
        'codeigniter' => [
            [
                'path' => 'app/Filters/CaptchaFilter.php',
                'template' => 'codeigniter/filter.php.tpl',
                'notes' => [
                    'Regístralo en app/Config/Filters.php, en $aliases:',
                    '',
                    '    \'captcha\' => \\App\\Filters\\CaptchaFilter::class,',
                ],
            ],
            [
                'path' => 'app/Controllers/Captcha.php',
                'template' => 'codeigniter/controller.php.tpl',
                'notes' => [
                    'Regístralo en app/Config/Routes.php, en $routes->get():',
                    '',
                    '    $routes->get(\'captcha/generate\', \'Captcha::generate\');',
                ],
            ],
        ],
        'laravel' => [
            [
                'path' => 'app/Providers/CaptchaServiceProvider.php',
                'template' => 'laravel/provider.php.tpl',
                'notes' => [
                    'Regístralo en bootstrap/providers.php (Laravel 11 y superior)',
                    'o en config/app.php, clave providers (Laravel 9 y 10):',
                    '',
                    '    App\\Providers\\CaptchaServiceProvider::class,',
                ],
            ],
            [
                'path' => 'app/Http/Middleware/CaptchaGuardMiddleware.php',
                'template' => 'laravel/middleware.php.tpl',
                'notes' => [
                    'Registra el middleware en bootstrap/app.php (Laravel 11 y',
                    'superior), dentro de ->withMiddleware():',
                    '',
                    '    $middleware->append(CaptchaGuardMiddleware::class);',
                    '',
                    'En Laravel 9 y 10 el sitio es app/Http/Kernel.php, en la',
                    'propiedad $middleware. Para una sola ruta, aplícalo con',
                    '->middleware(\'captcha\') en el POST que queras proteger.',
                ],
            ],
            [
                'path' => 'app/Http/Controllers/CaptchaController.php',
                'template' => 'laravel/controller.php.tpl',
                'notes' => [
                    'Publica la ruta en routes/web.php:',
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
                    'Etiqueta el listener en config/services.yaml:',
                    '',
                    '    App\\EventListener\\CaptchaGuardListener:',
                    '        tags:',
                    '            - { name: \'kernel.event_listener\',',
                    '                           event: \'kernel.request\', }',
                ],
            ],
            [
                'path' => 'src/Controller/CaptchaController.php',
                'template' => 'symfony/controller.php.tpl',
                'notes' => [
                    'El controlador es un servicio: autowiring lo encuentra solo.',
                    'Marca la clase como public en config/services.yaml para que',
                    'la ruta pueda referenciarla.',
                ],
            ],
            [
                'path' => 'config/routes/captcha.yaml',
                'template' => 'symfony/routes.yaml.tpl',
                'notes' => [
                    'Importa la ruta desde config/routes.yaml:',
                    '',
                    '    captcha:
        resource: \'routes/captcha.yaml\'',
                ],
            ],
        ],
        'cakephp' => [
            [
                'path' => 'src/Middleware/CaptchaMiddleware.php',
                'template' => 'cakephp/middleware.php.tpl',
                'notes' => [
                    'Regístralo en src/Application.php, en middleware():',
                    '',
                    '    $middleware->add(new CaptchaMiddleware());',
                ],
            ],
            [
                'path' => 'src/Controller/CaptchaController.php',
                'template' => 'cakephp/controller.php.tpl',
                'notes' => [
                    'Mapea la ruta en routes.php, dentro de $routes->scope():',
                    '',
                    '    $routes->scope(\'/captcha\', function (RouteBuilder $builder): void {',
                    '        $builder->connect(\'/generate\', [\'Captcha\' => \'generate\']);',
                    '    });',
                ],
            ],
        ],
        'yii' => [
            [
                'path' => 'src/filters/CaptchaFilter.php',
                'template' => 'yii/filter.php.tpl',
                'notes' => [
                    'Regístralo en config/web.php, en behaviors:',
                    '',
                    '    \'captcha\' => \\app\\filters\\CaptchaFilter::class,',
                ],
            ],
            [
                'path' => 'src/controllers/CaptchaController.php',
                'template' => 'yii/controller.php.tpl',
                'notes' => [
                    'La acción es inline (actionGenerate). Con el mapeo psr-4',
                    'app\\ => src/ de Composer, la ruta /captcha/generate la',
                    'alcanza sola. Si el controlador no está en app\\controllers,',
                    'decláralo en config/web.php dentro de controllerMap:',
                    '',
                    '    \'captcha\' => [',
                    '        \'class\' => \'\\app\\controllers\\CaptchaController\',',
                    '    ],',
                ],
            ],
        ],
        'janssen' => [
            [
                'path' => 'app/Preprocessor/CaptchaGuard.php',
                'template' => 'janssen/preprocessor.php.tpl',
                'notes' => [
                    'Regístralo en app/Config/engine.php, en la lista \'preprocessors\',',
                    'para POST y después de DecryptRoute (que fija la acción del usuario):',
                    '',
                    '    \'preprocessors\' => [',
                    '        \'\\App\\Preprocessor\\Maintenance\',',
                    '        [\'\\App\\Preprocessor\\DecryptRoute\', \'POST\'],',
                    '        \'\\App\\Preprocessor\\AccessControl\',',
                    '        [\'\\App\\Preprocessor\\CaptchaGuard\', \'POST\'],',
                    '    ],',
                    '',
                    'Esa lista es plana y se recorre en orden: cada entrada es la clase',
                    'del preprocesador o [clase, verbo] para limitarlo a un método HTTP.',
                    'La tupla con \'POST\' importa: sin ella el guard también se ejecuta en',
                    'GET y decide() vería un envío vacío.',
                    '',
                    'Comprueba que en ese mismo engine.php siga la clave de la que Janssen',
                    'saca los flags de json_encode() al responder (la trae el stock):',
                    '',
                    '    /* json_encode options for PHP 8.1+ compatibility */',
                    '    \'json_encode_options\' => 0,',
                    '',
                    'La lee Janssen\\Helpers\\Response\\JsonResponse::render(), que es quien',
                    'emite el reto del captcha. Sin la clave Config::get() devuelve null y',
                    'json_encode() recibe null en $flags: cada recarga del widget responde',
                    'con un "Deprecated" que, en una app que convierte los avisos en',
                    'excepciones, se come el JSON entero.',
                    '',
                    'Ajusta en el fichero la acción que proteges: el preprocesador es',
                    'global y solo decide() en esa una.',
                ],
            ],
            [
                'path' => 'app/Controller/CaptchaController.php',
                'template' => 'janssen/controller.php.tpl',
                'notes' => [
                    'Mapea la ruta en app/Config/routes.php. Sin ella el botón de',
                    'recarga del widget se queda sin destino:',
                    '',
                    '    // Endpoint del captcha.',
                    '    [\'path\' => \'/captcha\',',
                    '     \'resolver\' => \'App\\Controller\\CaptchaController@generate\',',
                    '     \'guard\' => \'nobody\'],',
                    '',
                    '    // La misma con la query del widget como friendly path.',
                    '    [\'path\' => \'/captcha/action/generate\',',
                    '     \'resolver\' => \'App\\Controller\\CaptchaController@generate\',',
                    '     \'guard\' => \'nobody\'],',
                    '',
                    'Janssen convierte el query en segmentos de la ruta: lo que el widget',
                    'manda al recargar (?action=generate) se resuelve como',
                    '/captcha/action/generate, así que hacen falta las dos entradas.',
                ],
            ],
            [
                'path' => 'templates/captcha-test.php',
                'template' => 'janssen/captcha-test.php.tpl',
                'notes' => [
                    'Página de prueba del widget: dibuja el captcha con su CSS y su JS',
                    'inline, sin configuración. Enciéndela con esta ruta en',
                    'app/Config/routes.php:',
                    '',
                    '    // Banco de pruebas del widget.',
                    '    [\'path\' => \'/captcha-test\',',
                    '     \'resolver\' => \'captcha-test.php\',',
                    '     \'guard\' => \'nobody\'],',
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
