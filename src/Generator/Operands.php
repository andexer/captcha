<?php

declare(strict_types=1);

namespace Captcha\Generator;

use Captcha\Config\Operation;

/**
 * Una expresión aritmética ya sorteada: sus dos operandos y su respuesta.
 *
 * Antes de existir esto el generador devolvía la tupla [a, b, respuesta] y
 * usaba un null en la primera posición como centinela de «reintenta el
 * sorteo». Eso obligaba a que cada sitio leyera la tupla por posición —cuatro
 * comparaciones para distinguir un Operands de un rejections— y a que un
 * Operand null se combinara con ints reales, que es exactamente el tipo de
 * error que el compilador deja pasar. Ahora el rechazo se expresa con null y
 * un Operands vivo siempre lleva las tres partes.
 */
final readonly class Operands
{
    public function __construct(
        public int $left,
        public int $right,
        public int $answer,
    ) {}

    /**
     * Expresión tal y como se pinta en la imagen.
     *
     * El símbolo lo decide la operación y es siempre ASCII: la fuente bitmap
     * de GD no tiene glifos para caracteres no ASCII.
     */
    public function expressionFor(Operation $operation): string
    {
        return $this->left . $operation->symbol() . $this->right;
    }
}
