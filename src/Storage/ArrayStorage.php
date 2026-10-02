<?php

declare(strict_types=1);

namespace Captcha\Storage;

use Captcha\Contract\StorageInterface;

/**
 * Almacenamiento en memoria, pensado principalmente para tests y flujos de
 * proceso único.
 *
 * Las entradas caducan según un reloj inyectable, de modo que los tests
 * nunca necesitan sleep().
 */
final class ArrayStorage implements StorageInterface
{
    /** @var array<string, array{code: string, expires: int}> */
    private array $entries = [];

    /**
     * @param \Closure(): int|null $clock Devuelve el timestamp unix actual;
     *                                    por defecto time().
     */
    public function __construct(
        private readonly ?\Closure $clock = null,
    ) {}

    public function put(string $id, string $code, int $ttl): void
    {
        $this->entries[$id] = [
            'code' => $code,
            'expires' => $this->now() + $ttl,
        ];
    }

    public function get(string $id): ?string
    {
        $entry = $this->entry($id);

        if ($entry === null || $entry['expires'] <= $this->now()) {
            if ($entry !== null) {
                unset($this->entries[$id]);
            }

            return null;
        }

        return $entry['code'];
    }

    public function consume(string $id): ?string
    {
        $entry = $this->entry($id);
        unset($this->entries[$id]);

        if ($entry === null) {
            return null;
        }

        if ($entry['expires'] <= $this->now()) {
            return null;
        }

        return $entry['code'];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    public function forget(string $id): void
    {
        unset($this->entries[$id]);
    }

    /**
     * @return array{code: string, expires: int}|null
     */
    private function entry(string $id): ?array
    {
        $entry = $this->entries[$id] ?? null;

        return is_array($entry) && is_string($entry['code'] ?? null) && is_int($entry['expires'] ?? null)
            ? $entry
            : null;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
