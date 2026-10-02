<?php

declare(strict_types=1);

/**
 * Ejemplo 4: API REST mínima (endpoint único que genera y verifica).
 *
 * GET  /04-rest-api.php          -> {"id": "...", "image": "data:image/png;base64,..."}
 * POST /04-rest-api.php id=... code=... -> {"valid": true|false, "status": "...", "message": "..."}
 *
 * El campo `status` llega en español (correcto|incorrecto|caducado|no_encontrado|bloqueado);
 * el ejemplo es solo eso, un ejemplo: trátalo como lo que mejor encaje con tu API.
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
use Captcha\Verification\Status;

session_start();
header('Content-Type: application/json; charset=utf-8');

/*
 *  `generateAttempts: 0` desactiva el techo anti-flood solo para esta demo, que
 *  se llama sin parar mientras la pruebas. Una API real lo deja en su default
 *  (20 por ventana) y traduce RateLimitException a un 429.
 */
$captcha = new Captcha(new SessionStorage(), new Config(generateAttempts: 0));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $captcha->verify(
        id: (string) ($_POST['id'] ?? ''),
        input: (string) ($_POST['code'] ?? ''),
    );

    echo json_encode([
        'valid' => $result->isValid(),
        'status' => match ($result->getStatus()) {
            Status::Ok => 'correcto',
            Status::Invalid => 'incorrecto',
            Status::Expired => 'caducado',
            Status::Missing => 'no_encontrado',
            Status::Blocked => 'bloqueado',
        },
        'message' => $result->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return;
}

$challenge = $captcha->generate();
$_SESSION['captcha_id'] = $challenge->getId();

echo json_encode([
    'id' => $challenge->getId(),
    'image' => $challenge->getDataUri(),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
