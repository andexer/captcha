<?php

declare(strict_types=1);

namespace App\Controller;

use Captcha\Captcha;
use Captcha\Http\Endpoint;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ruta GET que alimenta la recarga AJAX del widget.
 *
 * El endpoint del paquete ya devuelve 200 con el reto, 400 si la acción no
 * existe y 429 al superar el techo anti-flood, así que el controlador solo
 * traduce su tupla a una JsonResponse. Lo único que decide es qué hacer con un
 * action que no sea una cadena: ?action[]=x llega como array desde Symfony y
 * pasarlo tal cual a dispatch() sería un error de tipos en lugar de un 400.
 *
 * Symfony no necesita registrar la clase a mano: con autowiring el servicio se
 * instancia por tipo y solo hay que marcarla como public en config/services.yaml
 * para que la ruta pueda referenciarla.
 */
final class CaptchaController
{
    public function generate(Request $request): JsonResponse
    {
        $action = $request->query->get('action', 'generate');
        $response = (new Endpoint(Captcha::instance()))
            ->dispatch(is_string($action) ? $action : '');

        return new JsonResponse($response['payload'], $response['status']);
    }
}
