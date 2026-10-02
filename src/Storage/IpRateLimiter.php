<?php

declare(strict_types=1);

namespace Captcha\Storage;

use Captcha\Contract\RateLimiterInterface;
use Captcha\Exception\StorageException;

/**
 * Rate limiter respaldado por ficheros: un contador por clave, independiente
 * de cualquier cookie.
 *
 * Este es el compañero del lado servidor de SessionRateLimiter: los
 * contadores sobreviven a peticiones que no traen cookie de sesión (floods
 * con curl, bots), cosa que un limiter basado en sesión jamás puede hacer —
 * cada petición sin cookie arranca una sesión nueva y un contador nuevo.
 *
 * Un JSON por clave dentro de un directorio. Los nombres de fichero son un
 * hash SHA-256 de la clave, de modo que claves arbitrarias jamás puedan
 * salir del directorio de almacenamiento. Lecturas, escrituras y reinicios
 * de ventana ocurren bajo un flock exclusivo, de modo que peticiones
 * concurrentes jamás pierdan incrementos.
 *
 * Deliberadamente fail-closed: cuando los contadores no pueden leerse o
 * escribirse de forma fiable, allow() rechaza el intento en lugar de
 * concederlo. Un limiter que no puede contar no debe abrir la puerta jamás;
 * un falso negativo le cuesta a un usuario un reintento, un falso positivo
 * (fail-open) le entrega el endpoint a la inundación. Quien llama traduce el
 * rechazo en HTTP 429.
 */
final class IpRateLimiter implements RateLimiterInterface
{
    private const FILE_SUFFIX = '.limit.json';

    /**
     * @param string $directory Directorio escribible; se crea recursivamente
     *                          cuando falta.
     *
     * @throws StorageException Cuando el directorio no puede crearse o no es
     *                          escribible.
     */
    public function __construct(
        private readonly string $directory,
    ) {
        $this->aseguraDirectorio();
    }

    /**
     * El directorio tiene que existir (o poder crearse) y admitir escritura
     * antes de que nadie cuente nada: fail-closed implica que un directorio
     * inservible se nota al construir el limiter, no al primer intento.
     */
    private function aseguraDirectorio(): void
    {
        if (file_exists($this->directory) && !is_dir($this->directory)) {
            $this->falla('existe pero no es un directorio');
        }

        $this->creaSiFalta();

        if (!is_writable($this->directory)) {
            $this->falla('no es escribible');
        }
    }

    private function creaSiFalta(): void
    {
        if (is_dir($this->directory) || mkdir($this->directory, 0o775, true) || is_dir($this->directory)) {
            return;
        }

        $this->falla('no existe y no se puede crear');
    }

    private function falla(string $motivo): never
    {
        throw new StorageException(sprintf('El directorio del rate limiter "%s" %s.', $this->directory, $motivo));
    }

    /**
     * Contador por clave como un pequeño fichero JSON; la ventana se reinicia
     * cuando transcurre, y los intentos bloqueados siguen contando (un
     * atacante no puede recuperar su presupuesto fallando a propósito).
     *
     * Fail-closed por diseño: un contador ausente es un presupuesto nuevo
     * (ese es el camino normal de la primera petición), pero cualquier cosa
     * no fiable — un fichero presente que no puede decodificarse, un error
     * de lectura/escritura, algo que no es fichero ocupando la ruta del
     * contador — responde false (rechazo) SIN sobrescribir el estado. Ver el
     * docblock de la clase para la justificación.
     */
    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        if ($limit < 0 || $windowSeconds < 1) {
            return false;
        }

        $handle = $this->openLockedCounter($this->path($key));

        if ($handle === false) {
            return false;
        }

        return $this->spendAndRelease($handle, new RateLimit(maxAttempts: $limit, windowSeconds: $windowSeconds));
    }

    /**
     * Gasta el intento con el lock tomado y libera el handle pase lo que pase.
     *
     * @param resource $handle
     */
    private function spendAndRelease($handle, RateLimit $limit): bool
    {
        try {
            return $this->spendAttempt($handle, $limit);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Abre el contador en modo "c+" con el lock exclusivo tomado.
     *
     * Devolver false en vez de lanzar es la política fail-closed de la clase:
     * quien llama traduce ese false en un rechazo.
     *
     * @return resource|false
     */
    /**
     * El handle del contador ya bloqueado, o false si no se pudo abrir.
     *
     * El lock serializa los requests concurrentes sin cookie: sin él, dos
     * flooders podrían leer el mismo contador y perderse incrementos.
     *
     * @return resource|false
     */
    /**
     * El handle del contador ya bloqueado, o false si no se pudo abrir.
     *
     * @return resource|false
     */
    /**
     * El handle del contador ya bloqueado, o false si no se pudo abrir.
     *
     * @return resource|false
     */
    private function openLockedCounter(string $path)
    {
        if (!$this->isUsableCounterPath($path)) {
            return false;
        }

        $handle = fopen($path, 'c+');

        return $handle === false ? false : $this->lock($handle);
    }

    /**
     * @param resource $handle
     *
     * @return resource|false
     */
    private function lock($handle)
    {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }

    private function isUsableCounterPath(string $path): bool
    {
        if (!file_exists($path)) {
            return true;
        }

        return is_file($path) && is_readable($path) && is_writable($path);
    }

    /**
     * Lee el contador, gasta un intento y lo persiste. Asume el lock tomado.
     *
     * @param resource $handle
     */
    private function spendAttempt($handle, RateLimit $budget): bool
    {
        $counter = $this->advanceCounter($handle, time(), $budget->windowSeconds);

        if ($counter === null) {
            return false;
        }

        $this->persist($handle, $counter);

        return !$budget->denies($counter->count);
    }

    /**
     * El contador con el intento ya gastado, o null cuando el estado
     * almacenado no es fiable y la pasada debe rechazarse sin escribir nada.
     *
     * Fichero presente pero corrupto: se rechaza SIN sobrescribirlo (autocurar
     * el contador sería un fail-open con pasos extra).
     *
     * @param resource $handle
     */
    private function advanceCounter($handle, int $now, int $windowSeconds): ?WindowCounter
    {
        $raw = stream_get_contents($handle);

        if ($raw === false) {
            return null;
        }

        $entry = $raw === '' ? null : $this->decode($raw);
        if ($raw !== '' && $entry === null) {
            return null;
        }

        return WindowCounter::fromStored($entry, now: $now)->afterAttempt(now: $now, windowSeconds: $windowSeconds);
    }

    /**
     * @param resource $handle
     */
    private function persist($handle, WindowCounter $counter): void
    {
        /*
        *  Rewind + truncate: el modo "c+" conserva bytes viejos si el
        *  nuevo payload es más corto.
        */
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($counter->toArray(), JSON_THROW_ON_ERROR));
        fflush($handle);
    }

    /**
     * @return array{start: int, count: int}|null Null cuando no se puede decodificar.
     */
    private function decode(string $raw): ?array
    {
        try {
            $entry = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($entry) || !is_int($entry['start'] ?? null) || !is_int($entry['count'] ?? null)) {
            return null;
        }

        return ['start' => $entry['start'], 'count' => $entry['count']];
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . self::FILE_SUFFIX;
    }
}
