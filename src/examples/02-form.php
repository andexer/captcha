<?php

declare(strict_types=1);

/**
 * Ejemplo 2: formulario end-to-end — controlador + vista.
 *
 * El paquete entero se configura con UN archivo (descubierto automáticamente
 * por la env CAPTCHA_CONFIG o por <cwd>/app/Config/captcha.php) y se usa
 * con dos llamadas:
 *
 *   Captcha::check()  → backend: ¿llegó el POST?, ¿pasó?, feedback en español.
 *   Captcha::widget() → frontend: formulario completo (imagen, recarga, input).
 *
 * check() verifica y consume el reto una sola vez por petición (uso único)
 * y comparte esa caché con valid()/message(); en un GET responde idle sin
 * generar ni consumir nada. El error llega SIN escapar: la vista aplica
 * htmlspecialchars(), como en cualquier template PHP.
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
use Captcha\Exception\RateLimitException;

// ── CONTROLADOR ──────────────────────────────────────────────────────────────
$check = Captcha::check();

/*
 *  Un controlador real también captura el techo anti-flood (generateAttempts
 *  en la config global): la página responde con feedback en español en vez
 *  de fallar con una excepción sin capturar.
 */
$widget = '';
$limitError = null;

try {
    $widget = Captcha::widget();
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
    <title>Captcha — formulario con captcha</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 420px; margin: 0 auto; padding: 2rem 1.25rem; line-height: 1.5; }
        h1 { font-size: 1.35rem; margin: 0 0 1rem; }
        .ok { color: #177233; font-weight: 600; }
        .bad { color: #b42318; font-weight: 600; }
    </style>
</head>
<body>
    <h1>Formulario con captcha</h1>

    <?php if ($check->error !== null): ?>
        <p class="bad"><?= htmlspecialchars($check->error, ENT_QUOTES) ?></p>
    <?php elseif ($check->passed): ?>
        <p class="ok">Código captcha correcto.</p>
    <?php elseif ($limitError !== null): ?>
        <p class="bad"><?= htmlspecialchars($limitError, ENT_QUOTES) ?></p>
    <?php endif; ?>

<?php if ($limitError === null): ?>
    <form method="post">
        <?= $widget ?>
        <p><button type="submit">Enviar</button></p>
    </form>
<?php endif; ?>
</body>
</html>
