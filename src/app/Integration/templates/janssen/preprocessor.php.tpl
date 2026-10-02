<?php

declare(strict_types=1);

namespace App\Preprocessor;

use Captcha\Captcha;
use Captcha\Security\CaptchaGuard as Guard;
use Captcha\Security\GuardDecision;
use Janssen\Engine\Preprocessor;
use Janssen\Engine\Request;
use Janssen\Engine\Route;

/**
 * Corta el POST del formulario cuando el captcha no es válido.
 *
 * Regístralo en app/Config/engine.php, en 'preprocessors', para POST y justo
 * después de DecryptRoute (que es quien fija la acción del usuario). Al
 * montarse antes, el captcha se decide sin haber comparado nunca las credenciales.
 *
 * El preprocesador es global, así que decide() solo se llama en la acción que
 * quieres proteger: el resto de POST pasan de largo. Cambia 'Auth' y 'login'
 * por tu controlador y tu método.
 *
 * Delega el veredicto en el guard del paquete y no en Captcha::check() porque
 * decide() trae el estado HTTP y el mensaje ya resueltos, y porque exige
 * requireSubmission: true. Ese último argumento es lo que cierra el agujero
 * silencioso: sin él, un POST que no trae el campo oculto se decide como "no
 * había envío" y pasa.
 *
 * Lo que no pasa el captcha vuelve al formulario con un flash de Janssen; el
 * mensaje del paquete está ya en español. El reto se consume siempre, así que
 * el usuario tiene que resolver uno nuevo.
 */
final class CaptchaGuard extends Preprocessor
{
    public function handle(Request $request)
    {
        if (!$this->esLaAccionProtegida()) {
            return true;
        }

        $decision = (new Guard(Captcha::instance()))->decide($_POST, requireSubmission: true);

        return $decision->allowed ? true : $this->rechaza($decision);
    }

    public function handleError()
    {
        return false;
    }

    private function esLaAccionProtegida()
    {
        $action = Request::getUserAction();
        if (!is_array($action) || !isset($action['controller'], $action['method'])) {
            return false;
        }

        return strtolower(trim((string) $action['controller'], '\\')) === 'auth'
            && strtolower((string) $action['method']) === 'login';
    }

    private function rechaza(GuardDecision $decision)
    {
        return redirect(Route::hrefTo('/login'))->withMessage('captcha_error', $decision->message, 'error');
    }
}
