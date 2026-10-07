<?php

declare(strict_types=1);

namespace app\filters;

use Captcha\Captcha;
use Captcha\Security\CaptchaGuard;
use yii\base\ActionFilter;
use yii\web\HttpException;

/**
 * Corta la acción cuando el captcha no pasa, como behavior de Yii.
 *
 * beforeAction() devuelve bool y, al contrario que en un middleware, un false
 * basta para abortar. Se filtra el método antes de verificar porque en GET el
 * reto aún no se ha generado, y parent::beforeAction() es lo que permite que el
 * resto de behaviors del árbol sigan ejecutándose: devolver false aquí
 * cortaría también los que tuvieras más abajo.
 *
 * requireSubmission: true es lo que cierra el agujero. Con false, un POST que
 * omite el campo oculto captcha_id pasaría como válido y borrar ese input
 * bastaría para saltarse la comprobación.
 *
 * La denegación lanza HttpException con el estado que sugiere el guard. Si tu
 * login prefiere reponer el formulario en vez de mostrar un 422, sustituye el
 * throw por un flash más un redirect a la acción anterior.
 *
 * Regístralo a nivel superior de config/web.php, con el prefijo as:
 *
 *     'as captcha' => \app\filters\CaptchaFilter::class,
 *
 * Las behaviors a secas no son una clave de config válida a ese nivel: Yii
 * lanza InvalidCallException. Con el prefijo as la aplicación ensambla el
 * filtro como behavior propio, y Controller::runAction le pasa por delante
 * cada acción antes de ejecutarla.
 */
final class CaptchaFilter extends ActionFilter
{
    public function beforeAction($action): bool
    {
        $request = \Yii::$app->request;

        if (!$request->getIsPost()) {
            return parent::beforeAction($action);
        }

        $decision = (new CaptchaGuard(Captcha::instance()))
            ->decide($request->post(), requireSubmission: true);

        if ($decision->allowed) {
            return parent::beforeAction($action);
        }

        throw new HttpException($decision->httpStatus, $decision->message);
    }
}
