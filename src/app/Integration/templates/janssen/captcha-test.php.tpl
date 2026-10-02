<?php

declare(strict_types=1);

/*
 * Banco de pruebas del widget: página que dibuja el captcha con su CSS y su
 * JS inline (injectAssets por defecto), sin enlazar ningún asset del paquete.
 *
 * Sirve para ver de un vistazo el reto que emite el paquete, su botón de
 * recarga (que pega contra el endpoint /captcha) y los mensajes que el propio
 * widget pinta, sin nada en medio. La verificación del código se ejercita en
 * tu formulario protegido por el CaptchaGuard; aquí no hay POST que enviar.
 *
 * Regístrala en app/Config/routes.php para encenderla:
 *
 *   ['path' => '/captcha-test',
 *    'resolver' => 'captcha-test.php',
 *    'guard' => 'nobody'],
 */

$href = static fn (string $path): string => Janssen\Engine\Route::hrefTo($path);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Captcha · Janssen</title>
</head>
<body>
<main>
    <h1>Captcha</h1>

    <div class="box">
        <?= Captcha\Captcha::widget(['endpoint' => $href('/captcha')]) ?>
    </div>

    <p>
        El botón redondo de la imagen recarga el reto por AJAX contra
        <code>/captcha</code>; con cada reto nuevo viaja el campo oculto
        <code>captcha_id</code>. El CSS y el JS los inyecta el propio widget.
    </p>
</main>
</body>
</html>
