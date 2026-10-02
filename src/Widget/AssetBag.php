<?php

declare(strict_types=1);

namespace Captcha\Widget;

/**
 * Emite el CSS y el JavaScript empaquetados inline, deduplicados por
 * instancia.
 *
 * Mantiene al widget autosuficiente: sin rutas públicas de assets, sin
 * vendor/ en el docroot. La primera llamada a widget() en una página inyecta
 * <style>+<script>; las siguientes lo reutilizan. La secuencia "</script" se
 * escapa para que el código empaquetado jamás pueda cerrar antes de tiempo
 * la etiqueta inline.
 */
final class AssetBag
{
    private bool $emitted = false;

    /**
     * @param string $cssPath Ruta absoluta a captcha.min.css.
     * @param string $jsPath Ruta absoluta a captcha.min.js.
     */
    public function __construct(
        private readonly string $cssPath,
        private readonly string $jsPath,
    ) {}

    /**
     * Assets inline, pero solo la primera vez por instancia.
     *
     * @throws \RuntimeException Cuando un fichero de asset no puede leerse.
     */
    public function once(): string
    {
        if ($this->emitted) {
            return '';
        }

        $this->emitted = true;

        return $this->inline();
    }

    /**
     * Assets inline, siempre (lo usa Captcha::assets()).
     *
     * @throws \RuntimeException Cuando un fichero de asset no puede leerse.
     */
    public function inline(): string
    {
        return sprintf(
            '<style>%s</style>' . "\n" . '<script>%s</script>',
            $this->read($this->cssPath),
            $this->escapeScript($this->read($this->jsPath)),
        );
    }

    /**
     * @throws \RuntimeException Cuando el fichero no puede leerse.
     */
    private function read(string $path): string
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException(sprintf('No se puede leer el asset del widget "%s".', $path));
        }

        return $content;
    }

    private function escapeScript(string $js): string
    {
        return str_replace('</', '<\\/', $js);
    }
}
