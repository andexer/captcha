<?php

declare(strict_types=1);

namespace Captcha\Storage;

use InvalidArgumentException;

/**
 * El presupuesto de intentos de una clave dentro de una ventana temporal.
 *
 * El par (intentos, segundos) viaja siempre junto por allow(), así que se
 * encapsula aquí con la regla que lo gobierna y con la comparación que decide
 * si el intento se concede. El límite es inclusivo: los primeros
 * $maxAttempts intentos pasan y el siguiente se rechaza.
 *
 * Existe por dos motivos. Uno es la legibilidad: dos números sueltos en un
 * `return $count <= $limit` no dicen nada sobre cuál es el techo y cuál la
 * ventana, y este VO les pone nombre. El otro es la validación, que antes solo
 * vivía a medias —IpRateLimiter rechazaba un $limit negativo,
 * SessionRateLimiter no miraba ni el $limit ni la ventana, y ninguno
 * comprobaba que la ventana fuese positiva. Una ventana cero o negativa hacía
 * que la ventana se cumpliese siempre y el contador se reiniciara en cada
 * intento, es decir, un límite inexistente.
 *
 * @see RateLimiterInterface La interfaz pública conserva (int, int): este VO
 *                            se construye dentro de los limitadores, sin
 *                            romper a quien inyecta el suyo.
 */
final readonly class RateLimit
{
    /**
     * @param int $maxAttempts Intentos concedidos por ventana; 0 lo bloquea todo.
     * @param int $windowSeconds Duración de la ventana, en segundos; > 0.
     *
     * @throws InvalidArgumentException Con un límite negativo o una ventana no positiva.
     */
    public function __construct(
        public int $maxAttempts,
        public int $windowSeconds,
    ) {
        if ($maxAttempts < 0) {
            throw new InvalidArgumentException('El límite de intentos no puede ser negativo.');
        }

        if ($windowSeconds < 1) {
            throw new InvalidArgumentException('La ventana del rate limit debe durar al menos un segundo.');
        }
    }

    /**
     * ¿Este intento agota el presupuesto?
     *
     * Los intentos ya bloqueados siguen gastando: quien falla a propósito no
     * recupera presupuesto, porque solo el paso del tiempo reinicia la
     * ventana (de eso se encarga WindowCounter).
     */
    public function denies(int $attempts): bool
    {
        return $attempts > $this->maxAttempts;
    }
}
