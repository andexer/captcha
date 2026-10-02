<?php

declare(strict_types=1);

/**
 * Ejemplo 5: formulario simple de dígitos — controlador + vista.
 *
 * Misma estructura que 02: el controlador resuelve parámetros y verificación;
 * la vista solo imprime. Aquí la configuración se construye a mano (Config)
 * porque los selectores la cambian, pero el flujo es el mismo de dos
 * llamadas: check() consume el reto una vez (uso único), así que la página
 * siempre muestra un captcha nuevo después de cada intento.
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
use Captcha\Exception\RateLimitException;
use Captcha\Storage\SessionStorage;

// ── CONTROLADOR ──────────────────────────────────────────────────────────────
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

$captcha = new Captcha(new SessionStorage(), new Config(length: $length, difficulty: $difficulty));
$check = $captcha->check();

// Techo anti-flood capturado: la página muestra feedback en vez de fallar.
$challenge = null;
$limitError = null;

try {
    $challenge = $captcha->generate();
} catch (RateLimitException $e) {
    $limitError = $e->getMessage();
}

// ── VISTA ────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Captcha — ejemplo simple</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 460px; margin: 0 auto; padding: 2rem 1.25rem; line-height: 1.5; }
        h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
        p.lead { color: #667085; margin: 0 0 1.5rem; }
        form.params { display: flex; gap: 1rem; margin-bottom: 1.5rem; }
        .card { background: #fff; border: 1px solid #e2e6ee; border-radius: 12px; padding: 1.25rem; box-shadow: 0 1px 2px rgba(16, 24, 40, .05); }
        img { display: block; border: 1px solid #e2e6ee; border-radius: 8px; margin-bottom: .75rem; }
        .row { display: flex; gap: .5rem; align-items: center; }
        input[type="text"] { flex: 1; padding: .45rem .6rem; border: 1px solid #d0d7e2; border-radius: 8px; }
        button { padding: .45rem .9rem; border: 0; border-radius: 8px; background: #3b82f6; color: #fff; cursor: pointer; }
        .ok { color: #177233; font-weight: 600; }
        .bad { color: #b42318; font-weight: 600; }
    </style>
</head>
<body>
    <h1>Formulario simple</h1>
    <p class="lead">Captcha de dígitos clásico. Cambia longitud o dificultad y la página
        se recarga con los nuevos parámetros.</p>

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
    </form>

    <div class="card">
        <?php if ($check->error !== null): ?>
            <p class="bad"><?= htmlspecialchars($check->error, ENT_QUOTES) ?></p>
        <?php elseif ($check->passed): ?>
            <p class="ok">Código captcha correcto.</p>
        <?php elseif ($limitError !== null): ?>
            <p class="bad"><?= htmlspecialchars($limitError, ENT_QUOTES) ?></p>
        <?php endif; ?>

        <?php if ($challenge !== null): ?>
        <form method="post">
            <input type="hidden" name="captcha_id" value="<?= htmlspecialchars($challenge->getId(), ENT_QUOTES) ?>">
            <input type="hidden" name="length" value="<?= $length ?>">
            <input type="hidden" name="difficulty" value="<?= $difficulty->value ?>">
            <img src="<?= $challenge->getDataUri() ?>" alt="Captcha a resolver"
                 width="<?= $captcha->config()->width ?>" height="<?= $captcha->config()->height ?>">
            <div class="row">
                <input type="text" name="captcha" maxlength="<?= $captcha->config()->length ?>"
                       autocomplete="off" inputmode="numeric" required placeholder="Código">
                <button type="submit">Verificar</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
