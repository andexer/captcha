<?php

declare(strict_types=1);

namespace Captcha\Http;

use Captcha\Captcha;
use Captcha\Exception\RateLimitException;

/**
 * Endpoint HTTP para el widget JavaScript incluido.
 *
 * El modo servidor solo expone la acción "generate": el widget recarga un
 * reto mediante GET y el formulario del host verifica el código en un POST
 * clásico. Parámetro de URL: ?action=generate.
 */
final class Endpoint
{
    public function __construct(
        private readonly Captcha $captcha,
    ) {}

    /**
     * Resuelve una acción en un payload de respuesta (pura, testeable por
     * unidades).
     *
     * @return array{status: int, payload: array<string, scalar>}
     */
    public function dispatch(string $action): array
    {
        try {
            return match ($action) {
                'generate' => ['status' => 200, 'payload' => $this->generate()],
                default => ['status' => 400, 'payload' => ['ok' => false, 'error' => 'Acción desconocida.']],
            };
        } catch (RateLimitException $exception) {
            return ['status' => 429, 'payload' => ['ok' => false, 'error' => $exception->getMessage()]];
        }
    }

    /**
     * Gestiona una petición y termina. Frontera de I/O; ver JsonResponse::send().
     */
    public function handle(string $action): never
    {
        $response = $this->dispatch($action);
        JsonResponse::send($response['payload'], $response['status']);
    }

    /**
     * @return array<string, scalar>
     */
    private function generate(): array
    {
        $result = $this->captcha->generate();

        return [
            'ok' => true,
            'id' => $result->getId(),
            'image' => $result->getDataUri(),
            'mime' => $result->getMimeType(),
            'width' => $this->captcha->config()->width,
            'height' => $this->captcha->config()->height,
        ];
    }
}
