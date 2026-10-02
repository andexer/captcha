<?php

declare(strict_types=1);

namespace Captcha\Http;

/**
 * Envía una respuesta HTTP JSON.
 *
 * Frontera de I/O fina para el endpoint del widget JavaScript. Las cabeceras
 * de caché evitan que navegadores e intermediarios sirvan retos obsoletos.
 */
final class JsonResponse
{
    /**
     * Codifica un payload como JSON (pura; send() termina el script y no se
     * puede testear).
     *
     * @param array<string, scalar> $data
     *
     * @throws \JsonException Cuando el payload no puede serializarse.
     */
    public static function encode(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Cabeceras enviadas con toda respuesta JSON (pura, testeada).
     *
     * Las cabeceras de caché evitan que navegadores e intermediarios sirvan
     * retos obsoletos; las cabeceras de política de frame y referrer impiden
     * incrustar el endpoint en páginas de terceros y que este filtre el
     * referrer.
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'Content-Type: application/json; charset=utf-8',
            'Cache-Control: no-store, no-cache, must-revalidate',
            'Pragma: no-cache',
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: no-referrer',
        ];
    }

    /**
     * Envía el payload y termina el script.
     *
     * @param array<string, scalar> $data
     */
    public static function send(array $data, int $status = 200): never
    {
        http_response_code($status);

        foreach (self::headers() as $header) {
            header($header);
        }

        echo self::encode($data);
        exit;
    }

    private function __construct() {}
}
