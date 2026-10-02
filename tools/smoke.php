<?php

declare(strict_types=1);

/**
 * Prueba de humo out-of-the-box: instala el paquete en una app anfitriona y
 * ejercita la API pública sin configurar nada.
 *
 * La suite de PHPUnit vive dentro del paquete y por lo tanto solo puede
 * comprobar lo que ya es alcanzable desde el propio repositorio. Esta
 * comprobación se ejecuta al revés: recibe la ruta de una app de host donde
 * el paquete se instaló como dependencia y verifica que el consumidor recibe
 * un paquete que funciona, con la Resolución de autoload, el binario y el
 * generador de imagenes ya resueltos por Composer.
 *
 * Lo que protege frente a la deriva de "funciona en el repo pero no al
 * instalar": rutas de resources que no viajan en el archivo, firmas que
 * cambian y el binario que pierde su enlace.
 *
 * Uso: php tools/smoke.php /ruta/a/la/app
 * Las comprobaciones fallidas se imprimen todas y el script termina con 1.
 */

use Captcha\Captcha;
use Captcha\Contract\GeneratorInterface;
use Captcha\Storage\ArrayStorage;

$app = $argv[1] ?? null;

if ($app === null || !is_file($app . '/vendor/autoload.php')) {
    fwrite(STDERR, "Uso: php tools/smoke.php /ruta/a/la/app\n");
    fwrite(STDERR, "La ruta debe contener vendor/autoload.php.\n");
    exit(2);
}

require $app . '/vendor/autoload.php';

$fallos = [];

/**
 * Registra el resultado de una comprobación con su descripción.
 *
 * @param bool $ok Condición cumplida.
 * @param string $que Descripción legible de lo que se comprobaba.
 */
$comprobar = static function (bool $ok, string $que) use (&$fallos): void {
    if (!$ok) {
        $fallos[] = $que;
    }

    printf("[%s] %s\n", $ok ? 'ok' : '!!', $que);
};

/**
 * Generador de código fijo: la prueba sabe de antemano qué se ha generado.
 */
final class GeneradorFijo implements GeneratorInterface
{
    public function generate(int $length): string
    {
        return str_repeat('7', $length);
    }
}

// ── GENERAR SIN CONFIGURAR NADA ──────────────────────────────────────────────

$resultado = Captcha::generate();
$datos = base64_decode(explode(',', $resultado->getDataUri())[1] ?? '', true);
$imagen = $datos === false ? false : @imagecreatefromstring($datos);

$comprobar($resultado->getId() !== '', 'generate() devuelve un identificador de reto');
$comprobar($resultado->getMimeType() === 'image/png', 'generate() devuelve un PNG');
$comprobar($imagen !== false, 'el PNG es decodificable por GD');
$comprobar($imagen !== false && imagesx($imagen) > 0, 'el PNG tiene ancho');

unset($imagen);

// ── VERIFICAR CON EL CÓDIGO QUE SE SABE ──────────────────────────────────────

$captcha = new Captcha(storage: new ArrayStorage(), generator: new GeneradorFijo());
$reto = $captcha->generate();

$correcto = $captcha->verify($reto->getId(), '777777');
$fallido = $captcha->verify($captcha->generate()->getId(), '111111');
$reusado = $captcha->verify($reto->getId(), '777777');

$comprobar($correcto->isValid(), 'verify() acepta el código correcto');
$comprobar(!$fallido->isValid(), 'verify() rechaza un código incorrecto');
$comprobar(!$reusado->isValid(), 'verify() consume el reto y no admite reuso');
$comprobar($correcto->getMessage() !== '', 'verify() devuelve un mensaje legible');

// ── WIDGET Y CONSOLA ─────────────────────────────────────────────────────────

$widget = Captcha::widget();
$comprobar(str_contains($widget, '<form') || str_contains($widget, '<img'), 'widget() devuelve marcado');
$comprobar(!str_contains($widget, '{{'), 'widget() no deja marcadores sin sustituir');

$binario = $app . '/vendor/bin/captcha';
$salida = [];
$codigo = 0;
exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($binario) . ' list 2>&1', $salida, $codigo);

$comprobar(is_file($binario), 'el paquete enlaza vendor/bin/captcha');
$comprobar($codigo === 0, 'el binario responde con código 0');
$comprobar(str_contains(implode("\n", $salida), 'doctor'), 'la consola lista sus comandos');

// ── RESULTADO ────────────────────────────────────────────────────────────────

if ($fallos !== []) {
    fwrite(STDERR, sprintf("\n%d comprobación(es) fallida(s) en la app anfitriona.\n", count($fallos)));
    exit(1);
}

printf("\nTodo correcto en la app anfitriona: %s\n", $app);
