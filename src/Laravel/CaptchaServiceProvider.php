<?php

declare(strict_types=1);

namespace Captcha\Laravel;

use Captcha\Captcha;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor de auto-discovery de Laravel: composer.json lo declara en
 * extra.laravel.providers, así que el framework lo registra solo (Laravel 11+
 * y toda app que no lo haya desactivado en dont-discover), sin pegamento.
 *
 * Laravel carga por su cuenta los ficheros de config/ —config('captcha')
 * devuelve el array de config/captcha.php—, de modo que aquí no se descubre
 * nada: se fija lo que el anfitrión ya preparó, con la misma precedencia que
 * Captcha::configure() en cualquier otro arranque. Sin config, config('captcha')
 * es null y manda el descubrimiento de la capa estática, que encuentra el
 * mismo fichero.
 *
 * Apps sin auto-discovery o con dont-discover siguen cubiertas: `captcha
 * install laravel` emite su provider con esta misma línea de boot().
 */
final class CaptchaServiceProvider extends ServiceProvider
{
    /**
     * Fija en la capa estática la configuración que el anfitrión ya cargó.
     */
    public function boot(): void
    {
        $opciones = config('captcha');

        if (is_array($opciones)) {
            Captcha::configure($opciones);
        }
    }
}
