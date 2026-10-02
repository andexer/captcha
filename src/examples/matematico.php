<?php

declare(strict_types=1);

/**
 * Ejemplo 6: formulario con captcha matemático — suma y resta.
 *
 * La imagen muestra "a + b" o "a - b" y el usuario escribe solo el resultado.
 * Cada cambio de parámetro (longitud, dificultad o tipo de operación) recarga
 * la página por GET y regenera el captcha; el POST lo verifica y consume
 * (uso único), así que la página siempre muestra un captcha nuevo.
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
use Captcha\Config\Difficulty;
use Captcha\Config\Operation;
use Captcha\Storage\SessionStorage;

session_start();

$length = match ((string) ($_POST['length'] ?? $_GET['length'] ?? '5')) {
    '3' => 3,
    '10' => 10,
    default => 5,
};
$difficulty = match ((string) ($_POST['difficulty'] ?? $_GET['difficulty'] ?? 'medium')) {
    'low' => Difficulty::Low,
    'high' => Difficulty::High,
    default => Difficulty::Medium,
};

$add = isset($_POST['addition']) ? true : isset($_GET['addition']);
$subtract = isset($_POST['subtraction']) ? true : isset($_GET['subtraction']);

$operations = [];
if ($add) {
    $operations[] = Operation::Add;
}
if ($subtract) {
    $operations[] = Operation::Subtract;
}

/*
 *  `generateAttempts: 0` desactiva el techo anti-flood solo para esta demo, que
 *  se recarga sin parar mientras la pruebas. En una app real déjalo en su
 *  default (20 por ventana) y captura RateLimitException para responder 429.
 */
$config = new Config(
    length: $length,
    difficulty: $difficulty,
    operations: $operations,
    generateAttempts: 0,
);
$storage = new SessionStorage();

$outcome = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $outcome = (new Captcha($storage, $config))->verify(
        id: (string) ($_POST['captcha_id'] ?? ''),
        input: (string) ($_POST['captcha'] ?? ''),
    );
}

$challenge = (new Captcha($storage, $config))->generate();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Captcha — ejemplo matemático</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 460px; margin: 0 auto; padding: 2rem 1.25rem; line-height: 1.5; }
        h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
        p.lead { color: #667085; margin: 0 0 1.5rem; }
        form.params { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .card { background: #fff; border: 1px solid #e2e6ee; border-radius: 12px; padding: 1.25rem; box-shadow: 0 1px 2px rgba(16, 24, 40, .05); }
        img { display: block; border: 1px solid #e2e6ee; border-radius: 8px; margin-bottom: .75rem; }
        .row { display: flex; gap: .5rem; align-items: center; }
        input[type="text"] { flex: 1; padding: .45rem .6rem; border: 1px solid #d0d7e2; border-radius: 8px; }
        button { padding: .45rem .9rem; border: 0; border-radius: 8px; background: #3b82f6; color: #fff; cursor: pointer; }
        .checks { display: flex; gap: 1rem; align-items: center; }
        .ok { color: #177233; font-weight: 600; }
        .bad { color: #b42318; font-weight: 600; }
    </style>
</head>
<body>
    <h1>Formulario con captcha matemático</h1>
    <p class="lead">Resuelve la operación de la imagen: escribes solo el resultado.
        Cambia longitud, dificultad o tipo de operación y la página se recarga
        con los nuevos parámetros.</p>

    <form method="get" class="params">
        <label>Longitud
            <select name="length" onchange="this.form.submit()">
                <option value="3"<?= $length === 3 ? ' selected' : '' ?>>3 dígitos</option>
                <option value="5"<?= $length === 5 ? ' selected' : '' ?>>5 dígitos</option>
                <option value="10"<?= $length === 10 ? ' selected' : '' ?>>10 dígitos</option>
            </select>
        </label>
        <label>Dificultad
            <select name="difficulty" onchange="this.form.submit()">
                <option value="low"<?= $difficulty === Difficulty::Low ? ' selected' : '' ?>>Baja</option>
                <option value="medium"<?= $difficulty === Difficulty::Medium ? ' selected' : '' ?>>Media</option>
                <option value="high"<?= $difficulty === Difficulty::High ? ' selected' : '' ?>>Alta</option>
            </select>
        </label>
        <span class="checks">
            <label><input type="checkbox" name="addition"<?= $add ? ' checked' : '' ?> onchange="this.form.submit()"> Suma</label>
            <label><input type="checkbox" name="subtraction"<?= $subtract ? ' checked' : '' ?> onchange="this.form.submit()"> Resta</label>
        </span>
    </form>

    <div class="card">
        <?php if ($outcome !== null): ?>
            <p class="<?= $outcome->isValid() ? 'ok' : 'bad' ?>">
                <?= htmlspecialchars($outcome->getMessage(), ENT_QUOTES) ?>
            </p>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="captcha_id" value="<?= htmlspecialchars($challenge->getId(), ENT_QUOTES) ?>">
            <input type="hidden" name="length" value="<?= $length ?>">
            <input type="hidden" name="difficulty" value="<?= $difficulty->value ?>">
            <input type="hidden" name="addition" value="1">
            <?php if ($subtract): ?>
            <input type="hidden" name="subtraction" value="1">
            <?php endif; ?>
            <img src="<?= $challenge->getDataUri() ?>" alt="Operación a resolver"
                 width="<?= $config->width ?>" height="<?= $config->height ?>">
            <div class="row">
                <input type="text" name="captcha" maxlength="<?= $config->length ?>"
                       autocomplete="off" inputmode="numeric" required placeholder="Resultado">
                <button type="submit">Verificar</button>
            </div>
        </form>
    </div>
</body>
</html>
