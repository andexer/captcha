<?php

declare(strict_types=1);

/**
 * Ejemplo 3: storage en disco ( FileStorage ) — útil sin sesiones PHP.
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
use Captcha\Storage\FileStorage;

$storageDir = sys_get_temp_dir() . '/captcha-example';

/*
 *  `generateAttempts: 0` desactiva el techo anti-flood solo para esta demo, que
 *  se recarga sin parar mientras la pruebas. En una app real déjalo en su
 *  default (20 por ventana) y captura RateLimitException para responder 429.
 */
$captcha = new Captcha(
    storage: new FileStorage($storageDir),
    config: new Config(
        length: 6,
        ttl: 120,
        difficulty: Difficulty::High,
        generateAttempts: 0,
    ),
);

$result = $captcha->generate();

echo 'ID del challenge: ' . $result->getId() . PHP_EOL;
echo 'Imagen:           ' . $result->getDataUri() . PHP_EOL . PHP_EOL;

/*
 *  En un caso real el código vendría del usuario. Aquí lo leemos del
 *  storage para demostrar el ciclo completo:
 */
$expected = $storageDir . '/' . hash('sha256', $result->getId()) . '.captcha.json';
$entry = json_decode((string) file_get_contents($expected), true);

$ok = $captcha->verify($result->getId(), (string) $entry['code']);
echo 'Verificación: ' . $ok->getMessage() . PHP_EOL;

// Segundo intento: el challenge ya fue consumido.
$again = $captcha->verify($result->getId(), (string) $entry['code']);
echo 'Reintento:    ' . $again->getMessage() . PHP_EOL;
