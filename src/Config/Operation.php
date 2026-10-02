<?php

declare(strict_types=1);

namespace Captcha\Config;

/**
 * Operación aritmética que emplea el generador numérico del captcha.
 *
 * Cuando Config::$operations está poblado, el código generado es la respuesta
 * numérica de una expresión tipo "12*3" y la imagen muestra la propia
 * expresión. Los símbolos permanecen ASCII a propósito: el renderizador
 * dibuja con la fuente bitmap integrada de GD, que no tiene glifos para
 * caracteres no ASCII.
 */
enum Operation: string
{
    case Add = 'add';
    case Subtract = 'subtract';
    case Multiply = 'multiply';
    case Divide = 'divide';

    public function symbol(): string
    {
        return match ($this) {
            self::Add => '+',
            self::Subtract => '-',
            self::Multiply => '*',
            self::Divide => '/',
        };
    }
}
