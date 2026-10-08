<?php

declare(strict_types=1);

namespace app\controllers;

use Captcha\Captcha;
use Captcha\Http\Endpoint;
use yii\web\Controller;
use yii\web\Response;

/**
 * Ruta GET que alimenta la recarga AJAX del widget.
 *
 * Acción inline (actionGenerate): Yii la resuelve por el nombre del método,
 * sin tocar actions() ni controllerMap. El alias @app resuelve controllers/
 * desde la raíz del proyecto, así que no hay que mapear el autoloading.
 *
 * Sin enablePrettyUrl la ruta es index.php?r=captcha/generate; con él,
 * /captcha/generate. Apunta el widget con ['endpoint' => ...] a la que vale.
 *
 * Un action no cadena (?action[]=x) se manda vacío para que el endpoint
 * responda 400 en vez de un error de tipos.
 */
final class CaptchaController extends Controller
{
    public function actionGenerate(): Response
    {
        $action = $this->request->get('action', 'generate');
        $response = (new Endpoint(Captcha::instance()))
            ->dispatch(is_string($action) ? $action : '');

        $this->response->format = Response::FORMAT_JSON;
        $this->response->data = $response['payload'];

        return $this->response->setStatusCode($response['status']);
    }
}
