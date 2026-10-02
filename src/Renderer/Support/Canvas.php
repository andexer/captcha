<?php

declare(strict_types=1);

namespace Captcha\Renderer\Support;

use Captcha\Exception\GdNotAvailableException;
use GdImage;

/**
 * Creación de lienzos truecolor con las dos garanterías que GD no da.
 *
 * Se extrae del renderizador porque el pintado de glifos también necesita
 * lienzos, y duplicar la conversión de un false silencioso en una excepción
 * sería justo el tipo de copia que se desincroniza en el segundo sitio.
 */
final class Canvas
{
    /**
     * Crea un lienzo de las medidas pedidas.
     *
     * GD exige enteros positivos y no avisa de otra forma cuando no lo son, así
     * que el recorte a un mínimo de 1 vive aquí y no en cada punto que dibuja.
     *
     * imagecreatetruecolor() devuelve false, en lugar de lanzar, cuando no
     * queda memoria. El contrato de toda la capa de render es
     * GdNotAvailableException, y esta es la única conversión de ese false.
     *
     * @throws GdNotAvailableException Cuando no hay memoria para el lienzo.
     */
    public static function create(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));

        if (!$image instanceof GdImage) {
            throw new GdNotAvailableException('No se pudo reservar memoria para la imagen del captcha.');
        }

        return $image;
    }

    /**
     * Rellena el lienzo entero de un color.
     *
     * imagefill() devuelve bool y escribe true en el color en la salida, que
     * aquí no interesa: la operación no puede fallar en un lienzo válido.
     */
    public static function fill(GdImage $image, int $color): void
    {
        imagefill($image, 0, 0, $color);
    }
}
