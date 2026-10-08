<?php

declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Response;
use Captcha\Captcha;
use Captcha\Http\Endpoint;

/**
 * Ruta GET que alimenta la recarga AJAX del widget.
 *
 * Mapea la ruta en config/routes.php ($routes->scope()); el widget no apunta
 * aquí por defecto:
 *
 *     Captcha::widget(['endpoint' => '/captcha/generate']);
 *
 * No lleva CaptchaMiddleware: esta ruta solo genera retos y el middleware se
 * aplica al POST que los recibe. Un action no cadena (?action[]=x) se manda
 * vacío para que el endpoint responda 400 en vez de un error de tipos.
 */
final class CaptchaController extends AppController
{
    public function generate(): Response
    {
        $action = $this->request->getQuery('action') ?? 'generate';
        $response = (new Endpoint(Captcha::instance()))
            ->dispatch(is_string($action) ? $action : '');

        return $this->response
            ->withStatus($response['status'])
            ->withType('application/json')
            ->withStringBody((string) json_encode($response['payload']));
    }
}
