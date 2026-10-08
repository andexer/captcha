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
 * Regístrala en app/Config/Routes.php, dentro de $routes->get(); el widget no
 * apunta aquí por defecto:
 *
 *     Captcha::widget(['endpoint' => '/captcha/generate']);
 *
 * No lleva protección: la ruta solo genera retos; el CaptchaFilter valida el
 * POST. Un action no cadena (?action[]=x) se manda vacío para que el endpoint
 * responda 400 en vez de un error de tipos.
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
