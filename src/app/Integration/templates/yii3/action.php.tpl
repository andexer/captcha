<?php

declare(strict_types=1);

namespace App\Web\Captcha;

use Captcha\Captcha;
use Captcha\Http\Endpoint;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Ruta GET que alimenta la recarga AJAX del widget, como acción invocable.
 *
 * El endpoint del paquete ya devuelve 200 con el reto, 400 si la acción no
 * existe y 429 al superar el techo anti-flood, así que la acción solo vuelca
 * esa tupla en una respuesta PSR-7. Lo único que decide es qué hacer con un
 * action que no sea cadena: ?action[]=x llega como array desde Yii y pasarlo
 * tal cual a dispatch() sería un error de tipos en lugar de un 400.
 *
 * No lleva el GuardMiddleware encima: esta ruta solo genera retos y no consume
 * ninguno, el guard se aplica al POST que los recibe.
 *
 * Mapea la ruta en config/common/routes.php:
 *
 *     Route::get('/captcha/generate')
 *         ->action(App\Web\Captcha\GenerateAction::class)
 *         ->name('captcha-generate'),
 */
final class GenerateAction
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $action = $request->getQueryParams()['action'] ?? 'generate';
        $response = (new Endpoint(Captcha::instance()))
            ->dispatch(is_string($action) ? $action : '');

        return $this->responseFactory
            ->createResponse($response['status'])
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream((string) json_encode(
                $response['payload'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )));
    }
}
