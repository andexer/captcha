<?php

declare(strict_types=1);

namespace Captcha\Build;

/**
 * Minificador puro de los assets empaquetados del widget — el paso de build
 * detrás de tools/minify.php (que posee la I/O de ficheros, replicando la
 * división Doctor/Installer: lógica en src/, efectos secundarios en el
 * bin).
 *
 * Elimina comentarios, saltos de línea e indentación en una única pasada
 * con estado, pero CONSERVA los espacios internos de cada línea: el espacio
 * dentro de una sentencia JS (`var x`, `typeof module`) o de un selector
 * CSS/`and (` es significativo, y pegar palabras clave produce un bundle
 * sintácticamente roto — el bug exacto que envió la variante que se cargaba
 * todo el whitespace. La salida satisface el contrato de normalización de
 * tests/Unit/Widget/AssetSyncTest.php (que compara ambos lados sin espacio
 * alguno) y se mantiene limpia para `node --check`.
 *
 * @internal
 */
final class AssetMinifier
{
    /**
     * Minifica código fuente CSS o JS.
     *
     * @param bool $stripLineComments Los comentarios de línea JS (//) se
     *                                eliminan; en CSS "//" es una URL/protocolo
     *                                válida, así que ahí solo cuentan los
     *                                comentarios de bloque.
     */
    public static function minify(string $source, bool $stripLineComments): string
    {
        $out = '';
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $i = self::copyNext($source, $i, $length, $stripLineComments, $out);
        }

        return trim($out);
    }

    /**
     * Copia (o se salta) lo que haya en la posición $i y devuelve el índice
     * del siguiente carácter con el que seguir.
     */
    private static function copyNext(string $source, int $i, int $length, bool $stripLineComments, string &$out): int
    {
        $char = $source[$i];

        /* Una cadena se copia entera, comillas incluidas. */
        if ($char === '"' || $char === "'") {
            return self::copyString($source, $i, $length, $char, $out);
        }

        return self::skipComment($source, $i, $length, $stripLineComments)
            ?? self::copySpaceOrChar($source, $i, $length, $out);
    }

    /**
     * Copia una cadena literal tal cual, escapadas incluidas.
     *
     * Empieza en la comilla de apertura y termina en la de cierre; una
     * cadena sin cerrar se copia hasta el final del fichero.
     */
    private static function copyString(string $source, int $i, int $length, string $quote, string &$out): int
    {
        for (; $i < $length; $i++) {
            $char = $source[$i];
            $out .= $char;
            if ($char === '\\') {
                return self::copyEscape($source, $i, $out);
            }
            if ($char === $quote) {
                return $i + 1;
            }
        }

        return $i;
    }

    /**
     * La secuencia escapada se copia entera: la barra y lo que protege.
     */
    private static function copyEscape(string $source, int $i, string &$out): int
    {
        $out .= $source[$i + 1] ?? '';

        return $i + 2;
    }

    /**
     * El índice del primer carácter que ya no pertenece a un comentario que
     * empieza aquí, o null si no hay ninguno.
     */
    private static function skipComment(string $source, int $i, int $length, bool $stripLineComments): ?int
    {
        if (($source[$i] ?? '') !== '/') {
            return null;
        }

        $next = $source[$i + 1] ?? '';

        if ($stripLineComments && $next === '/') {
            return self::finDeLinea($source, $i, $length);
        }

        return $next === '*' ? self::finDeBloque($source, $i, $length) : null;
    }

    /**
     * El salto se resuelve después, así que aquí solo se para en él.
     */
    private static function finDeLinea(string $source, int $i, int $length): int
    {
        $salto = strpos($source, "\n", $i);

        return $salto === false ? $length : $salto;
    }

    /**
     * El índice tras el cierre del comentario de bloque; un bloque sin cerrar
     * se consume entero, porque un CSS truncado es mejor que un CSS con ruido.
     */
    private static function finDeBloque(string $source, int $i, int $length): int
    {
        $fin = strpos($source, '*/', $i + 2);

        return $fin === false ? $length : $fin + 2;
    }

    /**
     * Copia un carácter que no era ni comilla ni comentario.
     *
     * Un espacio o tabulador interno se conserva porque en JS y CSS es
     * significativo ("var x", "typeof module", "and ("). Un salto de línea, en
     * cambio, se elimina junto con la indentación que le sigue, pero solo
     * hasta el próximo carácter no blanco: pegarle las palabras de la línea
     * siguiente produciría un bundle roto.
     */
    private static function copySpaceOrChar(string $source, int $i, int $length, string &$out): int
    {
        $char = $source[$i];

        if ($char === "\n" || $char === "\r") {
            $i = self::saltaBlancos($source, $i, $length);

            return $i + 1;
        }

        $out .= $char;

        return $i + 1;
    }

    private static function saltaBlancos(string $source, int $i, int $length): int
    {
        while ($i + 1 < $length && ctype_space($source[$i + 1])) {
            $i++;
        }

        return $i;
    }
}
