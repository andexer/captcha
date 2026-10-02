<?php

declare(strict_types=1);

namespace App\Controllers;

use Captcha\Http\Endpoint;
use CodeIgniter\HTTP\ResponseInterface;

/*
 * El import de la fachada va con alias porque esta clase se llama Captcha: sin
 * él, los dos nombres coinciden y el fichero ni siquiera compila.
 */
use Captcha\Captcha as CaptchaPackage;

/**
 * Ruta GET que alimenta la recarga AJAX del widget.
 *
 * El endpoint del paquete ya resuelve 200 con el reto, 400 si la acción no
 * existe y 429 al superar el techo anti-flood, así que el controlador se limita
 * a volcar su tupla. Lo único que decide es qué hacer con un action que no sea
 * cadena: ?action[]=x llega como array desde CodeIgniter y pasarlo tal cual a
 * dispatch() sería un error de tipos en vez de un 400.
 *
 * No lleva protección: la ruta solo genera retos. Quien tenga que validar un
 * código lo hace el CaptchaFilter aplicado al POST que lo recibe, y el POST
 * sigue siendo el único sitio donde se consume el reto.
 *
 * Regístrala en app/Config/Routes.php, dentro de $routes->get().
 */
final class Captcha extends BaseController
{
    public function generate(): ResponseInterface
    {
        $action = service('request')->getGet('action') ?? 'generate';
        $response = (new Endpoint(CaptchaPackage::instance()))
            ->dispatch(is_string($action) ? $action : '');

        return $this->response
            ->setStatusCode($response['status'])
            ->setContentType('application/json')
            ->setBody((string) json_encode($response['payload']));
    }
}
