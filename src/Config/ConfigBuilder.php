<?php

declare(strict_types=1);

namespace Captcha\Config;

use Captcha\Exception\InvalidConfigException;

/**
 * Builder fluido y tipado para el value object Config.
 *
 * El builder es el camino legible hacia una configuración limpia en el
 * cableado de servicios: cada opción tiene su setter tipado (un tipo
 * erróneo falla al instante con un TypeError), la validación completa de
 * rangos se difiere a build() y nada se muta en el sitio — build() entrega
 * una Config inmutable. El array interno de opciones puede sembrarse desde
 * una Config existente, un array plano de opciones u otro builder con from().
 */
final class ConfigBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    // ── SEMILLA ──────────────────────────────────────────────────────────────

    /**
     * Siembra el builder desde una Config existente, un array plano de
     * opciones (la misma forma de Config::fromArray()) u otro builder.
     *
     * @param Config|array<string, mixed>|self|null $base
     */
    /**
     * Cada rama devuelve el array de opciones de partida y la última es la del
     * null; sin else, porque son tipos disjuntos y no hay nada que hacer
     * cuando no llega base ninguna.
     *
     * @param Config|array<string, mixed>|self|null $base
     */
    public static function from(Config|array|self|null $base = null): self
    {
        $builder = new self();
        $builder->options = match (true) {
            $base instanceof self => $base->options,
            $base instanceof Config => $base->toArray(),
            is_array($base) => $base,
            default => [],
        };

        return $builder;
    }

    // ── SETTERS DE OPCIONES ──────────────────────────────────────────────────

    /**
     * Referencia el atajo PRESETS ('default' | 'login' | 'strict'); las
     * opciones explícitas registradas hasta ahora conservan la precedencia
     * sobre las del preset.
     */
    public function preset(string $preset): self
    {
        $this->options['preset'] = $preset;

        return $this;
    }

    public function length(int $length): self
    {
        $this->options['length'] = $length;

        return $this;
    }

    public function width(int $width): self
    {
        $this->options['width'] = $width;

        return $this;
    }

    public function height(int $height): self
    {
        $this->options['height'] = $height;

        return $this;
    }

    public function ttl(int $ttl): self
    {
        $this->options['ttl'] = $ttl;

        return $this;
    }

    public function difficulty(Difficulty|string $difficulty): self
    {
        $this->options['difficulty'] = $difficulty;

        return $this;
    }

    public function font(int $font): self
    {
        $this->options['font'] = $font;

        return $this;
    }

    public function fontSize(?int $fontSize): self
    {
        $this->options['fontSize'] = $fontSize;

        return $this;
    }

    public function noise(bool $noise): self
    {
        $this->options['noise'] = $noise;

        return $this;
    }

    public function distortion(bool $distortion): self
    {
        $this->options['distortion'] = $distortion;

        return $this;
    }

    public function output(string $output): self
    {
        $this->options['output'] = $output;

        return $this;
    }

    public function idField(string $idField): self
    {
        $this->options['idField'] = $idField;

        return $this;
    }

    public function inputField(string $inputField): self
    {
        $this->options['inputField'] = $inputField;

        return $this;
    }

    public function injectAssets(bool $injectAssets): self
    {
        $this->options['injectAssets'] = $injectAssets;

        return $this;
    }

    /**
     * @param list<Operation>|list<string>|array<string, bool> $operations
     */
    public function operations(array $operations): self
    {
        $this->options['operations'] = $operations;

        return $this;
    }

    /**
     * @param array{int, int}|null $between
     */
    public function between(?array $between): self
    {
        $this->options['between'] = $between;

        return $this;
    }

    public function verifyAttempts(int $verifyAttempts): self
    {
        $this->options['verifyAttempts'] = $verifyAttempts;

        return $this;
    }

    public function generateAttempts(int $generateAttempts): self
    {
        $this->options['generateAttempts'] = $generateAttempts;

        return $this;
    }

    public function rateLimitWindow(int $rateLimitWindow): self
    {
        $this->options['rateLimitWindow'] = $rateLimitWindow;

        return $this;
    }

    public function honeypot(bool $honeypot): self
    {
        $this->options['honeypot'] = $honeypot;

        return $this;
    }

    public function honeypotField(string $honeypotField): self
    {
        $this->options['honeypotField'] = $honeypotField;

        return $this;
    }

    public function rateLimitByIp(bool $rateLimitByIp): self
    {
        $this->options['rateLimitByIp'] = $rateLimitByIp;

        return $this;
    }

    public function storage(string $storage): self
    {
        $this->options['storage'] = $storage;

        return $this;
    }

    /**
     * @param list<string> $trustedProxies
     */
    public function trustedProxies(array $trustedProxies): self
    {
        $this->options['trustedProxies'] = $trustedProxies;

        return $this;
    }

    // ── SALIDA ───────────────────────────────────────────────────────────────

    /**
     * Las opciones acumuladas hasta ahora, como array plano (la forma de
     * Config::fromArray(), atajo "preset" incluido si está presente).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->options;
    }

    /**
     * Construye y valida la Config inmutable.
     *
     * Las comprobaciones de tipo ya corrieron en cada setter; la validación
     * completa de rangos y el pase estricto de claves desconocidas corren
     * aquí vía Config::fromArray().
     *
     * @throws InvalidConfigException Cuando una opción está fuera de rango o
     *                                el array semilla traía claves desconocidas.
     */
    public function build(): Config
    {
        return Config::fromArray($this->options);
    }
}
