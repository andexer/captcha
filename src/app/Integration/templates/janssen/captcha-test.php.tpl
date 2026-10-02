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

/* 
* para los errores
$href = static fn (string $path): string => Janssen\Engine\Route::hrefTo($path);
$flashes = Janssen\Helpers\FlashMessage::all();

foreach ($flashes as $flash) {
    $type = $flash['type'] === 'error' ? 'danger' : 'info';
    $message = htmlspecialchars((string) $flash['message']);
    echo "<div class=\"notification is-{$type}\">{$message}</div>\n";
}
*/

?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Captcha · Janssen</title>
    <link rel="stylesheet" href="https://unpkg.com/bulma/css/bulma.min.css">
</head>
<body>
<section class="hero is-fullheight">
    <div class="hero-body">
        <div class="container">
            <div class="columns is-centered">
                <div class="column is-5">

                    <h1 class="title has-text-centered">Demo del Captcha</h1>
                    <p class="subtitle is-6 has-text-centered">
                        Prueba del widget de seguridad
                    </p>

                    <div class="box">
                        <div class="field">
                            <label class="label">Código de seguridad</label>
                            <div class="control">
                                <?= Captcha\Captcha::widget(['endpoint' => '/captcha']) ?> <!-- <= ya tiene css y js embebido -->
                            </div>
                        </div>

                        <div class="content mt-4">
                            <p class="is-size-7 has-text-grey">
                                El botón redondo de la imagen recarga el reto por AJAX contra
                                <code>/captcha</code>; con cada reto nuevo viaja el campo oculto
                                <code>captcha_id</code>. El CSS y el JS los inyecta el propio widget.
                            </p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</section>
</body>
</html>
