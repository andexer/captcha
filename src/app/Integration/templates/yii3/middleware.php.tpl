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
 * Solo actúa sobre el POST: en GET el reto todavía no existe y verificar ahí
 * dejaría al usuario con un formulario vacío y sin reto que resolver.
 *
 * requireSubmission: true es lo que hace seguro el middleware: con false, un
 * POST que omite el campo oculto captcha_id se considera válido.
 *
 * La denegación es una respuesta propia con el estado y el mensaje del guard;
 * si tu aplicación prefiere reponer el formulario, sustitúyela por un
 * redirect 303 al referrer con un flash de sesión.
 *
 * Regístralo en config/web/di/application.php, dentro de withMiddlewares(),
 * justo antes de Router::class.
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
