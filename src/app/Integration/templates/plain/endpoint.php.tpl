<?php

declare(strict_types=1);

/*
 * Endpoint AJAX del captcha para una aplicación PHP sin framework.
 *
 * El widget recarga el reto con un GET a este fichero. Solo se expone la acción
 * "generate": el código se verifica en el POST que trata el formulario, y el
 * paquete nunca devuelve el código en claro.
 *
 * La resolución del autoload va en cascada porque este fichero se copia dentro
 * de la aplicación anfitriona, donde el paquete vive en vendor/andexer/captcha/.
 */

$autoload = null;

foreach ([
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;

        break;
    }
}

if ($autoload === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => false, 'error' => 'No se encontró el autoload de Composer.']));
}

require $autoload;

$action = $_GET['action'] ?? 'generate';

(new Captcha\Http\Endpoint(Captcha\Captcha::instance()))->handle(is_string($action) ? $action : '');
