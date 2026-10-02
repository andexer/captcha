<?php

declare(strict_types=1);

/**
 * Ejemplo 1: uso mínimo — generar un captcha y servir el PNG.
 */

/*
 *  Autoload de Composer en cascada, igual que bin/captcha y
 *  src/public/endpoint.php: el ejemplo arranca tanto desde el repositorio como
 *  instalado como dependencia, en vendor/andexer/captcha/src/examples/. Gana el
 *  primer candidato que exista en disco.
 */
foreach ([
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../../autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Storage\SessionStorage;

session_start();

/*
 *  `generateAttempts: 0` desactiva el techo anti-flood solo para esta demo, que
 *  se recarga sin parar mientras la pruebas. En una app real déjalo en su
 *  default (20 por ventana) y captura RateLimitException para responder 429.
 */
$captcha = new Captcha(new SessionStorage(), new Config(generateAttempts: 0));
$result = $captcha->generate();

// Guardamos el id (no el código) en la sesión para verificarlo después.
$_SESSION['captcha_id'] = $result->getId();

header('Content-Type: ' . $result->getMimeType());
echo $result->getImage();
