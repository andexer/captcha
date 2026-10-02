<?php

declare(strict_types=1);

namespace Captcha\Console;

/**
 * Declaración de un argumento posicional de un comando.
 *
 * Se modela aparte de Option porque no lleva guiones y porque su obligatoriedad
 * se comprueba de otra forma: una opción desconocida siempre es un error,
 * mientras que un argumento de más solo lo es si el comando no lo admite.
 * Esa diferencia es la que permite que `install` acepte el framework como
 * posicional y que `doctor` rechace cualquier sobra.
 *
 * @internal
 */
final readonly class Argument
{
    public function __construct(
        public string $name,
        public bool $required = false,
        public string $description = '',
    ) {}

    /**
     * Sintaxis del argumento para la ayuda: `<nombre>` si es obligatorio,
     * `[nombre]` si es opcional.
     */
    public function synopsis(): string
    {
        return $this->required ? '<' . $this->name . '>' : '[' . $this->name . ']';
    }
}
