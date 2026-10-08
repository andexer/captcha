<?php

declare(strict_types=1);

/*
 * Endpoint AJAX del captcha para una aplicación PHP sin framework.
 *
 * El widget recarga el reto con un GET a este fichero; solo expone la acción
 * "generate", porque el código se verifica en el POST del formulario y nunca
 * sale en claro. El autoload va en cascada porque el fichero se copia dentro
 * de la aplicación anfitriona, donde el paquete vive en vendor/.
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
