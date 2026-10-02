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
 * El endpoint del paquete ya devuelve 200 con el reto, 400 si la acción no
 * existe y 429 al superar el techo anti-flood, así que la acción solo vuelca
 * esa tupla. Lo único que decide es qué hacer con un action que no sea cadena:
 * ?action[]=x llega como array desde Yii y pasarlo tal cual a dispatch() sería
 * un error de tipos en lugar de un 400.
 *
 * actionGenerate() es una acción inline: Yii la resuelve por el nombre del
 * método, así que no hace falta declararla en actions() —ese mapa es solo para
 * acciones sueltas, que se crean por clase— ni tocar controllerMap.
 *
 * Se da por hecho que el proyecto mapea el namespace app\ a src/ en el psr-4 de
 * Composer. Con la estructura básica de Yii2 mueve el fichero a controllers/ y
 * deja el namespace app\controllers.
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
