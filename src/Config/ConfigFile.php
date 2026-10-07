<?php

declare(strict_types=1);

namespace Captcha\Config;

/**
 * Contrato de marcador para los ficheros de configuración descubiertos por
 * anclaje a la raíz.
 *
 * El descubrimiento sube caminando desde la ubicación de instalación del
 * paquete y sondea {root}/app/Config/captcha.php, {root}/config/captcha.php,
 * {root}/config/packages/captcha.php (retrocompatibilidad con rc) y
 * {root}/etc/captcha.php. Esa adivinanza es la única vía hacia la capa
 * estática que NO es explícita: en checkouts anidados (un paquete dentro de
 * una aplicación anfitriona, un path repository) podría resolver al config
 * de otra app y cambiar la postura de seguridad en silencio. Los ficheros
 * anclados son por tanto opt-in: deben llevar el comentario de firma de
 * abajo para aceptarse. Los ficheros entregados explícitamente
 * (CAPTCHA_CONFIG o el directorio de trabajo) jamás necesitan el marcador.
 *
 * @internal
 */
final class ConfigFile
{
    /**
     * El token exacto que un config anclado debe contener como línea de
     * comentario. Las plantillas de install lo traen en su tercera línea.
     */
    public const SIGNATURE = 'captcha config v2';

    /**
     * Solo se escanea la cabecera del fichero: la firma es un boilerplate
     * que vive junto a <?php, nunca dentro del array de opciones.
     */
    private const HEAD_BYTES = 4096;

    /**
     * Si el fichero lleva el comentario de firma del paquete.
     *
     * El token solo cuenta cuando aparece en una línea de comentario //, de
     * modo que un literal de cadena en el array de opciones jamás pueda
     * falsificarlo.
     */
    public static function carriesSignature(string $path): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $head = (string) file_get_contents($path, false, null, 0, self::HEAD_BYTES);

        return self::buscaEnTokens($head);
    }

    private static function buscaEnTokens(string $head): bool
    {
        $tokens = token_get_all($head);

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_COMMENT && str_contains($token[1], self::SIGNATURE)) {
                return true;
            }
        }

        return false;
    }
}
