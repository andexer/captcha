<?php

declare(strict_types=1);

namespace Captcha\Console;

/**
 * Declaración de una opción de línea de comandos.
 *
 * Existe como dato, no como lógica, por dos razones concretas. La primera es
 * que el texto de ayuda se genera de estas declaraciones, de modo que
 * añadir una opción no obliga a recordar reescribir el manual en otro sitio.
 * La segunda es que el parser recibe la lista de opciones que admite y puede
 * rechazar lo desconocido; sin ella, `--framwork=laravel` sería indistinguible
 * de una orden válida y el error solo aparecería más tarde y en otro sitio.
 *
 * @internal
 */
final readonly class Option
{
    /**
     * @param string $name Nombre largo, sin guiones: "framework".
     * @param string|null $short Nombre corto sin guion, o null si no tiene.
     * @param bool $takesValue true si la opción consume un valor.
     * @param string $description Texto de una línea para la ayuda.
     * @param string $valueLabel Cómo se llama al valor en la ayuda.
     * @param string|bool|null $default Valor por defecto, o null si no tiene.
     * @param string|null $example Un valor de muestra, para sugerir cuando falte.
     */
    public function __construct(
        public string $name,
        public ?string $short = null,
        public bool $takesValue = false,
        public string $description = '',
        public string $valueLabel = 'valor',
        public string|bool|null $default = null,
        public ?string $example = null,
    ) {}

    /**
     * Opción que no consume valor: `--strict`, no `--strict=algo`.
     */
    public static function flag(string $name, string $description, ?string $short = null): self
    {
        return new self($name, $short, false, $description);
    }

    /**
     * Opción que consume valor: `--framework=laravel` o `--framework laravel`.
     *
     * @param string $valueLabel Cómo se describe el valor en la ayuda, que
     *                           puede ser una lista de admitidos.
     * @param string|null $example Un valor concreto, para los mensajes de
     *                             error, donde una lista de admitidos no
     *                             ajuda a quien no sabe escribirla.
     */
    public static function value(
        string $name,
        string $description,
        ?string $short = null,
        string $valueLabel = 'valor',
        ?string $example = null,
    ): self {
        return new self($name, $short, true, $description, $valueLabel, null, $example);
    }

    /**
     * Cómo se escribe la opción en la ayuda, con sus dos formas.
     *
     * La forma corta solo aparece cuando existe, y entonces se le anteceden
     * los huecos que le faltan para que la columna izquierda de la ayuda
     * quede recta aunque no toda opción tenga forma corta.
     *
     * La larga lleva siempre el sufijo `=<valor>` cuando consume valor: es la
     * que no puede confundirse con un argumento posicional al leer la ayuda
     * de un vistazo.
     */
    public function synopsis(): string
    {
        $larga = '--' . $this->name . ($this->takesValue ? '=<' . $this->valueLabel . '>' : '');
        $corta = $this->short === null ? '    ' : '-' . $this->short . ',';

        return $corta . ' ' . $larga;
    }

    /**
     * La opción en forma compacta, para la línea de uso de un comando.
     *
     * @internal
     */
    public function compact(): string
    {
        $larga = '--' . $this->name . ($this->takesValue ? '=<' . $this->valueLabel . '>' : '');

        return $this->short === null ? $larga : '-' . $this->short . '|' . $larga;
    }

    /**
     * La opción tal y como puede escribirse en la terminal.
     */
    public function matches(string $token): bool
    {
        if ($token === '--' . $this->name) {
            return true;
        }

        return $this->short !== null && $token === '-' . $this->short;
    }
}
