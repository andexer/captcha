<?php

declare(strict_types=1);

namespace Captcha\Storage;

/**
 * Contador de intentos de una ventana temporal, con su instante de arranque.
 *
 * Es la pieza que IpRateLimiter y SessionRateLimiter tenían duplicada: los dos
 * leían {start, count} de su almacenamiento —un JSON, un hueco de $_SESSION—,
 * lo reiniciaban si la ventana había vencido, lo incrementaban y comparaban
 * con el límite. Aquí vive solo la aritmética, sin saber de dónde salen los
 * datos ni adónde se guardan, así que los dos limitadores hacen lo mismo por
 * construcción y no por coincidencia.
 *
 * Es inmutable: afterAttempt() devuelve un contador nuevo. Mutar en sitio
 * obligaría a los limitadores a estrechar tipos dentro de $_SESSION, donde una
 * referencia pierde las garantías de int y la aritmética acabaría sobre
 * mixed.
 */
final readonly class WindowCounter
{
    /**
     * @param int $startedAt Instante (time()) en que abrió la ventana actual.
     * @param int $count Intentos consumidos en esa ventana.
     */
    public function __construct(
        public int $startedAt,
        public int $count,
    ) {}

    /** Contador recién abierto: la primera petición de una clave es su caso normal. */
    public static function fresh(int $now): self
    {
        return new self(startedAt: $now, count: 0);
    }

    /**
     * Lee un contador del almacenamiento sin confiar en él.
     *
     * Lo que venga dentro del array puede tener tipos equivocados —start o
     * count que no son int, un array indexado, claves que faltan—, porque en
     * un JSON de disco o en una sesión que el integrador manipula nada
     * garantiza la forma. Cualquier cosa que no cumpla el contrato se degrada
     * a un contador nuevo en vez de propagar un error: decidir sobre un
     * contador peor es preferible a no decidir.
     *
     * El null es la entrada "no había nada", que es el camino normal de la
     * primera petición. Lo que no sea un array lo resuelve quien lee, que es
     * quien sabe si lo tenía delante; aquí el tipo es concreto para que este
     * método no viva de mixed.
     *
     * @param array<array-key, mixed>|null $stored Cualquier array es un
     *                                             almacenamiento posible: la
     *                                             forma es justo lo que este
     *                                             método verifica.
     */
    public static function fromStored(?array $stored, int $now): self
    {
        if ($stored === null) {
            return self::fresh($now);
        }

        $startedAt = $stored['start'] ?? null;
        $count = $stored['count'] ?? null;

        if (!is_int($startedAt) || !is_int($count)) {
            return self::fresh($now);
        }

        return new self(startedAt: $startedAt, count: $count);
    }

    /**
     * La ventana se cierra al cumplirse su duración exacta, no un instante
     * antes: con $now - $startedAt >= $windowSeconds una ventana de 5 s
     * aguanta los cinco segundos completos.
     */
    public function isWindowOver(int $now, int $windowSeconds): bool
    {
        return $now - $this->startedAt >= $windowSeconds;
    }

    /**
     * Contador resultante de gastar un intento.
     *
     * Dentro de la ventana solo suma; al haber vencido abre una ventana nueva
     * con un único intento. Devolver un objeto nuevo y no tocar este es lo que
     * permite al llamador escribir el resultado una sola vez, sea en disco o
     * en la sesión.
     *
     * Solo recibe la duración de la ventana y no el presupuesto completo: la
     * comparación con el techo es cosa de RateLimit, y mezclarla aquí
     * ataría la aritmética de la ventana a una política de límite.
     */
    public function afterAttempt(int $now, int $windowSeconds): self
    {
        if ($this->isWindowOver($now, $windowSeconds)) {
            return new self(startedAt: $now, count: 1);
        }

        return new self(startedAt: $this->startedAt, count: $this->count + 1);
    }

    /**
     * @return array{start: int, count: int} Forma persistida; es el contrato con
     *                                       el disco y con la sesión.
     */
    public function toArray(): array
    {
        return ['start' => $this->startedAt, 'count' => $this->count];
    }
}
