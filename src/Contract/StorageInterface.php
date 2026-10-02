<?php

declare(strict_types=1);

namespace Captcha\Contract;

use Captcha\Exception\StorageException;

interface StorageInterface
{
    /**
     * Almacena un código bajo un identificador con un TTL (segundos).
     *
     * @throws StorageException Si falla la escritura.
     */
    public function put(string $id, string $code, int $ttl): void;

    /**
     * Recupera el código, o null cuando falta o está caducado.
     *
     * @throws StorageException Si falla la lectura.
     */
    public function get(string $id): ?string;

    /**
     * Lee el código y elimina la entrada en una única operación atómica.
     *
     * Esta es la primitiva anti-replay: las implementaciones DEBEN garantizar
     * que, bajo acceso concurrente, exactamente un llamador obtiene el código
     * para un id dado. Devuelve null cuando la entrada falta, está caducada o
     * ya se consumió; las caducadas se eliminan como efecto secundario.
     *
     * @throws StorageException Si falla la lectura o el borrado.
     */
    public function consume(string $id): ?string;

    /**
     * Comprueba la existencia sin consumir la entrada.
     *
     * Las entradas caducadas pueden seguir reportando true aquí hasta que se
     * recojan de forma perezosa por get() o forget().
     */
    public function has(string $id): bool;

    /**
     * Elimina una entrada (semántica de uso único).
     *
     * Eliminar una entrada ausente es una no-op.
     *
     * @throws StorageException Si falla el borrado.
     */
    public function forget(string $id): void;
}
