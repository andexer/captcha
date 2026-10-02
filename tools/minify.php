<?php

declare(strict_types=1);

/**
 * Minificador de assets por CLI para los assets del widget incluidos en el
 * paquete — el paso de build que faltaba. La lógica es pura
 * (Build\AssetMinifier); este script solo hace el I/O de ficheros, en la
 * línea del reparto Doctor/Installer.
 *
 * Uso: php tools/minify.php  (o composer assets:min)
 * Ejecútalo tras editar los assets legibles; `composer check` falla si las
 * copias .min distribuidas divergen de la fuente.
 */

use Captcha\Build\AssetMinifier;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$targets = [
    [$root . '/src/resources/assets/captcha.css', $root . '/src/resources/assets/captcha.min.css', false],
    [$root . '/src/resources/assets/captcha.js', $root . '/src/resources/assets/captcha.min.js', true],
];

foreach ($targets as [$source, $target, $stripLineComments]) {
    $raw = file_get_contents($source);

    if ($raw === false) {
        fwrite(STDERR, "No se pudo leer {$source}\n");
        exit(1);
    }

    $minified = AssetMinifier::minify($raw, $stripLineComments);

    if (file_put_contents($target, $minified) === false) {
        fwrite(STDERR, "No se pudo escribir {$target}\n");
        exit(1);
    }

    printf(
        "%s: %d → %d bytes\n",
        basename($target),
        strlen($raw),
        strlen($minified),
    );
}
