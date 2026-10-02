<?php

declare(strict_types=1);

namespace Captcha\Storage;

use Captcha\Contract\RateLimiterInterface;
use Captcha\Http\SessionCookie;

/**
 * Rate limiter respaldado por la sesión: un contador por clave dentro de
 * $_SESSION.
 *
 * El compañero natural sin dependencias de SessionStorage. Como él, la
 * sesión arranca de forma perezosa en la primera llamada a allow() en lugar
 * de en la construcción, de modo que construir el limiter jamás preempta un
 * gestor de sesión que configura ini al construirse.
 *
 * Deliberadamente fail-closed: cuando allow() corre sin sesión activa, el
 * contador no puede fiarse, así que el intento se rechaza en lugar de
 * concederse. El antiguo comportamiento fail-open convertía cada host sin
 * sesiones en un endpoint sin límites; cerrar la puerta cuesta un reintento
 * a un usuario legítimo y nada a un atacante, mientras que abrírsela entrega
 * el endpoint. Los hosts que no pueden arrancar sesión deben desactivar el
 * límite por IP explícitamente (Config::$rateLimitByIp = false conserva el
 * limiter de solo sesión para hosts que la garantizan) o inyectar su propio
 * RateLimiterInterface.
 *
 * Los contadores viven bajo un namespace dedicado para que un volcado
 * completo de sesión jamás exponga códigos del captcha; las claves son la IP
 * del cliente o lo que el integrador pase.
 */
final class SessionRateLimiter implements RateLimiterInterface
{
    private const NAMESPACE = '_captcha_limits';

    /**
     * @param string $namespace Clave bajo la que se guardan los contadores en $_SESSION.
     */
    public function __construct(
        private readonly string $namespace = self::NAMESPACE,
    ) {}

    /**
     * Fail-closed deliberado: sin sesión activa no hay contador fiable, y un
     * limiter que no puede contar no debe abrir la puerta.
     */
    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        if ($limit < 0 || $windowSeconds < 1) {
            return false;
        }

        if (!$this->startSessionIfNeeded()) {
            return false;
        }

        $counter = $this->advance($key, time(), $windowSeconds);
        $budget = new RateLimit(maxAttempts: $limit, windowSeconds: $windowSeconds);

        return !$budget->denies($counter->count);
    }

    /**
     * Gasta el intento de la clave dentro del namespace y deja persistido el
     * contador resultante, para que quien llama solo tenga que decidir si el
     * presupuesto se ha agotado.
     */
    private function advance(string $key, int $now, int $windowSeconds): WindowCounter
    {
        $namespace = $_SESSION[$this->namespace] ?? [];

        if (!is_array($namespace)) {
            $namespace = [];
        }

        $counter = $this->cuenta($namespace[$key] ?? null, $now, $windowSeconds);

        $namespace[$key] = $counter->toArray();
        $_SESSION[$this->namespace] = $namespace;

        return $counter;
    }

    /**
     * Nada garantiza la forma de lo que el integrador haya dejado en la
     * sesión, así que se estrecha aquí y no dentro de WindowCounter: quien lee
     * es quien sabe si tenía delante un contador o cualquier otra cosa.
     *
     * @param mixed $guardado
     */
    private function cuenta(mixed $guardado, int $now, int $windowSeconds): WindowCounter
    {
        return WindowCounter::fromStored(is_array($guardado) ? $guardado : null, now: $now)
            ->afterAttempt(now: $now, windowSeconds: $windowSeconds);
    }

    /**
     * Arranca la sesión en el primer uso real del contador, no en el
     * constructor: construir el limiter jamás preempta la sesión del host
     * (handlers como CI4 configuran ini de sesión al construirse).
     *
     * El retorno de session_start() es además la única fuente fiable del
     * arranque: session_status() no cambia de valor para el analizador
     * estático, así que volver a compararlo después daría una comprobación que
     * el analizador cree imposible cuando no lo es.
     */
    private function startSessionIfNeeded(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if (session_status() !== PHP_SESSION_NONE) {
            return false;
        }

        return $this->arrancaConEndurecimiento();
    }

    /**
     * Misma política endurecida que SessionStorage: solo PHP plano.
     */
    private function arrancaConEndurecimiento(): bool
    {
        $params = SessionCookie::harden();

        if ($params !== []) {
            session_set_cookie_params($params);
        }

        return session_start();
    }
}
