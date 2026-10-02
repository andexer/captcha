<?php

declare(strict_types=1);

namespace Captcha\Storage;

use Captcha\Contract\StorageInterface;
use Captcha\Exception\StorageException;
use Captcha\Http\SessionCookie;

/**
 * Almacenamiento respaldado por la sesión PHP.
 *
 * Esta es la única clase del paquete autorizada a tocar la superglobal
 * $_SESSION; el dominio nunca lee ni escribe estado de sesión directamente.
 * La sesión arranca de forma perezosa en el primer acceso de almacenamiento
 * en lugar de en la construcción, de modo que meramente construir este
 * objeto — o una instancia default de Captcha — jamás preempta un gestor de
 * sesión que configura ini al construirse (CodeIgniter, Laravel...). Si la
 * sesión no puede arrancar de verdad (p. ej. cabeceras ya enviadas, ruta de
 * save path inutilizable), el acceso lanza StorageException: un backend que
 * no puede persistir es inseguro, así que el fallo es ruidoso (fail-closed);
 * SessionRateLimiter replica la misma política.
 */
final class SessionStorage implements StorageInterface
{
    /**
     * @param string $namespace Clave bajo la que se guardan las entradas en $_SESSION.
     */
    public function __construct(
        private readonly string $namespace = '_captcha',
    ) {}

    public function put(string $id, string $code, int $ttl): void
    {
        $this->ensureSession();

        $this->session()[$this->key($id)] = [
            'code' => $code,
            'expires' => time() + $ttl,
        ];
    }

    public function get(string $id): ?string
    {
        $this->ensureSession();

        $session = &$this->session();
        $key = $this->key($id);
        $code = self::liveCode($session, $key);

        if ($code === null) {
            unset($session[$key]);
        }

        return $code;
    }

    public function consume(string $id): ?string
    {
        $this->ensureSession();

        $session = &$this->session();
        $key = $this->key($id);
        $code = self::liveCode($session, $key);

        unset($session[$key]);

        return $code;
    }

    /**
     * El código de una entrada viva y bien formada, o null cuando no la hay.
     *
     * @param array<string, mixed> $session
     */
    private static function liveCode(array $session, string $key): ?string
    {
        $entry = $session[$key] ?? null;

        if (!is_array($entry) || !is_string($entry['code'] ?? null) || !is_int($entry['expires'] ?? null)) {
            return null;
        }

        return $entry['expires'] > time() ? $entry['code'] : null;
    }

    public function has(string $id): bool
    {
        $this->ensureSession();

        return array_key_exists($this->key($id), $this->session());
    }

    public function forget(string $id): void
    {
        $this->ensureSession();

        unset($this->session()[$this->key($id)]);
    }

    /**
     * Arranca la sesión la primera vez que un método de almacenamiento la
     * necesita de verdad.
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $this->endurece();
            session_start();
        }
    }

    /**
     * En PHP plano se endurece la cookie que el propio paquete va a crear
     * (HttpOnly + SameSite=Lax + Secure en HTTPS); bajo un framework que
     * gestiona la sesión, SessionCookie::harden() devuelve [] y no se toca
     * nada del host.
     */
    private function endurece(): void
    {
        $params = SessionCookie::harden();

        if ($params !== []) {
            session_set_cookie_params($params);
        }
    }

    /**
     * El mapa crudo del espacio de nombres dentro de la sesión.
     *
     * El tipo declarado es a propósito laxo: las entradas las escribe esta
     * misma clase, pero una sesión puede venir manipulada o de otra versión
     * del paquete, así que quien lee valida code y expires antes de fiarse
     * (ver liveCode()).
     *
     * @throws StorageException Cuando la sesión PHP no está activa.
     *
     * @return array<string, mixed>
     */
    private function &session(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new StorageException(
                'La sesión PHP no está activa; llama a session_start() antes de usar SessionStorage.',
            );
        }

        $actual = $_SESSION[$this->namespace] ?? null;
        $vacia = [];

        $_SESSION[$this->namespace] = is_array($actual) ? $actual : $vacia;

        return $_SESSION[$this->namespace];
    }

    private function key(string $id): string
    {
        return $id;
    }
}
