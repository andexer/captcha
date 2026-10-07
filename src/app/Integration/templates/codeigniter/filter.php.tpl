<?php

declare(strict_types=1);

namespace App\Filters;

use Captcha\Captcha;
use Captcha\Security\CaptchaGuard;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Corta la petición cuando el captcha no pasa, como filter de CodeIgniter 4.
 *
 * El filtro corre en ambos sentidos, así que after() devuelve la respuesta sin
 * tocarla: toda la lógica vive en before(), que es donde CodeIgniter permite
 * interrumpir el flujo devolviendo algo distinto de null.
 *
 * El método se compara en minúsculas porque no todos los servidores normalizan
 * el verbo: un POST en mayúsculas daría false con un === y el filtro dejaría
 * pasar la petición sin verificar.
 *
 * requireSubmission: true es lo que cierra el agujero: con false, un POST que
 * omite el campo oculto captcha_id se daría por válido y bastaría con borrar ese
 * input para saltarse la comprobación.
 *
 * Regístralo en app/Config/Filters.php: el alias en $aliases y la activación
 * en $methods, por ejemplo ['POST' => ['captcha']]. El alias por sí solo no
 * prende nada: CodeIgniter solo lo usa cuando algo (una ruta, un filtro, los
 * globales o $methods) lo referencia.
 */
final class CaptchaFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments Alias del filtro; no se usa.
     */
    public function before(
        RequestInterface $request,
        ?array $arguments = null,
    ): ?RedirectResponse {
        if (strtolower((string) $request->getMethod()) !== 'post') {
            return null;
        }

        $decision = (new CaptchaGuard(Captcha::instance()))
            ->decide($request->getPost(), requireSubmission: true);

        return $decision->allowed
            ? null
            : redirect()->back()->with('error', $decision->message);
    }

    /**
     * @param list<string>|null $arguments Alias del filtro; no se usa.
     */
    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        ?array $arguments = null,
    ): ResponseInterface {
        return $response;
    }
}
