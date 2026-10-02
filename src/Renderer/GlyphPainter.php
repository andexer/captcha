<?php

declare(strict_types=1);

namespace Captcha\Renderer;

use Captcha\Config\Config;
use Captcha\Renderer\Support\Canvas;
use Captcha\Renderer\Support\GlyphLayout;
use Captcha\Renderer\Support\Ink;
use GdImage;

/**
 * Pinta un único carácter sobre el lienzo del captcha.
 *
 * Existe para que el renderizador no tenga que saber cómo se compone un glifo:
 * aquí viven el escalado, la rotación, el desplazamiento y el pegado, que son
 * decisiones de pintado y no de composición. El contexto de un render (lienzo,
 * configuración, tinta, geometría y color de fondo) se fija una vez en el
 * constructor, de modo que paint() solo recibe lo que cambia por carácter.
 */
final class GlyphPainter
{
    public function __construct(
        private readonly GdImage $canvas,
        private readonly Config $config,
        private readonly Ink $ink,
        private readonly GlyphLayout $layout,
        private readonly int $background,
    ) {}

    /**
     * Dibuja un carácter en la columna pedida.
     *
     * @param int $x Columna donde arranca el glifo, ya escalada.
     * @param int $baseY Fila sin desplazamiento; el jitter se aplica aquí.
     */
    public function paint(string $char, int $x, int $baseY): void
    {
        $rotation = $this->rotation();
        $drawn = $this->scaled($char, $rotation);

        $this->paste($drawn, $x, $this->jitteredY($baseY, $drawn), $rotation);
    }

    /**
     * Inclinación de este glifo, en grados; cero lo deja intacto.
     */
    private function rotation(): int
    {
        $range = $this->config->difficulty->rotationRange();

        return $range > 0 ? random_int(-$range, $range) : 0;
    }

    /**
     * Glifo del tamaño final: primero se dibuja en la fuente bitmap, después
     * se escala con copia de vecino próximo y por último se rota.
     *
     * El orden importa: rotar el glifo ya escalado mantiene el pegado 1:1, y
     * los factores enteros con vecino próximo dejan los bordes de 1 bit nítidos
     * en lugar de interpolarlos a grises difusos. Ante un error de rotación el
     * glifo sale sin rotar en vez de propagar un false.
     */
    private function scaled(string $char, int $rotation): GdImage
    {
        $drawn = $this->recorta($char);

        return $rotation === 0 ? $drawn : $this->rotate($drawn, $rotation);
    }

    private function recorta(string $char): GdImage
    {
        $drawn = Canvas::create($this->layout->glyphWidth, $this->layout->glyphHeight);
        Canvas::fill($drawn, $this->background);
        $this->copiarGlifo($drawn, $char);

        return $drawn;
    }

    private function copiarGlifo(GdImage $drawn, string $char): void
    {
        imagecopyresized(
            $drawn,
            $this->glyph($char),
            0,
            0,
            0,
            0,
            $this->layout->glyphWidth,
            $this->layout->glyphHeight,
            $this->layout->fontWidth,
            $this->layout->fontHeight,
        );
    }

    /**
     * Carácter dibujado en un lienzo del tamaño de la fuente y relleno con EL
     * MISMO fondo del lienzo destino: Palette elige un color aleatorio por
     * llamada, así que hay que pasarlo, no redibujarlo. Si no, la copia
     * escalada dejaría una costura alrededor de cada glifo.
     */
    private function glyph(string $char): GdImage
    {
        $glyph = Canvas::create($this->layout->fontWidth, $this->layout->fontHeight);
        Canvas::fill($glyph, $this->background);
        imagechar($glyph, $this->layout->font, 0, 0, $char, $this->ink->text);

        return $glyph;
    }

    /**
     * imagerotate() devuelve false, en lugar de lanzar, si GD no acepta el
     * ángulo; en ese caso el glifo sale sin rotar.
     */
    private function rotate(GdImage $drawn, int $rotation): GdImage
    {
        $rotated = imagerotate($drawn, $rotation, $this->background);

        return $rotated instanceof GdImage ? $rotated : $drawn;
    }

    /**
     * Fila final del glifo, con jitter vertical recortado contra el lienzo.
     *
     * El recorte usa el alto real pegado, que en un glifo rotado es mayor que
     * el nominal, para que jamás se corte contra el borde inferior.
     */
    private function jitteredY(int $baseY, GdImage $drawn): int
    {
        $jitter = $this->layout->jitter;
        $y = $baseY + ($jitter > 0 ? random_int(-$jitter, $jitter) : 0);

        return max(0, min($this->config->height - imagesy($drawn), $y));
    }

    /**
     * Pega el glifo ya escalado, con su sombra cuando toca.
     */
    private function paste(GdImage $drawn, int $x, int $y, int $rotation): void
    {
        if ($rotation !== 0) {
            $this->pasteInk($drawn, $x, $y);

            return;
        }

        $this->pasteShadow($drawn, $x, $y);

        imagecopy($this->canvas, $drawn, $x, $y, 0, 0, imagesx($drawn), imagesy($drawn));
    }

    /**
     * Sombra sólida desplazada un píxel, solo sobre glifos planos con ruido.
     *
     * El bitmap de sombra borra el ruido bajo el glifo y deja un halo fino y
     * limpio. Un glifo rotado ya funde sus bordes y solo ganaría papilla.
     */
    private function pasteShadow(GdImage $drawn, int $x, int $y): void
    {
        if (!$this->config->noise) {
            return;
        }

        imagecopy($this->canvas, $this->shadow($drawn), $x + 1, $y + 1, 0, 0, imagesx($drawn), imagesy($drawn));
    }

    /**
     * Copia del glifo con su tinta recoloreada al tinte de sombra.
     *
     * El bitmap escalado contiene exactamente dos colores (fondo y tinta), así
     * que basta un remapeo píxel a píxel: no hay filtrado ni mezcla alguna.
     */
    private function shadow(GdImage $glyph): GdImage
    {
        $shadow = Canvas::create(imagesx($glyph), imagesy($glyph));
        Canvas::fill($shadow, $this->background);

        $this->eachPixel($glyph, function (int $x, int $y, bool $esTinta) use ($shadow): void {
            if ($esTinta) {
                imagesetpixel($shadow, $x, $y, $this->ink->shadow);
            }
        });

        return $shadow;
    }

    /**
     * Copia solo la tinta del glifo sobre el lienzo, saltándose sus píxeles de
     * fondo.
     *
     * Un glifo rotado llega con las esquinas descubiertas rellenas del color de
     * fondo, así que pegarlo entero borraría el lienzo —incluso el borde de un
     * vecino— bajo ellas. Solo debe pasar la tinta.
     */
    private function pasteInk(GdImage $glyph, int $x, int $y): void
    {
        $this->eachPixel($glyph, function (int $px, int $py, bool $esTinta, int $color) use ($x, $y): void {
            if ($esTinta) {
                imagesetpixel($this->canvas, $x + $px, $y + $py, $color);
            }
        });
    }

    /**
     * Recorre los píxeles del glifo distinguiendo tinta de fondo.
     *
     * imagecolorat() devuelve false cuando GD no puede leer el píxel, y un
     * false aquí llegaría como color a imagesetpixel(), así que se descarta.
     *
     * @param callable(int, int, bool, int): void $visitar Recibe x, y, si es
     *                                                     tinta y el color leído.
     */
    private function eachPixel(GdImage $glyph, callable $visitar): void
    {
        $width = imagesx($glyph);
        $height = imagesy($glyph);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $this->visitaPixel($glyph, $visitar, $x, $y);
            }
        }
    }

    /**
     * @param callable(int, int, bool, int): void $visitar
     */
    private function visitaPixel(GdImage $glyph, callable $visitar, int $x, int $y): void
    {
        $color = imagecolorat($glyph, $x, $y);

        if (!is_int($color)) {
            return;
        }

        $visitar($x, $y, $color !== $this->background, $color);
    }
}
