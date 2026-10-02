<?php

declare(strict_types=1);

namespace Captcha\Contract;

interface GeneratorInterface
{
    /**
     * Genera un código numérico.
     *
     * @param int $length Número de dígitos (>= 3).
     *
     * @throws \Captcha\Exception\InvalidConfigException Cuando la longitud
     *                                                   está fuera de rango.
     *
     * @return string Solo dígitos, p. ej. "47391".
     */
    public function generate(int $length): string;
}
