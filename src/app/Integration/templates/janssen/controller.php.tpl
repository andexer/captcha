<?php

declare(strict_types=1);

namespace App\Controller;

use Captcha\Captcha;
use Captcha\Http\Endpoint;
use Janssen\Engine\Controller;
use Janssen\Engine\Header;
use Janssen\Engine\Request;
use Janssen\Helpers\Response\JsonResponse;

/**
 * Ruta GET que alimenta la recarga AJAX del widget.
 *
 * Se usa Endpoint::dispatch() y no handle(): dispatch() es puro y devuelve el
 * estado y el payload, así que la respuesta la emite Janssen (un array se
 * convierte solo en JsonResponse) en vez de saltarse su pipeline con un exit.
 *
 * Para el estado que no es 200 —el 429 del techo anti-flood al recargar, o el
 * 400 de una acción desconocida— se devuelve una JsonResponse con la cabecera
 * del estado. Importa que el cuerpo siga siendo JSON: el widget solo enseña el
 * mensaje cuando la respuesta llega con error y el cuerpo trae 'error'; un 200
 * con ok=false lo tomaría por un endpoint roto y recargaría la página en bucle.
 *
 * El widget trae este endpoint como destino por defecto, así que la ruta solo
 * hace falta si lo dibujas con Captcha::widget(['endpoint' => '/captcha']).
 *
 * No lleva protección: la ruta solo genera retos. El código lo valida el
 * CaptchaGuard sobre el POST, que es donde el reto se consume.
 */
final class CaptchaController extends Controller
{
    public function generate()
    {
        $accion = Request::get('action', 'generate');
        $response = (new Endpoint(Captcha::instance()))->dispatch(is_string($accion) ? $accion : '');

        if ($response['status'] === 200) {
            return $response['payload'];
        }

        $header = new Header();
        $header->setMessage(' ', $response['status']);

        return (new JsonResponse())->setContent($response['payload'])->setHeader($header);
    }
}
