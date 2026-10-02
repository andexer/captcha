<?php

declare(strict_types=1);

/**
 * Demostración completa de captcha.
 *
 * Tres bloques:
 *  - Widget de la capa estática: `Captcha::widget()` + verificación con
 *    `Captcha::check()` (un solo archivo de config o defaults; el CSS/JS
 *    `.min` se inyectan inline con el primer widget).
 *  - Probador interactivo: marca los tipos de captcha matemático (suma/resta/
 *    multiplicación/división), dificultad, ruido, distorsión y longitud, y
 *    comprueba `verify()` (Ok vs Invalid/Missing/Expired). El challenge es
 *    de un solo uso: cada intento consume la entrada.
 *  - Galería de variantes vía `?render=<clave>`.
 *
 * El botón de recarga del widget usa `src/public/endpoint.php` (su Config debe
 * coincidir con el del formulario: compartes sesión).
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
use Captcha\Exception\RateLimitException;
use Captcha\Storage\SessionStorage;
use Captcha\Verification\Status;

session_start();

const GALLERY = [
    'dificultad-baja' => new Config(difficulty: Difficulty::Low),
    'dificultad-media' => new Config(difficulty: Difficulty::Medium),
    'dificultad-alta' => new Config(difficulty: Difficulty::High),
    'sin ruido' => new Config(noise: false),
    'sin distorsión' => new Config(distortion: false),
    'sin ruido ni distorsión' => new Config(noise: false, distortion: false),
    'grande (360×120)' => new Config(width: 360, height: 120),
    'compacto (120×40)' => new Config(width: 120, height: 40),
    'código corto (3)' => new Config(length: 3),
    'código largo (10)' => new Config(length: 10),
    'suma y resta' => new Config(operations: [Operation::Add, Operation::Subtract]),
    'solo suma' => new Config(operations: [Operation::Add]),
    'solo resta' => new Config(operations: [Operation::Subtract]),
    'solo multiplicación' => new Config(operations: [Operation::Multiply]),
    'solo división' => new Config(operations: [Operation::Divide]),
    'todo (4 tipos)' => new Config(operations: [Operation::Add, Operation::Subtract, Operation::Multiply, Operation::Divide]),
];

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$postMain = $isPost && array_key_exists('captcha_id', $_POST);
$postTester = $isPost && !$postMain && array_key_exists('code', $_POST);

if (isset($_GET['render'])) {
    serveGalleryImage((string) $_GET['render']);

    return;
}

if (isset($_GET['refresh'])) {
    header('Content-Type: application/json; charset=utf-8');

    /*
    *  El recargador del widget pide un reto nuevo por AJAX. El límite de
    *  generación es por IP, no por sesión: llega 429 con un cuerpo JSON que el
    *  JavaScript sabe leer, no una excepción sin capturar que rompa la demo con
    *  un error 500 en el navegador.
    */
    try {
        $options = currentOpts();
        $challenge = (new Captcha(new SessionStorage(), optsConfig($options)))->generate();
        $_SESSION['pc_test_challenge'] = $challenge->getId();

        http_response_code(200);
        echo json_encode(['ok' => true, 'image' => $challenge->getDataUri(), 'id' => $challenge->getId()], JSON_UNESCAPED_SLASHES);
    } catch (RateLimitException $e) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }

    return;
}

$mainCheck = null;
$mainWidget = '';
$mainLimitError = null;

if ($postMain) {
    $mainCheck = Captcha::check();
}

/*
 *  El widget de la capa estática hereda la config descubierta (con límite de
 *  generación); un controlador real captura el 429 y muestra feedback.
 */
try {
    $mainWidget = Captcha::widget(['theme' => 'light']);
} catch (RateLimitException $e) {
    $mainLimitError = $e->getMessage();
}

$testerResult = null;
$testerLimitError = null;
$challenge = null;
$opts = currentOpts();

if ($postTester) {
    $storedId = is_string($_SESSION['pc_test_challenge'] ?? null) ? $_SESSION['pc_test_challenge'] : '';
    $input = (string) ($_POST['code'] ?? '');
    $opts = storedOpts(
        isset($_POST['addition']),
        isset($_POST['subtraction']),
        isset($_POST['multiplication']),
        isset($_POST['division']),
        isset($_POST['noise']),
        isset($_POST['distortion']),
        (string) ($_POST['difficulty'] ?? ''),
        (string) ($_POST['length'] ?? ''),
    );
    $testerResult = (new Captcha(new SessionStorage(), optsConfig($opts)))->verify($storedId, $input);
}

/*
 *  El probador genera su propio reto (con la config que marquaste) y por eso
 *  puede toparse con el límite de generación en una visita en la que el widget
 *  principal aún iba bien. Se muestra el aviso en el panel en lugar de dejar la
 *  excepción sin capturar: al recargar en unos segundos vuelve a funcionar.
 */
try {
    $challenge = (new Captcha(new SessionStorage(), optsConfig($opts)))->generate();
    $_SESSION['pc_test_challenge'] = $challenge->getId();
} catch (RateLimitException $e) {
    $testerLimitError = $e->getMessage();
}

/**
 * @return array{difficulty: Difficulty, noise: bool, distortion: bool, length: int, operations: list<Operation>}
 */
function currentOpts(): array
{
    $saved = $_SESSION['pc_test_opts'] ?? [
        'difficulty' => Difficulty::Medium,
        'noise' => true,
        'distortion' => true,
        'length' => 5,
        'operations' => [Operation::Add, Operation::Subtract],
    ];

    /*
    *  Migra sesiones guardadas con el formato antiguo (clave única `operation`
    *  en lugar del conjunto `operations`), p. ej. tras actualizar la demo.
    */
    if (!isset($saved['operations'])) {
        $operations = [];
        $operation = $saved['operation'] ?? null;

        if ($operation instanceof Operation) {
            $operations[] = $operation;
        } else {
            $operation = Operation::tryFrom((string) $operation);
            if ($operation !== null) {
                $operations[] = $operation;
            }
        }

        $saved['operations'] = $operations;
        $_SESSION['pc_test_opts'] = $saved;
    }

    return [
        'difficulty' => $saved['difficulty'],
        'noise' => $saved['noise'],
        'distortion' => $saved['distortion'],
        'length' => $saved['length'],
        'operations' => $saved['operations'],
    ];
}

/**
 * @return array{difficulty: Difficulty, noise: bool, distortion: bool, length: int, operations: list<Operation>}
 */
function storedOpts(
    bool $addition,
    bool $subtraction,
    bool $multiplication,
    bool $division,
    bool $noise,
    bool $distortion,
    string $difficulty,
    string $length,
): array {
    $operations = [];
    if ($addition) {
        $operations[] = Operation::Add;
    }
    if ($subtraction) {
        $operations[] = Operation::Subtract;
    }
    if ($multiplication) {
        $operations[] = Operation::Multiply;
    }
    if ($division) {
        $operations[] = Operation::Divide;
    }

    $options = [
        'difficulty' => match ($difficulty) {
            'low' => Difficulty::Low,
            'high' => Difficulty::High,
            default => Difficulty::Medium,
        },
        'noise' => $noise,
        'distortion' => $distortion,
        'length' => match ($length) {
            '3' => 3,
            '10' => 10,
            default => 5,
        },
        'operations' => $operations,
    ];

    $_SESSION['pc_test_opts'] = $options;

    return $options;
}

/**
 * Rótulo en español del estado de verificación (el valor del enum es un
 * identificador de máquina y jamás debe mostrarse al usuario).
 */
function statusLabel(Status $status): string
{
    return match ($status) {
        Status::Ok => 'Correcto',
        Status::Invalid => 'Incorrecto',
        Status::Expired => 'Caducado',
        Status::Missing => 'No encontrado',
        Status::Blocked => 'Bloqueado',
    };
}

/**
 * @param array{difficulty: Difficulty, noise: bool, distortion: bool, length: int, operations: list<Operation>} $options
 */
function optsConfig(array $options): Config
{
    return new Config(
        difficulty: $options['difficulty'],
        noise: $options['noise'],
        distortion: $options['distortion'],
        length: $options['length'],
        operations: $options['operations'],
    );
}

function serveGalleryImage(string $key): void
{
    if (!array_key_exists($key, GALLERY)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Variante desconocida.';

        return;
    }

    $result = (new Captcha(new SessionStorage(), GALLERY[$key]))->generate();

    header('Content-Type: ' . $result->getMimeType());
    header('Cache-Control: no-store');
    echo $result->getImage();
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo de captcha</title>
    <style>
        :root { color-scheme: light; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.5;
            max-width: 1120px;
            margin: 0 auto;
            padding: 2rem 1.25rem 3rem;
            background: #f7f8fa;
            color: #1f2430;
        }
        h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
        .lead { color: #667085; margin: 0 0 1.5rem; }
        .links { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-bottom: 1.5rem; }
        .links a { color: #3b82f6; }
        .panel { background: #fff; border: 1px solid #e2e6ee; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 1px 2px rgba(16, 24, 40, .05); }
        .panel h2 { font-size: 1.15rem; margin: 0 0 .5rem; }
        .panel p { margin: 0 0 .75rem; color: #4b5563; }
        .row { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; margin: .5rem 0; }
        .tester label { display: inline-flex; gap: .4rem; align-items: center; font-size: .9rem; }
        .tester select, .tester input[type="text"] { font: inherit; padding: .35rem .5rem; border: 1px solid #d0d7e2; border-radius: 6px; }
        .tester input[type="text"] { width: 6rem; letter-spacing: .3rem; text-align: center; }
        button { font: inherit; padding: .45rem .9rem; border: 1px solid #d0d7e2; border-radius: 6px; background: #fff; color: inherit; cursor: pointer; }
        button:hover { border-color: #3b82f6; }
        button:focus-visible, .gallery a:focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; }
        .result { display: inline-block; font-weight: 600; padding: .4rem .75rem; border-radius: 8px; margin-top: .5rem; }
        .result.ok { background: #d9f2df; color: #177233; }
        .result.bad { background: #fde3e1; color: #b42318; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
        .card { border: 1px solid #e2e6ee; border-radius: 12px; padding: 1rem; background: #fff; }
        .card h3 { font-size: .95rem; margin: 0 0 .75rem; }
        .captcha-box { position: relative; display: inline-block; }
        .captcha-box img { display: block; border: 1px solid #e2e6ee; border-radius: 8px; background: #fff; max-width: 100%; height: auto; }
        .captcha-box .reload {
            position: absolute; left: 6px; bottom: 6px;
            background: linear-gradient(180deg, #ffffff, #f1f3f7);
            border: 1px solid #d0d7e2; border-radius: 999px;
            font-size: .9rem; line-height: 1; padding: .35rem .5rem; cursor: pointer;
        }
        .captcha-box .reload:disabled { opacity: .45; cursor: default; }
        code { background: #f1f3f7; padding: .1rem .35rem; border-radius: 4px; font-size: .85em; }
        .theme-toggle { display: inline-flex; gap: .4rem; }
        .theme-toggle button[aria-pressed="true"] { border-color: #3b82f6; background: #e7efff; }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation: none !important; }
        }
    </style>
</head>
<body>
    <h1>captcha — demo</h1>
    <p class="lead">Captcha numérico en imagen, sin dependencias. Ajusta los parámetros, resuelve el código y pulsa verificar.</p>

    <nav class="links">
        <a href="01-basic.php">01 básico</a>
        <a href="02-form.php">02 formulario</a>
        <a href="03-file-storage.php">03 almacenamiento en archivo</a>
        <a href="04-rest-api.php">04 REST API</a>
        <a href="simple.php">simple</a>
        <a href="matematico.php">matemático</a>
        <a href="../public/endpoint.php?action=generate">endpoint JSON</a>
    </nav>

    <section class="panel">
        <h2>Widget de la capa estática</h2>
        <p>Un archivo de configuración (o los defaults) y dos llamadas:
            <code>Captcha::check()</code> y <code>Captcha::widget()</code>. El CSS y el JS
            (versiones <code>.min</code>) se inyectan inline con el primer widget; el botón
            recarga solo la imagen vía <code>src/public/endpoint.php</code>.</p>

        <?php if ($mainLimitError !== null): ?>
            <span class="result bad"><?= htmlspecialchars($mainLimitError, ENT_QUOTES) ?></span>
        <?php else: ?>
        <form method="post">
            <?= $mainWidget ?>
            <p><button type="submit">Verificar</button></p>
        </form>
        <?php endif; ?>

        <p>Tema: esta demo está fijada en claro; si quieres probar el modo oscuro,
            el botón «Oscuro» fuerza <code>data-theme</code>, y «Auto» sigue la
            preferencia del sistema.
            <span class="theme-toggle" role="group" aria-label="Tema del widget">
                <button type="button" data-theme="auto">Auto</button>
                <button type="button" data-theme="light" aria-pressed="true">Claro</button>
                <button type="button" data-theme="dark">Oscuro</button>
            </span>
        </p>

        <?php if ($postMain && $mainCheck !== null): ?>
            <span class="result <?= $mainCheck->passed ? 'ok' : 'bad' ?>">
                <?= htmlspecialchars($mainCheck->passed ? 'Código captcha correcto.' : (string) $mainCheck->error, ENT_QUOTES) ?>
            </span>
        <?php endif; ?>
    </section>

    <section class="panel tester">
        <h2>Probador interactivo</h2>
        <p>Marca los tipos de captcha matemático que quieras (si no marcas ninguno
            sale la imagen de dígitos): la imagen muestra <code>a + b</code>,
            <code>a − b</code>, <code>a × b</code> o <code>a ÷ b</code> y tú solo
            escribes el <strong>resultado</strong>. La dificultad gradúa los números
            (baja = de 1 dígito, media = hasta 2, alta = hasta 3). El challenge se
            consume en cada intento (uso único).</p>

        <form method="post">
            <div class="captcha-box">
                <?php if ($challenge !== null): ?>
                    <img src="<?= $challenge->getDataUri() ?>" alt="captcha a resolver"
                         width="360" height="120" data-refresh-endpoint="index.php?refresh=1">
                    <button type="button" class="reload" aria-label="Recargar captcha"
                            title="Recargar captcha">&#10227;</button>
                <?php else: ?>
                    <p class="result bad"><?= htmlspecialchars((string) $testerLimitError, ENT_QUOTES) ?></p>
                <?php endif; ?>
            </div>

            <div class="row">
                <span>Tipos:
                    <label><input type="checkbox" name="addition" value="1"<?= in_array(Operation::Add, $opts['operations'], true) ? ' checked' : '' ?>> suma</label>
                    <label><input type="checkbox" name="subtraction" value="1"<?= in_array(Operation::Subtract, $opts['operations'], true) ? ' checked' : '' ?>> resta</label>
                    <label><input type="checkbox" name="multiplication" value="1"<?= in_array(Operation::Multiply, $opts['operations'], true) ? ' checked' : '' ?>> multiplicación</label>
                    <label><input type="checkbox" name="division" value="1"<?= in_array(Operation::Divide, $opts['operations'], true) ? ' checked' : '' ?>> división</label>
                </span>
                <label>Dificultad
                    <select name="difficulty">
                        <option value="low"<?= $opts['difficulty'] === Difficulty::Low ? ' selected' : '' ?>>Baja</option>
                        <option value="medium"<?= $opts['difficulty'] === Difficulty::Medium ? ' selected' : '' ?>>Media</option>
                        <option value="high"<?= $opts['difficulty'] === Difficulty::High ? ' selected' : '' ?>>Alta</option>
                    </select>
                </label>
                <label>Longitud máxima del resultado
                    <select name="length">
                        <option value="3"<?= $opts['length'] === 3 ? ' selected' : '' ?>>3</option>
                        <option value="5"<?= $opts['length'] === 5 ? ' selected' : '' ?>>5</option>
                        <option value="10"<?= $opts['length'] === 10 ? ' selected' : '' ?>>10</option>
                    </select>
                </label>
                <label><input type="checkbox" name="noise" value="1"<?= $opts['noise'] ? ' checked' : '' ?>> ruido</label>
                <label><input type="checkbox" name="distortion" value="1"<?= $opts['distortion'] ? ' checked' : '' ?>> distorsión</label>
            </div>

            <div class="row">
                <label>Código: <input type="text" name="code" maxlength="10" autocomplete="off" required></label>
                <button type="submit">Verificar</button>
            </div>
        </form>

        <?php if ($testerResult !== null): ?>
            <span class="result <?= $testerResult->isValid() ? 'ok' : 'bad' ?>">
                <?= htmlspecialchars(statusLabel($testerResult->getStatus()), ENT_QUOTES) ?>: <?= htmlspecialchars($testerResult->getMessage(), ENT_QUOTES) ?>
            </span>
            <span>(la imagen de arriba ya es un captcha nuevo)</span>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Galería de variantes</h2>
        <div class="grid">
            <?php foreach (GALLERY as $key => $config): ?>
                <div class="card">
                    <h3><?= htmlspecialchars($key, ENT_QUOTES) ?></h3>
                    <div class="captcha-box">
                        <img src="index.php?render=<?= rawurlencode($key) ?>" alt="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                             data-render="<?= htmlspecialchars($key, ENT_QUOTES) ?>" width="360" height="120">
                        <button type="button" class="reload" aria-label="Recargar captcha"
                                title="Recargar captcha">&#10227;</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <script>
        document.querySelectorAll('.theme-toggle button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var theme = this.dataset.theme;
                document.querySelectorAll('[data-captcha]').forEach(function (widget) {
                    widget.setAttribute('data-theme', theme);
                });
                document.querySelectorAll('.theme-toggle button').forEach(function (b) {
                    b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
                });
            });
        });

        document.querySelectorAll('.captcha-box .reload').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var box = this.closest('.captcha-box');
                var img = box.querySelector('img');
                var done = function (b, i) { b.disabled = false; i.onload = null; i.onerror = null; };

                this.disabled = true;

                if (img.dataset.refreshEndpoint) {
                    fetch(img.dataset.refreshEndpoint)
                        .then(function (r) { if (!r.ok) { throw new Error('Estado HTTP ' + r.status); } return r.json(); })
                        .then(function (d) { if (d.image) { img.src = d.image; } done(btn, img); })
                        .catch(function () { done(btn, img); });
                    return;
                }

                img.src = 'index.php?render=' + encodeURIComponent(img.dataset.render) + '&v=' + Date.now();
                img.onload = function () { done(btn, img); };
                img.onerror = function () { done(btn, img); };
            });
        });
    </script>
</body>
</html>
