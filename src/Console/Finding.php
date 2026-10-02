<?php

declare(strict_types=1);

namespace Captcha\Console;

/**
 * Una línea del reporte de doctor, con su gravedad.
 *
 * La gravedad viaja como dato y no incrustada en el texto, que es el cambio
 * que permite que `doctor` sirva para algo en un script: antes el resultado
 * era una lista de cadenas con la marca ya escrita dentro, de modo que
 * ningún consumidor podía preguntar qué había fallado sin analyze el texto.
 *
 * @internal
 */
final readonly class Finding
{
    /**
     * @param Severity $severity Cuán grave es.
     * @param string $text Qué se comprobó y qué se encontró.
     * @param int $indent Nivel de anidamiento: 0 para una comprobación,
     *                    1 para un detalle que cuelga de la anterior.
     */
    public function __construct(
        public Severity $severity,
        public string $text,
        public int $indent = 0,
    ) {}
}
