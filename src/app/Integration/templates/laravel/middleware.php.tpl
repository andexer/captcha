<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Captcha\Captcha;
use Captcha\Security\CaptchaGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta la petición cuando el captcha no pasa.
 *
 * Solo actúa sobre el POST: un GET llega intacto al pipeline y el widget dibuja
 * el reto en la respuesta. Si el middleware verificara también la primera
 * visita, el usuario vería un formulario vacío con un error que no sabe
 * resolver.
 *
 * requireSubmission: true es lo que hace seguro este middleware. Sin ese
 * argumento, una petición POST que ni siquiera trae el campo oculto captcha_id
 * pasa como válida, y basta con que alguien omita ese input para saltarse la
 * comprobación entera.
 *
 * Registra el middleware en bootstrap/app.php, dentro de ->withMiddleware()
 * (Laravel 11 y superior); en Laravel 9 y 10 el sitio es app/Http/Kernel.php.
 */
final class CaptchaGuardMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('post')) {
            return $next($request);
        }

        $decision = (new CaptchaGuard(Captcha::instance()))
            ->decide($request->request->all(), requireSubmission: true);

        if ($decision->allowed) {
            return $next($request);
        }

        /*
         * Se repone el input menos el código tecleado: volver a pintar el
         * captcha ya consumido es trabajo del widget, no del formulario, y
         * dejar el código en el HTML de vuelta lo convertiría en un regalo
         * para quien intente leerlo.
         */
        return redirect()->back()
            ->withInput($request->except('captcha'))
            ->withErrors(['captcha' => $decision->message]);
    }
}
