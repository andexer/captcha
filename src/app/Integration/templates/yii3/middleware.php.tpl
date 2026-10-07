<?php

declare(strict_types=1);

namespace App\Web\Captcha;

use Captcha\Captcha;
use Captcha\Security\CaptchaGuard;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Corta la petición cuando el captcha no pasa, como middleware PSR-15.
 *
 * Solo actúa sobre el POST. En GET el reto todavía no existe —se genera al
 * renderizar el formulario— así que verificar en la primera visita dejaría al
 * usuario con el formulario vacío y sin reto que resolver.
 *
 * requireSubmission: true es lo que hace seguro el middleware: con false, un
 * POST que omite el campo oculto captcha_id se considera válido, de modo que
 * borrar ese input bastaría para saltarse la comprobación.
 *
 * La denegación es una respuesta propia con el estado que sugiere el guard y
 * el mensaje en español en el cuerpo, construida con los servicios PSR-17 que
 * el contenedor ya tiene ligados. Si tu aplicación prefiere reponer el
 * formulario en vez de devolver JSON, sustitúyela por un redirect 303 al
 * referrer con un flash de sesión.
 *
 * Regístralo en config/web/di/application.php, dentro de withMiddlewares() y
 * justo antes de Router::class:
 *
 *     App\Web\Captcha\GuardMiddleware::class,
 */
final class GuardMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

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

        return $this->responseFactory
            ->createResponse($decision->httpStatus)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream((string) json_encode(
                ['error' => $decision->message],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )));
    }
}
