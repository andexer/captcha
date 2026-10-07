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
 * después de DecryptRoute (quien fija la acción del usuario): montándose
 * antes, el captcha se decide sin haber comparado nunca las credenciales.
 *
 * Un preprocesador es global y corre en todas las peticiones, así que lo que
 * decide es a qué POST corta, y eso está declarado en la constante ACCIONES,
 * arriba del todo: a la izquierda la acción en 'controladora@metodo', a la
 * derecha la ruta a la que vuelve el formulario al rechazarlo. Las acciones
 * ausentes pasan de largo, de modo que uno solo protege login y registro.
 *
 * Una lista vacía corta todos los POST, como el middleware de Laravel y el
 * filter de CodeIgniter: encaja cuando el único formulario con captcha es el
 * que quieres proteger. Sin acción que devolver se vuelve a la raíz, porque no
 * hay un formulario único al que regresar.
 *
 * Delega el veredicto en el guard del paquete y no en Captcha::check() porque
 * decide() trae el estado HTTP y el mensaje ya resueltos, y exige
 * requireSubmission: true: sin él, un POST sin campo oculto se decide como "no
 * había envío" y pasa.
 *
 * Lo que no pasa vuelve al formulario con un flash de Janssen; el mensaje del
 * paquete está ya en español. El reto se consume siempre, así que el usuario
 * tiene que resolver uno nuevo.
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