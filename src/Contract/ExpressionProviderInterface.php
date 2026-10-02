<?php

declare(strict_types=1);

namespace Captcha\Contract;

/**
 * Capacidad opcional de un generador: exponer una expresión legible.
 *
 * Un generador puede desempeñar ambos papeles: generate() devuelve el
 * código máquina que hay que verificar (p. ej. el resultado aritmético)
 * mientras que expression() devuelve el texto ASCII realmente dibujado en la
 * imagen (p. ej. "12*3").
 */
interface ExpressionProviderInterface
{
    /**
     * Devuelve la expresión dibujada en la imagen, si el código actual tiene.
     *
     * @return string Cadena vacía cuando el generador no tiene expresión.
     */
    public function expression(): string;
}
