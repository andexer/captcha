<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 *  El stub de Illuminate solo entra si el anfitrión no aportó el paquete real:
 *  define la base del ServiceProvider y el helper config() que usa el
 *  proveedor de auto-discovery (src/Laravel/CaptchaServiceProvider.php).
 *  Es un único fichero que crea la clase y la función, así que basta con
 *  comprobar la clase para cargarlo una sola vez.
 */
if (!class_exists(\Illuminate\Support\ServiceProvider::class)) {
    require dirname(__DIR__) . '/tests/stubs/Illuminate/Support/ServiceProvider.php.tpl';
}
