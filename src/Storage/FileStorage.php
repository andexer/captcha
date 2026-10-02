<?php

declare(strict_types=1);

namespace Captcha\Storage;

use Captcha\Contract\StorageInterface;
use Captcha\Exception\StorageException;

/**
 * Almacenamiento basado en ficheros: un JSON por reto dentro de un directorio.
 *
 * Los nombres de fichero son un hash SHA-256 del id, de modo que ids
 * arbitrarios jamás puedan salir del directorio de almacenamiento.
 */
final class FileStorage implements StorageInterface
{
    private const FILE_SUFFIX = '.captcha.json';

    /**
     * Número de entradas a partir del cual put() recolecta retos caducados.
     * Suficientemente alto para que un host normal (un puñado de códigos
     * vivos) jamás escanee, suficientemente bajo para que el directorio no
     * pueda crecer sin límite.
     */
    private const SWEEP_THRESHOLD = 200;

    /**
     * Segundos mínimos entre dos pasadas de recolección de basura. Una
     * petición escribe un reto, de modo que bajo PHP-FPM esto dispara más o
     * menos una vez por petición; el intervalo evita además que un worker de
     * larga vida escanee en cada escritura.
     */
    private const SWEEP_INTERVAL = 30;

    private ?int $lastSweep = null;

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
     * antes de que nadie guarde nada: fallar aquí evita descubrir el problema
     * en mitad de una verificación.
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
        throw new StorageException(sprintf('El directorio de almacenamiento "%s" %s.', $this->directory, $motivo));
    }

    public function put(string $id, string $code, int $ttl): void
    {
        if (!is_writable($this->directory)) {
            $this->falla('no es escribible');
        }

        $payload = json_encode([
            'code' => $code,
            'expires' => time() + $ttl,
        ], JSON_THROW_ON_ERROR);

        $this->escribe($this->path($id), $payload);

        $this->sweep();
    }

    private function escribe(string $path, string $payload): void
    {
        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new StorageException(sprintf(
                'No se pudo escribir la entrada del captcha en "%s".',
                $this->directory,
            ));
        }
    }

    /**
     * Recolección de basura oportunista de las entradas caducadas.
     *
     * Una entrada normalmente se elimina cuando alguien la verifica, pero un
     * cliente que pide un reto y jamás lo envía deja su fichero ahí para
     * siempre: la entrada caduca lógicamente y nada la recolectó nunca. Sin
     * barrer, eso es una fuga sin límite del directorio de almacenamiento —
     * alcanzable a través del endpoint público (por eso generate() está
     * limitado por tasa) y un problema real de memoria cuando el temp dir es
     * un tmpfs.
     *
     * Corre como mucho una vez por cada SWEEP_INTERVAL segundos y solo cuando
     * el directorio superó de verdad el umbral, de modo que el caso común de
     * una sola entrada paga un recuento barato del directorio en vez de un
     * escaneo completo. Solo se eliminan las entradas cuyo "expires" está en
     * el pasado: un fichero malformado se deja en paz (este backend jamás
     * sobrescribe ni "repara" estado ilegible, y un fichero roto vale para
     * diagnóstico). Nunca lanza: perder una pasada de recolección no debe
     * fallar una escritura legítima.
     */
    private function sweep(): void
    {
        $now = time();

        if (!$this->claimSweep($now)) {
            return;
        }

        $entries = $this->entriesToSweep();
        foreach ($entries ?? [] as $entry) {
            $this->removeIfExpired($entry);
        }
    }

    /**
     * Las entradas que el barrido debe mirar: null cuando todavía no compensa
     * (pasada ya reclamada, directorio ilegible o umbral sin superar).
     *
     * @return list<string>|null
     */
    private function entriesToSweep(): ?array
    {
        $entries = $this->readableEntries();

        if ($entries === null || self::countEntries($entries) <= self::SWEEP_THRESHOLD) {
            return null;
        }

        return $entries;
    }

    /**
     * Si toca una pasada de recolección ahora, y la anota para no repetirla
     * dentro del intervalo. Decide y recuerda en un solo paso: separar el
     * "¿toca?" del "¿cuándo fue la última?" obligaría a leer lastSweep desde
     * fuera para poder escribirlo después.
     */
    private function claimSweep(int $now): bool
    {
        if ($this->lastSweep !== null && ($now - $this->lastSweep) < self::SWEEP_INTERVAL) {
            return false;
        }

        $this->lastSweep = $now;

        return true;
    }

    /**
     * El contenido del directorio, o null si no se puede leer.
     *
     * El constructor ya garantiza que el directorio existe y es escribible,
     * así que aquí solo queda el caso exótico de un directorio escribible
     * pero no legible. Se descarta con una comprobación explícita en vez de
     * silenciar la llamada: scandir() sin suprimir el aviso no emite nada
     * en el camino normal, y si la carrera sale mal el aviso informa en
     * lugar de esconderse.
     *
     * @return list<string>|null
     */
    private function readableEntries(): ?array
    {
        if (!is_readable($this->directory)) {
            return null;
        }

        $entries = scandir($this->directory);

        return $entries === false ? null : $entries;
    }

    /**
     * Recuento de entradas reales del directorio, descontando los dos
     * directorios que scandir() siempre devuelve.
     *
     * @param list<string> $entries
     */
    private static function countEntries(array $entries): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $count++;
            }
        }

        return $count;
    }

    private function removeIfExpired(string $entry): void
    {
        if ($entry === '.' || $entry === '..' || !str_ends_with($entry, self::FILE_SUFFIX)) {
            return;
        }

        $path = $this->directory . '/' . $entry;

        if ($this->debeBorrar($path)) {
            unlink($path);
        }
    }

    private function debeBorrar(string $path): bool
    {
        return is_file($path) && $this->isExpiredFile($path);
    }

    /**
     * Si un fichero de entrada almacenado guarda una caducidad en el pasado.
     *
     * Una entrada ausente, o presente pero no legible, no cuenta como caducada:
     * el barrido jamás elimina y jamás se queja. La comprobación previa evita
     * el aviso que emitiría la lectura sobre un fichero que no está, y
     * json_decode() solo llega a ejecutarse con contenido real.
     */
    private function isExpiredFile(string $path): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            return false;
        }
        $entry = json_decode($raw, true, 8);

        return is_array($entry) && is_int($entry['expires'] ?? null) && $entry['expires'] <= time();
    }

    public function get(string $id): ?string
    {
        $path = $this->path($id);

        if (!is_file($path)) {
            return null;
        }

        return $this->readAndRemove($path);
    }

    /**
     * Lee el fichero y lo elimina manteniendo un lock exclusivo, de modo que
     * una llamada consume() concurrente para el mismo id jamás obtenga el
     * código.
     */
    public function consume(string $id): ?string
    {
        $path = $this->path($id);

        if (!is_file($path)) {
            return null;
        }

        $raw = $this->abreYConsume($path);

        return $raw === false ? null : $this->parse($raw, $path);
    }

    private function abreYConsume(string $path): string|false
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return false;
        }

        return $this->consumeUnderLock($handle, $path);
    }

    /**
    *  El lock serializa accesos concurrentes; quien lo obtiene primero lee y
    *  borra, así que ningún otro llamador puede ganar el código. El handle
    *  queda cerrado pase lo que pase, también si remove() lanza.
    *
     * @param resource $handle
    *
     * @throws StorageException Cuando el bloqueo falla o el fichero no se puede eliminar.
    *
     * @return string|false
    */
    private function consumeUnderLock($handle, string $path): string|false
    {
        if (!flock($handle, LOCK_EX)) {
            $this->fallaBloqueo($handle, $path);
        }

        try {
            $raw = stream_get_contents($handle);
            $this->remove($path);

            return $raw;
        } finally {
            $this->libera($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @throws StorageException Siempre: el cierre va con el fallo.
     */
    private function fallaBloqueo($handle, string $path): never
    {
        fclose($handle);

        throw new StorageException(sprintf('No se pudo bloquear la entrada del captcha "%s".', basename($path)));
    }

    /**
     * @param resource $handle
     */
    private function libera($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function has(string $id): bool
    {
        return is_file($this->path($id));
    }

    public function forget(string $id): void
    {
        $path = $this->path($id);

        if (is_file($path)) {
            $this->remove($path);
        }
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . hash('sha256', $id) . self::FILE_SUFFIX;
    }

    /**
     * Decodifica y valida un fichero de entrada; lo elimina cuando está
     * malformado, caducado o ya consumido.
     */
    private function parse(string $raw, string $path): ?string
    {
        $entry = self::decodeEntry($raw);

        if ($entry === null || !isset($entry['code'], $entry['expires']) || !self::esVigente($entry)) {
            $this->remove($path);

            return null;
        }

        return $entry['code'];
    }

    /**
     * @param array<mixed, mixed> $entry
     */
    private static function esVigente(array $entry): bool
    {
        return is_string($entry['code']) && is_int($entry['expires']) && $entry['expires'] > time();
    }

    /**
     * El contenido del fichero decodificado, o null si no es un JSON válido.
     *
     * @return array<mixed, mixed>|null
     */
    private static function decodeEntry(string $raw): ?array
    {
        try {
            $entry = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($entry) ? $entry : null;
    }

    /**
     * Lee y valida un fichero existente, eliminándolo de forma incondicional
     * para que una entrada vieja o caducada nunca se devuelva dos veces.
     */
    private function readAndRemove(string $path): ?string
    {
        if (!is_readable($path)) {
            throw new StorageException(sprintf('No se pudo leer la entrada del captcha "%s".', basename($path)));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new StorageException(sprintf('No se pudo leer la entrada del captcha "%s".', basename($path)));
        }

        return $this->parse($raw, $path);
    }

    /**
     * @throws StorageException Cuando el fichero no puede eliminarse.
     */
    private function remove(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        if (!is_writable(dirname($path))) {
            throw new StorageException(sprintf('No se pudo eliminar la entrada del captcha "%s".', basename($path)));
        }

        if (!unlink($path)) {
            throw new StorageException(sprintf('No se pudo eliminar la entrada del captcha "%s".', basename($path)));
        }
    }
}
