<?php

declare(strict_types=1);

namespace Captcha\Console;

/**
 * Colores y énfasis de la salida de consola.
 *
 * La decisión de colorear no se toma dentro: el constructor recibe un booleano
 * y, cuando es falso, todos los métodos devuelven el texto intacto. Eso la
 * deja sin estado de entorno y por tanto testeable de forma directa, y
 * además hace que la salida sea idéntica cuando se vacía a un fichero, que
 * es justo cuando los códigos de escape sobran.
 *
 * Decide el binario, en este orden: `--no-ansi` gana siempre, luego si la
 * salida es una terminal, y por defecto se asume que no lo es.
 *
 * @internal
 */
final readonly class Style
{
    public function __construct(private bool $color = false) {}

    /**
     * Estilo sin color, para pruebas y para salidas redirigidas.
     */
    public static function plain(): self
    {
        return new self(false);
    }

    public function success(string $text): string
    {
        return $this->wrap($text, '32');
    }

    public function warning(string $text): string
    {
        return $this->wrap($text, '33');
    }

    public function failure(string $text): string
    {
        return $this->wrap($text, '31');
    }

    public function heading(string $text): string
    {
        return $this->wrap($text, '1');
    }

    public function accent(string $text): string
    {
        return $this->wrap($text, '36');
    }

    public function dim(string $text): string
    {
        return $this->wrap($text, '2');
    }

    /**
     * Aplica un código de escape, o devuelve el texto tal cual sin color.
     */
    private function wrap(string $text, string $code): string
    {
        return $this->color ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }
}
