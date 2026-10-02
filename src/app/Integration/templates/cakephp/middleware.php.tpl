<?php

declare(strict_types=1);

namespace App\Middleware;

use Cake\Http\Response;
use Captcha\Captcha;
use Captcha\Security\CaptchaGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Corta la petición cuando el captcha no pasa, como middleware PSR-15.
 *
 * Solo actúa sobre el POST. En GET el reto todavía no existe —se genera al
 * renderizar el formulario— así que un middleware que verificara en la primera
 * visita dejaría al usuario con un formulario vacío y un error sin retos que
 * resolver.
 *
 * requireSubmission: true es lo que hace seguro el middleware: con false, un
 * POST que omite el campo oculto captcha_id se considera válido, de modo que
 * borrar ese input bastaría para saltarse la comprobación.
 *
 * La denegación se construye con los métodos inmutables withStatus(), withType()
 * y withStringBody() en vez de pasar un array al constructor: así el código de
 * estado, el tipo y el cuerpo van por rutas que CakePHP interpreta con certeza, y
 * un cambio no se pierde en una clave de opción que el constructor ignore.
 *
 * Regístralo en src/Application.php, dentro de middleware().
 */
final class CaptchaMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        $decision = (new CaptchaGuard(Captcha::instance()))
            ->decide((array) $request->getParsedBody(), requireSubmission: true);

        if ($decision->allowed) {
            return $handler->handle($request);
        }

        return (new Response())
            ->withStatus($decision->httpStatus)
            ->withType('application/json')
            ->withStringBody((string) json_encode(
                ['error' => $decision->message],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
    }
}
