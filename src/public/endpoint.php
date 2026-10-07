<?php

declare(strict_types=1);

use Captcha\Captcha;
use Captcha\Http\Endpoint;

/*
*  Endpoint drop-in para el widget JavaScript. Va sobre la capa estática
*  (Captcha::instance()) para usar la MISMA configuración global descubierta
*  por el paquete (env CAPTCHA_CONFIG → app/Config/captcha.php →
*  config/captcha.php → config/packages/captcha.php → etc/captcha.php): coincide
*  por construcción con la del formulario — comparten sesión y no hay dos
*  configs que mantener sincronizadas.
*
*  El límite de generación frena el flood del endpoint (GD es costoso en
*  CPU): los excedentes responden 429. Sin cookies el contador sigue en
*  IpRateLimiter (ficheros por IP). Actívalo en tu config global, p. ej.:
*
*  'generateAttempts' => 10,
*
*  El default del paquete ya trae techo (20 generate / 5 verify por ventana
*  de 300 s); ponlo a 0 solo para desactivarlo de forma explícita.
*/

/*
*  Autoload de Composer en cascada, como bin/captcha: los cuatro
*  layouts de instalación razonables — el primero que exista gana.
*/
foreach ([
    // Repositorio del paquete (vendor está 2 niveles por encima de src/public/).
    __DIR__ . '/../../vendor/autoload.php',
    /*
    *  Instalación Composer vendor/andexer/captcha/src/public/ (4 niveles
    *  arriba está el vendor/ del anfitrión).
    */
    __DIR__ . '/../../../../autoload.php',
    // Path repository copiado dentro del proyecto (3 niveles a la raíz).
    __DIR__ . '/../../../vendor/autoload.php',
    // Copiado al docroot/carpeta pública del anfitrión (1 nivel a la raíz).
    __DIR__ . '/../vendor/autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}

if (!class_exists(Captcha::class)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo '{"ok":false,"error":"No se encontró el autoload de Composer (vendor\/autoload.php)."}';
    exit;
}

$action = $_GET['action'] ?? '';

if (!is_string($action)) {
    /*
    *  ?action[]=... inyecta un array: no es una acción válida, responde 400
    *  en vez de estrellar handle() con un TypeError.
    */
    $action = '';
}

(new Endpoint(Captcha::instance()))->handle($action);
