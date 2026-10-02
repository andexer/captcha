<?php

declare(strict_types=1);

namespace Captcha\Renderer;

use Captcha\Config\Config;
use Captcha\Contract\RendererInterface;
use Captcha\Exception\GdNotAvailableException;
use Captcha\Renderer\Support\Canvas;
use Captcha\Renderer\Support\Distortion;
use Captcha\Renderer\Support\GlyphLayout;
use Captcha\Renderer\Support\Ink;
use Captcha\Renderer\Support\Noise;
use Captcha\Renderer\Support\Palette;
use GdImage;

/**
 * Renderizador PNG basado en GD.
 *
 * Compone el lienzo —fondo, fila de glifos, ruido y distorsión— y delega el
 * detalle de cada carácter en GlyphPainter y la matemática de colocación en
 * GlyphLayout. Su trabajo acaba donde empiezan las decisiones de un glifo
 * concreto.
 */
final class GdRenderer implements RendererInterface
{
    private readonly Palette $palette;
    private readonly Noise $noise;
    private readonly Distortion $distortion;

    /**
     * @throws GdNotAvailableException Cuando ext-gd falta o está incompleta.
     */
    public function __construct()
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            throw new GdNotAvailableException(
                'Se requiere la extensión GD para renderizar imágenes de captcha. Activa ext-gd.',
            );
        }

        $this->palette = new Palette();
        $this->noise = new Noise();
        $this->distortion = new Distortion();
    }

    // ── API DEL RENDERIZADOR ─────────────────────────────────────────────────

    public function render(string $code, Config $config): string
    {
        $image = Canvas::create($config->width, $config->height);
        $background = $this->palette->background();
        Canvas::fill($image, $background);

        $this->drawCode($image, $code, $config, $background);
        $this->applyEffects($image, $config);

        return $this->encodePng($image);
    }

    public function mimeType(): string
    {
        return 'image/png';
    }

    // ── EFECTOS ──────────────────────────────────────────────────────────────

    private function applyEffects(GdImage &$image, Config $config): void
    {
        if ($config->noise) {
            $this->noise->apply($image, $config, $this->palette);
        }

        if ($config->distortion) {
            $this->adopta($image, $this->distortion->apply($image, $config));
        }
    }

    /**
     * La distorsión puede devolver el mismo lienzo (lienzo demasiado bajo), y
     * reasignarlo en ese caso perdería el efecto de las anteriores.
     */
    private function adopta(GdImage &$image, GdImage $distorted): void
    {
        if ($distorted !== $image) {
            $image = $distorted;
        }
    }

    // ── FILA DE GLIFOS ───────────────────────────────────────────────────────

    private function drawCode(GdImage $image, string $code, Config $config, int $background): void
    {
        $layout = GlyphLayout::forCode($code, $config);
        $painter = new GlyphPainter($image, $config, $this->ink($image, $background), $layout, $background);
        $x = $layout->left;

        for ($i = 0, $len = strlen($code); $i < $len; $i++) {
            $painter->paint($code[$i], $x, $layout->top);
            $x += $layout->cellWidth;
        }
    }

    /**
     * Reserva los dos colores con los que se pintarán todos los glifos.
     *
     * imagecolorallocate() sobre un lienzo truecolor devuelve siempre un entero
     * válido, pero su firma es int|false; la comprobación está aquí y no
     * repetida en el pintado, que da por hecho un color usable.
     *
     * @throws GdNotAvailableException Cuando GD no concede el color.
     */
    private function ink(GdImage $image, int $background): Ink
    {
        $foreground = $this->palette->foreground();

        return new Ink(
            text: self::allocate($image, $foreground),
            shadow: self::allocate($image, $this->palette->shadow($background, $foreground)),
        );
    }

    /** @throws GdNotAvailableException */
    private static function allocate(GdImage $image, int $rgb): int
    {
        $color = imagecolorallocate($image, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);

        if (!is_int($color)) {
            throw new GdNotAvailableException('GD no pudo reservar un color para el glifo del captcha.');
        }

        return $color;
    }

    // ── CODIFICACIÓN ─────────────────────────────────────────────────────────

    /**
     * @throws GdNotAvailableException Cuando la codificación PNG no está disponible.
     */
    private function encodePng(GdImage $image): string
    {
        ob_start();
        $ok = imagepng($image);
        $png = ob_get_clean();

        if (!$ok || $png === '') {
            throw new GdNotAvailableException('No se pudo codificar la imagen del captcha como PNG.');
        }

        return $png;
    }
}
