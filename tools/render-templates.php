<?php

declare(strict_types=1);

/**
 * Generador de plantillas de framework para `captcha install` (#5).
 *
 * Las 7 plantillas de config que se distribuyen comparten ~80 % de su
 * contenido (el bloque de opciones); esta herramienta renderiza cada una a
 * partir del fragmento compartido (src/app/Config/fragments/options.php)
 * más su cabecera y sus notas de wiring específicas del framework,
 * regenerando src/app/Config/templates/*.php en el sitio. Un framework
 * nuevo = una entrada en $frameworks; la documentación de las opciones se
 * escribe una sola vez.
 *
 * Seis de las siete entregan el bloque de opciones comentado, para que quien
 * instala lea las opciones y active solo lo que necesite. Janssen es la
 * excepción: su entrada trae 'body' con los valores ya activos y su propia nota,
 * porque en ese framework el config se escribe en la raíz de la app y el
 * instalador no va a preguntar por cada clave.
 *
 * Uso: php tools/render-templates.php
 * Enganchado a composer como `config:templates`; TemplateRenderTest
 * demuestra que los ficheros distribuidos están al día.
 */

$root = dirname(__DIR__);
$fragmentPath = $root . '/src/app/Config/fragments/options.php';

/**
 * Plantilla por framework: comentario de cabecera, bloque de wiring
 * opcional y el cuerpo del return. Todas devuelven un array plano, el mismo
 * contrato que espera el descubrimiento automático. El cuerpo sale del
 * fragmento compartido salvo que la entrada traiga 'body' (Janssen, que
 * entrega valores activos en vez de opciones comentadas).
 *
 * Ninguna de estas cabeceras puede llevar la secuencia ?> de PHP: el lexer
 * cierra el modo PHP también dentro de un comentario de línea, así que el
 * resto de la plantilla se emitiría como texto y el config devolvería 1 en
 * lugar del array. InstallerTest lo caza al exigir que la plantilla cargada
 * sea un array.
 */
$frameworks = [
    'plain' => [
        'header' => [
            '// Configuración global de captcha — PHP plano (o cualquier framework).',
            '// captcha config v2',
            '// Instalación: vendor/bin/captcha install',
            '// ─────────────────────────────────────────────────────────────────────────────',
            '// La capa estática descubre este fichero automáticamente (orden: env',
            '// CAPTCHA_CONFIG → raíz del proyecto app/Config/captcha.php → config/ →',
            '// etc/ → los mismos en cwd) y sus valores alimentan tanto widget() como',
            '// check(). Instalación completa = composer require + este fichero. install',
            '// escribe además el guard (src/CaptchaGuard.php) y el endpoint AJAX',
            '// (public/captcha.php) cuando eliges el framework plain.',
        ],
        'wiring' => [],
    ],
    'codeigniter' => [
        'header' => [
            '// Configuración de captcha — CodeIgniter 4 (ruta app/Config/captcha.php).',
            '// captcha config v2',
            '// Instalación: vendor/bin/captcha install --framework=codeigniter',
            '// ─────────────────────────────────────────────────────────────────────────────',
            '// La capa estática descubre este fichero automáticamente (orden: env',
            '// CAPTCHA_CONFIG → raíz del proyecto app/Config/captcha.php → config/ →',
            '// etc/ → los mismos en cwd) y sus valores alimentan tanto widget() como',
            '// check(). install escribe además el guard como filter',
            '// (app/Filters/CaptchaFilter.php) y el endpoint AJAX',
            '// (app/Controllers/Captcha.php); regístralos en app/Config/Filters.php',
            '// y app/Config/Routes.php.',
        ],
        'wiring' => [],
    ],
    'laravel' => [
        'header' => [
            '// Configuración de captcha — Laravel (ruta config/captcha.php).',
            '// captcha config v2',
            '// Instalación: vendor/bin/captcha install --framework=laravel',
            '// ─────────────────────────────────────────────────────────────────────────────',
            '// Laravel carga este fichero con config(\'captcha\'). Conecta el array en',
            '// un service provider (regístrate en config/app.php providers):',
        ],
        'wiring' => [
            '//',
            '//   use Captcha\Captcha;',
            '//',
            '//   public function boot(): void',
            '//   {',
            '//       Captcha::configure((array) config(\'captcha\'));',
            '//   }',
            '//',
            '// Luego Captcha::widget() dentro del <form> y Captcha::check() en el POST.',
            '// Para CSP estricta usa Captcha::assets(\'url\', ...) (ver README). install',
            '// escribe además el service provider, el middleware (guard) y el',
            '// controlador de la ruta AJAX; el instalador imprime dónde va cada uno.',
        ],
    ],
    'symfony' => [
        'header' => [
            '// Configuración de captcha — Symfony (ruta config/packages/captcha.php).',
            '// captcha config v2',
            '// Instalación: vendor/bin/captcha install --framework=symfony',
            '// ─────────────────────────────────────────────────────────────────────────────',
            '// Symfony carga este fichero como parámetro de contenedor. Conecta el array',
            '// en un compiler pass o en un servicio:',
        ],
        'wiring' => [
            '//',
            '//   use Captcha\Captcha;',
            '//',
            '//   $config = (array) $container->getParameter(\'captcha\');',
            '//   Captcha::configure($config);',
            '//',
            '// Luego Captcha::widget() dentro del <form> (Twig) y Captcha::check() en el',
            '// controlador del POST. install escribe además el listener de',
            '// kernel.request (guard), el controlador de la ruta AJAX y su ruta YAML.',
        ],
    ],
    'cakephp' => [
        'header' => [
            '// Configuración de captcha — CakePHP (ruta config/captcha.php).',
            '// captcha config v2',
            '// Instalación: vendor/bin/captcha install --framework=cakephp',
            '// ─────────────────────────────────────────────────────────────────────────────',
            '// La capa estática descubre este fichero automáticamente (orden: env',
            '// CAPTCHA_CONFIG → raíz del proyecto app/Config/captcha.php → config/ →',
            '// etc/ → los mismos en cwd) y sus valores alimentan tanto widget() como',
            '// check(); no necesitas tocar Configure. El array es plano, igual que en',
            '// el resto de frameworks.',
        ],
        'wiring' => [
            '//',
            '// Luego Captcha::widget() en la vista (Helper) y Captcha::check() en el',
            '// controller del POST. install escribe además el middleware PSR-15',
            '// (guard) y el controlador de la ruta AJAX.',
        ],
    ],
    'yii' => [
        'header' => [
            '// Configuración de captcha — Yii (ruta config/captcha.php).',
            '// captcha config v2',
            '// Instalación: vendor/bin/captcha install --framework=yii',
            '// ─────────────────────────────────────────────────────────────────────────────',
            '// Yii carga este fichero en el arranque de la aplicación. Conecta el array',
            '// en config/web.php (y console.php):',
        ],
        'wiring' => [
            '//',
            '//   use Captcha\Captcha;',
            '//',
            '//   Captcha::configure(require __DIR__ . \'/captcha.php\');',
            '//',
            '// Luego Captcha::widget() dentro del formulario y Captcha::check() en la',
            '// acción que recibe el POST. install escribe además el action filter',
            '// (guard) y el controlador de la ruta AJAX.',
        ],
    ],
    'janssen' => [
        'header' => [
            '// Configuración de captcha — Janssen (ruta app/Config/captcha.php).',
            '// captcha config v2',
            '// Instalación: vendor/bin/captcha install --framework=janssen',
            '// ─────────────────────────────────────────────────────────────────────────────',
            '// La capa estática descubre este fichero automáticamente (orden: env',
            '// CAPTCHA_CONFIG → raíz del proyecto app/Config/captcha.php → config/ →',
            '// etc/ → los mismos en cwd) y sus valores alimentan tanto widget() como',
            '// el veredicto del guard. Los valores de abajo están activos: ajústalos a',
            '// tu gusto y borra las líneas que no uses, porque toda clave es opcional.',
            '//',
            '// El widget se dibuja en la plantilla del formulario y el POST lo decide',
            '// el CaptchaGuard que escribe install. Modo estricto: una clave no',
            '// reconocida lanza InvalidConfigException, así que un typo no desactiva',
            '// la protección en silencio.',
        ],
        'wiring' => [
            '//',
            '//     Captcha\Captcha::widget([\'endpoint\' => $href(\'/captcha\')]);',
            '//',
            '// install escribe además el preprocesador (guard) y el controlador de la',
            '// ruta AJAX; el instalador imprime dónde registrar cada uno.',
        ],
        'body' => [
            '// Código de 5 dígitos en una imagen de 200x60, válido 2 minutos.',
            '\'length\' => 5,',
            '\'width\' => 200,',
            '\'height\' => 60,',
            '\'ttl\' => 120,',
            '',
            '// Dificultad visual: operandos de hasta 9 (low), 99 (medium) o',
            '// 999 (high).',
            '\'difficulty\' => \'medium\',',
            '',
            '// Tipografía bitmap integrada de GD (1-5; la 5 es la mayor) y alto',
            '// del glifo en píxeles; null lo deja automático.',
            '\'font\' => 5,',
            '\'fontSize\' => 35,',
            '',
            '// Ruido y distorsión para dificultar el OCR.',
            '\'noise\' => true,',
            '\'distortion\' => true,',
            '',
            '// Techo anti-flood por IP y ventana de 300 segundos.',
            '\'verifyAttempts\' => 10,',
            '\'generateAttempts\' => 30,',
            '',
            '// Modo aritmético: el reto muestra "a + b" o "a - b" y se teclea el',
            '// resultado, que siempre cae en este rango. Los operandos también',
            '// quedan confinados al entorno del rango (techo max + (max-min)/4).',
            '\'operations\' => [\'+\', \'-\'],',
            '\'between\' => [0, 20],',
        ],
        'note' => [
            '//',
            '// Borra la línea de cualquier clave que no uses: todas son opcionales y',
            '// lo que falta se queda en su default. Para volver a reto numérico, quita',
            '// \'operations\' y \'between\'.',
        ],
    ],
];

// El fragmento compartido define $fragment y hace echo; capturamos su salida.
ob_start();
require $fragmentPath;
$fragment = (string) ob_get_clean();

// Aviso estricto común (va tras las notas de wiring en cada plantilla).
$strictNote = [
    '//',
    '// Todas las claves son OPCIONALES: cada línea comentada deja su default',
    '// activo (los mismos del constructor del paquete). Descomenta y ajusta solo',
    '// lo que necesites. Modo estricto: una clave no reconocida lanza',
    '// InvalidConfigException — un typo no desactiva la protección en silencio.',
];

foreach ($frameworks as $name => $spec) {
    /*
    *  El cuerpo y la nota se sustituyen por los de la entrada cuando los trae:
    *  Janssen entrega valores activos, y su sentido es el inverso al del
    *  fragmento comentado, así que también necesita su propio aviso. Su cuerpo
    *  llega como lista de líneas sin sangrar —la sangría la pone el generador,
    *  que es quien la firma de estilo impone— y el fragmento compartido ya
    *  viene con la suya.
    */
    $body = isset($spec['body']) ? indentar($spec['body']) : $fragment;
    $note = $spec['note'] ?? $strictNote;

    $content = "<?php\n\ndeclare(strict_types=1);\n\n"
        . implode("\n", $spec['header']) . "\n"
        . implode("\n", $spec['wiring']) . "\n"
        . implode("\n", $note) . "\n\n"
        . "return [\n"
        . $body . "\n"
        . "];\n";

    file_put_contents($root . '/src/app/Config/templates/' . $name . '.php', $content);
    printf("rendered %s.php (%d bytes)\n", $name, strlen($content));
}

/**
 * Une las líneas de un cuerpo con la sangría de un elemento de array, que es
 * la que espera el estilo del proyecto: cuatro espacios, y líneas en blanco
 * sin tocar.
 *
 * @param list<string> $lineas
 */
function indentar(array $lineas): string
{
    return implode("\n", array_map(
        static fn (string $linea): string => $linea === '' ? '' : '    ' . $linea,
        $lineas,
    ));
}
