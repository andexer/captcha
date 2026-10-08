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
 * Symfony no necesita registrar la clase a mano: con autowiring el servicio se
 * instancia por tipo y solo hay que marcarla como public en config/services.yaml
 * para que la ruta pueda referenciarla.
 *
 * Un action no cadena (?action[]=x) se manda vacío para que el endpoint
 * responda 400 en vez de un error de tipos.
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
