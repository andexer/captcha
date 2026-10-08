<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Captcha\Captcha;
use Captcha\Http\Endpoint;
use Illuminate\Http\JsonResponse;

/**
 * Ruta GET que alimenta la recarga AJAX del widget.
 *
 * Publica la ruta en routes/web.php:
 *
 *     Route::get('/captcha/generate', [CaptchaController::class, 'generate']);
 *
 * Después indícale al widget dónde vive:
 *
 *     Captcha::widget(['endpoint' => '/captcha/generate']);
 *
 * Un action no cadena (?action[]=x) se manda vacío para que el endpoint
 * responda 400 en vez de un error de tipos.
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
