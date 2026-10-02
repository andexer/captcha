<?php

declare(strict_types=1);

namespace Captcha\Console;

/**
 * Declaración de un comando: qué se llama, qué alias tiene, qué admite y
 * qué se cuenta de él en la ayuda.
 *
 * La clase no ejecuta nada. Solo describe, y tanto el parser como el
 * generador de ayuda leen de aquí, de modo que las dos cosas que se
 * desincronizan con facilidad —lo que la CLI acepta y lo que la CLI
 * documenta— no puedan divergir: si se añade una opción al comando, aparece
 * en la ayuda porque la ayuda se deriva de la opción.
 *
 * @internal
 */
final readonly class Command
{
    /**
     * @param string $name Nombre principal, tal y como se escribe.
     * @param list<string> $aliases Sinónimos aceptados en la terminal.
     * @param string $summary Una línea, para el listado de comandos.
     * @param string $description Texto largo, para `help <comando>`.
     * @param list<Option> $options Opciones propias del comando.
     * @param list<Argument> $arguments Argumentos posicionales, en orden.
     * @param list<string> $examples Ejemplos de uso, ya formateados.
     */
    public function __construct(
        public string $name,
        public array $aliases,
        public string $summary,
        public string $description,
        public array $options = [],
        public array $arguments = [],
        public array $examples = [],
    ) {}

    /**
     * Todas las opciones del comando más las globales, en ese orden.
     *
     * @return list<Option>
     */
    public function allOptions(): array
    {
        return [...$this->options, ...Registry::globalOptions()];
    }

    /**
     * El nombre canónico si el token es este comando o uno de sus alias,
     * o null si no lo es.
     */
    public function resolve(string $token): ?string
    {
        if ($token === $this->name) {
            return $this->name;
        }

        return in_array($token, $this->aliases, true) ? $this->name : null;
    }

    /**
     * Todos los nombres por los que se puede llamar, para la ayuda.
     *
     * @return list<string>
     */
    public function spellings(): array
    {
        return [$this->name, ...$this->aliases];
    }
}
