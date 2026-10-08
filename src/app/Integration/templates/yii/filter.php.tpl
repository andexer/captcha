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
 * beforeAction() devuelve bool: un false basta para abortar, y
 * parent::beforeAction() es lo que deja seguir al resto de behaviors. El
 * método se filtra antes de verificar porque en GET el reto aún no existe.
 *
 * requireSubmission: true es lo que cierra el agujero: con false, un POST que
 * omite el campo oculto captcha_id pasaría como válido.
 *
 * La denegación lanza HttpException con el estado del guard; si tu login
 * prefiere reponer el formulario, sustituye el throw por un flash más un
 * redirect a la acción anterior.
 *
 * Regístralo a nivel superior de config/web.php, con el prefijo as:
 *
 *     'as captcha' => \app\filters\CaptchaFilter::class,
 *
 * Las behaviors a secas no son una clave de config válida a ese nivel: Yii
 * lanza InvalidCallException.
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
