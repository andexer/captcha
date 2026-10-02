<?php

declare(strict_types=1);

namespace App\Providers;

use Captcha\Captcha;
use Illuminate\Support\ServiceProvider;

/**
 * Conecta el config de Laravel con la capa estática del paquete.
 *
 * Laravel lee config/captcha.php a través de config(), de modo que el array que
 * devuelve install --framework=laravel no lo lee nadie por su cuenta: este
 * provider es el que se lo pasa a Captcha::configure().
 *
 * La llamada es idempotente y va en boot() porque el orden de arranque entre el
 * provider y los controladores no está garantizado; ponerlo en register() dejaría
 * el widget sin config si algún middleware corre antes.
 *
 * Registra la clase en bootstrap/providers.php (Laravel 11 y superior) o en
 * config/app.php, clave providers (Laravel 9 y 10).
 */
final class CaptchaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Captcha::configure((array) config('captcha'));
    }
}
