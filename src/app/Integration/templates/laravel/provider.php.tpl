<?php

declare(strict_types=1);

namespace App\Providers;

use Captcha\Captcha;
use Illuminate\Support\ServiceProvider;

/**
 * Conecta el config de Laravel con la capa estática del paquete.
 *
 * Laravel lee config/captcha.php con config(), pero nadie se lo pasa al SDK:
 * este provider se lo entrega a Captcha::configure(). La llamada es
 * idempotente y va en boot() para que el widget no arranque sin config.
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
