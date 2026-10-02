<?php

declare(strict_types=1);

namespace Captcha\Renderer\Support;

use Captcha\Config\Config;
use Captcha\Config\Difficulty;

/**
 * Geometría de una fila de glifos: cuánto se escala cada dígito, cuánto mide
 * el resultado y dónde arranca el bloque centrado en el lienzo.
 *
 * Son siete números que solo existen juntos: el que coloca un glifo necesita
 * el factor de escalado, el ancho y alto de la celda y el punto de partida.
 * Calcularlos aquí en lugar de repartirlos por el renderizador convierte un
 * grupo de variables que viajan juntas (lo que la literatura llama «racimo de
 * datos») en un objeto con nombre, y deja el cálculo como una decisión
 * testeable por separado de GD.
 */
final readonly class GlyphLayout
{
    public const MAX_SCALE = 4;

    /**
     * Proporción del alto del lienzo que ocupa el glifo cuando la app no pide
     * un tamaño explícito: el resto es aire para que la distorsión y el ruido
     * respiren sin comerse los dígitos.
     */
    private const HEIGHT_TARGET = 0.78;

    /** Separación en píxeles de fuente entre glifos, además del glifo en sí. */
    private const SOURCE_GAP = 2;

    /**
     * @param int $font Fuente bitmap de GD elegida (1-5).
     * @param int $fontWidth Ancho en píxeles de esa fuente.
     * @param int $fontHeight Alto en píxeles de esa fuente.
     * @param int $scale Factor entero de escalado aplicado al glifo.
     * @param int $glyphWidth Ancho del glifo ya escalado.
     * @param int $glyphHeight Alto del glifo ya escalado.
     * @param int $cellWidth Paso horizontal entre glifos ya escalado.
     * @param int $jitter Desplazamiento vertical máximo por glifo.
     * @param int $left Coordenada X donde arranca el bloque de dígitos.
     * @param int $top Coordenada Y del glifo sin desplazamiento.
     */
    public function __construct(
        public int $font,
        public int $fontWidth,
        public int $fontHeight,
        public int $scale,
        public int $glyphWidth,
        public int $glyphHeight,
        public int $cellWidth,
        public int $jitter,
        public int $left,
        public int $top,
    ) {}

    /**
     * Calcula la geometría para un código concreto sobre un lienzo concreto.
     *
     * Las medidas de la fuente se leen en runtime de GD (imagefontwidth() /
     * imagefontheight()) porque cambian según el build de la extensión, así que
     * no pueden ser constantes. Ambas dimensiones se acotan siempre: un glifo
     * más ancho que el presupuesto horizontal saldría cortado, y uno más alto
     * que el lienzo lo recortaría GD dejando el código ilegible.
     */
    public static function forCode(string $code, Config $config): self
    {
        $len = strlen($code);
        $metrics = FontMetrics::of($config->font, self::SOURCE_GAP);
        $scale = self::scaleFor($config, $metrics, $len);

        return self::construye($config, $len, $metrics, $scale);
    }

    private static function construye(Config $config, int $len, FontMetrics $metrics, int $scale): self
    {
        $size = $metrics->scaled($scale);
        $cellWidth = $size->cellWidth;

        return new self(
            font: $config->font,
            fontWidth: $metrics->width,
            fontHeight: $metrics->height,
            scale: $scale,
            glyphWidth: $size->width,
            glyphHeight: $size->height,
            cellWidth: $cellWidth,
            jitter: self::jitterFor($config->difficulty, $config->height),
            left: self::centra($config->width - $len * $cellWidth),
            top: self::centra($config->height - $size->height),
        );
    }

    private static function centra(int $resto): int
    {
        return max(0, intdiv($resto, 2));
    }

    /**
     * Factor de escalado, acotado por el ancho disponible y por el alto.
     *
     * El ancho se reparte entre los dígitos (más un margen a cada lado) y el
     * alto admite un solo glifo, porque un segundo glifo en vertical no cabe
     * en una sola fila. intdiv() trunca hacia cero y el máximo con 1 evita
     * tanto un factor 0 —que borraría el dígito— como una división por cero
     * cuando el ancho se queda corto para el número de caracteres.
     */
    private static function scaleFor(Config $config, FontMetrics $metrics, int $length): int
    {
        $requested = self::requestedScale($config, $metrics->height);

        return max(1, min(
            $requested,
            self::widthBudget($config, $metrics->spacing, $length),
            self::heightBudget($config, $metrics->height),
        ));
    }

    private static function requestedScale(Config $config, int $fontHeight): int
    {
        if ($config->fontSize === null) {
            return min(self::MAX_SCALE, intdiv((int) ($config->height * self::HEIGHT_TARGET), $fontHeight));
        }

        return max(1, (int) round($config->fontSize / $fontHeight));
    }

    private static function widthBudget(Config $config, int $spacing, int $length): int
    {
        return max(1, intdiv($config->width, $length * $spacing + self::SOURCE_GAP));
    }

    private static function heightBudget(Config $config, int $fontHeight): int
    {
        return max(1, intdiv($config->height, $fontHeight));
    }

    /**
     * Desplazamiento vertical máximo por glifo según la dificultad.
     *
     * Sin jitter en dificultad baja: el modo barato y de máxima legibilidad.
     * Los pisos de 2 y 4 píxeles garantizan que un lienzo pequeño siga
     * separando los dígitos.
     */
    private static function jitterFor(Difficulty $difficulty, int $height): int
    {
        if ($difficulty->jitterFloor() === 0) {
            return 0;
        }

        return max($difficulty->jitterFloor(), (int) ($height * $difficulty->jitterFactor()));
    }
}
