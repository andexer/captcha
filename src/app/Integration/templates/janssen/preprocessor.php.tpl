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
 * después de DecryptRoute (quien fija la acción): montándose antes, el captcha
 * se decidiría sin haber comparado las credenciales nunca.
 *
 * La constante ACCIONES declara a qué POST corta ('controladora@metodo' =>
 * ruta a la que vuelve el formulario); vacía, corta todos los POST.
 *
 * Delega el veredicto en el guard del paquete con requireSubmission: true y
 * deja el mensaje en español como flash de Janssen; el reto se consume
 * siempre, así que toca resolver uno nuevo.
 */
final class CaptchaGuard extends Preprocessor
{
    /**
     * Acciones cuyo POST se corta, y adónde vuelve el formulario al rechazarlo.
     *
     * La clave es la acción tal como la escribe Request::getUserAction(), en
     * minúsculas y sin la barra inicial del namespace; el comparador normaliza
     * las mayúsculas, así que da igual cómo la escribas aquí.
     *
     * @var array<string, string>
     */
    private const ACCIONES = [
        'auth@login' => '/login',
    ];

    /** Clave del flash donde se deja el mensaje del paquete. */
    private const FLASH = 'captcha_error';

    public function handle(Request $request)
    {
        $accion = $this->accionDeEstaPeticion();

        if (!$this->corta($accion)) {
            return true;
        }

        $decision = (new Guard(Captcha::instance()))
            ->decide(Request::all('post'), requireSubmission: true);

        return $decision->allowed ? true : $this->rechaza($accion, $decision);
    }

    public function handleError()
    {
        return false;
    }

    /**
     * La acción que el usuario pidió, en 'controladora@metodo', o cadena vacía
     * si la petición no trae ninguna.
     */
    private function accionDeEstaPeticion(): string
    {
        $accion = Request::getUserAction();

        if (!is_array($accion) || !isset($accion['controller'], $accion['method'])) {
            return '';
        }

        return strtolower(trim((string) $accion['controller'], '\\') . '@' . $accion['method']);
    }

    /** Si esta acción está entre las declaradas, o si se cortan todas. */
    private function corta(string $accion): bool
    {
        return self::ACCIONES === [] || array_key_exists($accion, self::ACCIONES);
    }

    /** Devuelve el formulario con el mensaje del paquete en un flash. */
    private function rechaza(string $accion, GuardDecision $decision)
    {
        $destino = self::ACCIONES[$accion] ?? '/';

        return redirect(Route::hrefTo($destino))->withMessage(self::FLASH, $decision->message, 'error');
    }
}