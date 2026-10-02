<?php

declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Response;
use Captcha\Captcha;
use Captcha\Http\Endpoint;

/**
 * Ruta GET que alimenta la recarga AJAX del widget.
 *
 * El endpoint del paquete ya devuelve 200 con el reto, 400 si la acción no
 * existe y 429 al superar el techo anti-flood; el controlador solo vuelca esa
 * tupla. Lo único que decide es qué hacer con un action que no sea cadena:
 * ?action[]=x llega como array desde CakePHP y pasarlo tal cual a dispatch()
 * sería un error de tipos en lugar de un 400.
 *
 * No lleva CaptchaMiddleware: esta ruta solo genera retos, no consume ninguno.
 * El reto se consume en el POST que lo recibe, que es donde se aplica el
 * middleware.
 *
 * Mapea la ruta en routes.php, dentro de $routes->scope().
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
