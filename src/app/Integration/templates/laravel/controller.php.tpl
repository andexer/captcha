<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Captcha\Captcha;
use Captcha\Http\Endpoint;
use Illuminate\Http\JsonResponse;

/**
 * Ruta GET que alimenta la recarga AJAX del widget.
 *
 * El endpoint del paquete ya resuelve los tres casos que puede devolver — 200
 * con el reto nuevo, 400 si la acción no existe y 429 si el techo anti-flood
 * está superado — así que este controlador se limita a traducir su tupla a una
 * respuesta de Laravel. La única decisión que toma es qué hacer cuando el
 * parámetro action no es una cadena: ?action[]=x llega como array y pasarlo tal
 * cual a dispatch() sería un error de tipos en vez de un 400.
 *
 * Publica la ruta en routes/web.php:
 *
 *     Route::get('/captcha/generate', [CaptchaController::class, 'generate']);
 *
 * Después indícale al widget dónde vive:
 *
 *     Captcha::widget(['endpoint' => '/captcha/generate']);
 */
final class CaptchaController extends Controller
{
    public function generate(): JsonResponse
    {
        $action = request()->query('action', 'generate');
        $response = (new Endpoint(Captcha::instance()))
            ->dispatch(is_string($action) ? $action : '');

        return response()->json($response['payload'], $response['status']);
    }
}
