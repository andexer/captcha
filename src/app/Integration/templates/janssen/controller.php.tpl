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
 * Usa Endpoint::dispatch(), no handle(): la respuesta la emite el pipeline de
 * Janssen. Los estados que no son 200 —el 429 del techo anti-flood o el 400
 * de una acción desconocida— salen como JsonResponse con su cabecera y el
 * cuerpo en JSON: con un 200 y ok=false el widget recargaría en bucle.
 *
 * El widget apunta a esta ruta por defecto; solo hace falta declararla al
 * dibujarlo con Captcha::widget(['endpoint' => '/captcha']).
 *
 * No lleva protección: la ruta solo genera retos; el CaptchaGuard decide en
 * el POST, que es donde el reto se consume.
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
