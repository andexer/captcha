<?php

declare(strict_types=1);

namespace Captcha\Http;

/**
 * @internal
 * Único punto de acceso a las superglobales web de PHP, para que el resto
 * del paquete quede libre de lecturas de $_SERVER/$_POST.
 *
 * Ayuda a la fachada a ofrecer una API coherente (submitted()/valid()/message())
 * sin filtrar a quien integra la fontanería de la petición. La única otra
 * superglobal tocada por el paquete es $_SESSION (SessionStorage) y los
 * scripts de arranque en public/, que leen $_GET/$_SERVER fuera de src/.
 */
final class Globals
{
    private function __construct() {}

    /**
     * Si la petición actual se envió con método POST.
     */
    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * Si el cliente conectó por HTTPS, para que el paquete pida una cookie
     * de sesión Secure solo cuando de verdad ayuda (jamás en HTTP plano,
     * donde una cookie Secure rompería la sesión en silencio).
     */
    public static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $secure = is_string($https) ? strtolower($https) : '';
        $httpsSet = $secure !== '' && $secure !== 'off';

        return $httpsSet || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    /**
     * Los campos POST no pueden inspeccionarse sin la dirección del cliente:
     * las claves del rate limit suelen ser la IP remota. Misma política que
     * isPost(): el contexto web entra solo por esta clase.
     */
    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }

    /**
     * IP del cliente para las claves del rate limit, honrando los proxies de
     * confianza solo cuando están declarados explícitamente.
     *
     * X-Forwarded-For JAMÁS se cree por defecto: un $trustedProxies vacío
     * (el default del paquete) devuelve solo REMOTE_ADDR, de modo que un
     * cliente no pueda falsear la cabecera para rotar su cubo de rate limit.
     * Solo cuando el propio REMOTE_ADDR casa con un proxy de confianza se
     * recorre la cabecera, de derecha a izquierda, devolviendo el primer salto
     * que no sea a su vez un proxy de confianza; cualquier cosa sospechosa
     * cae de vuelta a REMOTE_ADDR.
     *
     * Normalizar ANTES de comparar: una IP puede escribirse igual como
     * "10.0.0.1" o como "::ffff:10.0.0.1" (IPv4-mapped IPv6) y ambas grafías
     * designan el mismo host — sin normalización, un proxy declarado con una
     * grafía no emparejaría nunca con REMOTE_ADDR en la otra y su
     * X-Forwarded-For quedaría ignorado en silencio.
     *
     * Una cadena ausente, vacía o compuesta solo por proxies de confianza
     * deja la IP utilizable en REMOTE_ADDR: es el valor de vuelta de null.
     *
     * @param list<string> $trustedProxies
     */
    public static function clientIp(array $trustedProxies = []): string
    {
        $trusted = array_values(array_map([self::class, 'normalizeIp'], $trustedProxies));
        $remote = self::normalizeIp(self::ip());

        if ($remote === '' || !in_array($remote, $trusted, true)) {
            return $remote;
        }

        return self::firstUntrustedHop($trusted) ?? $remote;
    }

    /**
     * Primer salto de X-Forwarded-For, de derecha a izquierda, que sea una IP
     * válida y no figure como proxy de confianza.
     *
     * Los saltos vacíos y los no-IP se saltan en silencio: una cabecera
     * manipulada no puede inventarse una IP de cliente que no estaba.
     *
     * @param list<string> $trusted
     */
    private static function firstUntrustedHop(array $trusted): ?string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwarded) || trim($forwarded) === '') {
            return null;
        }

        return self::primerCliente(array_reverse(array_map('trim', explode(',', $forwarded))), $trusted);
    }

    /**
     * @param list<string> $hops
     * @param list<string> $trusted
     */
    private static function primerCliente(array $hops, array $trusted): ?string
    {
        foreach ($hops as $hop) {
            $normalized = self::normalizeIp($hop);

            if (self::esCliente($normalized, $trusted)) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Un salto cuenta como cliente si es una IP válida y no es a su vez un
     * proxy de confianza; un salto vacío o manipulado cae por su propio peso.
     *
     * @param list<string> $trusted
     */
    private static function esCliente(string $hop, array $trusted): bool
    {
        return filter_var($hop, FILTER_VALIDATE_IP) !== false && !in_array($hop, $trusted, true);
    }

    /**
     * Desenvuelve IPv4 mapeado en IPv6 (::ffff:a.b.c.d) para que ambas
     * grafías del mismo cliente compartan un único cubo de rate limit.
     */
    private static function normalizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }

        return self::desenvuelveMapeada($ip) ?? $ip;
    }

    /**
     * El IPv4 escondido tras el prefijo, o null si la cadena no lo traía o lo
     * que seguía no era una IPv4 válida.
     *
     * strripos() ya ignora mayúsculas y minúsculas, así que no hace falta bajar
     * la cadena antes: la posición se busca una sola vez y se comprueba, porque
     * sumarle el largo del prefijo a un false (que PHP convierte a 0)
     * devolvería un trozo de la IP en vez de fallar.
     */
    private static function desenvuelveMapeada(string $ip): ?string
    {
        $prefijo = strripos($ip, '::ffff:');

        if ($prefijo === false) {
            return null;
        }

        $mapped = substr($ip, $prefijo + strlen('::ffff:'));

        return filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $mapped;
    }

    /**
     * Los campos POST recibidos, exactamente como PHP los reportó.
     *
     * Las claves de $_POST pueden ser enteras (un índice numérico en el
     * formulario las convierte), así que el tipo honesto es array-key.
     *
     * @return array<array-key, mixed>
     */
    public static function post(): array
    {
        /*
        *  $_POST es una superglobal y normalmente un array; una asignación
        *  violada en otro lugar no debe tumbar a quienes llaman.
        */
        return is_array($_POST) ? $_POST : [];
    }
}
