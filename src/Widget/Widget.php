<?php

declare(strict_types=1);

namespace Captcha\Widget;

use Captcha\Result\CaptchaResult;

/**
 * Widget del lado servidor para el JavaScript incluido.
 *
 * Renderiza toda la UI del captcha (imagen, botón de recarga, input e id
 * oculto) como HTML estático con data attributes; sin script inline, así que
 * funciona bajo Content-Security-Policy estricta. El JavaScript solo recarga
 * la imagen.
 *
 * El widget depende solo de su view model (WidgetModel) y del resultado
 * inyectado — no de la fachada — de modo que el renderizado queda
 * desacoplado de la orquestación y es testeable con un modelo construido a
 * mano. Sin resultado (null) renderiza su estado de error: solo el envoltorio
 * y el mensaje de error, de modo que el render de una página sobrevive a una
 * generación limitada por tasa en lugar de romper a mitad de plantilla.
 */
final class Widget
{
    public function __construct(
        private readonly WidgetModel $model,
        private readonly WidgetOptions $options,
        private readonly ?CaptchaResult $result = null,
        private readonly ?string $error = null,
    ) {}

    public function render(): string
    {
        if ($this->result === null) {
            return $this->renderWrapper($this->renderError($this->error ?? ''));
        }

        return $this->renderWrapper($this->renderPiezas($this->result));
    }

    /**
     * El estado con reto: imagen, input, id oculto, honeypot y la banda de
     * error. El JavaScript tolera las piezas ausentes (toda búsqueda es
     * opcional), así que una recarga disparada por el host sigue
     * comportándose aunque el reto se haya perdido.
     */
    private function renderPiezas(CaptchaResult $result): string
    {
        return sprintf(
            '%s%s%s%s%s',
            $this->renderImage($result),
            $this->renderInput(),
            $this->renderHiddenId($result),
            $this->renderHoneypot(),
            $this->renderError(),
        );
    }

    /**
     * El envoltorio del widget con sus data attributes, envolviendo lo que
     * $inner traiga (solo la banda de error en el estado degradado).
     */
    private function renderWrapper(string $inner): string
    {
        return sprintf(
            '<div class="ct" data-captcha data-endpoint="%s" data-theme="%s" role="group" aria-label="Captcha">%s</div>',
            htmlspecialchars($this->options->endpoint, ENT_QUOTES),
            htmlspecialchars($this->theme(), ENT_QUOTES),
            $inner,
        );
    }

    /**
     * @return string Siempre uno de los valores de WidgetOptions::THEMES; una
     *                opción inválida cae al default (light).
     */
    private function theme(): string
    {
        $theme = strtolower($this->options->theme);
        if (!in_array($theme, WidgetOptions::THEMES, true)) {
            $theme = 'light';
        }

        return $theme;
    }

    private function renderImage(CaptchaResult $result): string
    {
        return sprintf(
            '<span class="ct__image-wrap">'
            . '<img class="ct__image" src="%s" alt="Captcha" width="%d" height="%d" draggable="false">'
            . '%s</span>',
            htmlspecialchars($result->getDataUri(), ENT_QUOTES),
            $this->model->width,
            $this->model->height,
            $this->renderReloadButton(),
        );
    }

    private function renderReloadButton(): string
    {
        return '<button type="button" class="ct__reload" '
            . 'aria-label="Recargar captcha" title="Recargar captcha">'
            . '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            . '<path fill="currentColor" d="M12 4V1L8 5l4 4V6a6 6 0 1 1-6 6H4a8 8 0 1 0 8-8z"/></svg>'
            . '</button>';
    }

    private function renderInput(): string
    {
        return sprintf(
            '<input type="text" name="%s" class="ct__input" '
            . 'autocomplete="off" autocapitalize="off" spellcheck="false" '
            . 'inputmode="numeric" pattern="[0-9]*" maxlength="%d" required '
            . 'placeholder="Código" aria-label="Código captcha" '
            . 'title="Escribe el código de la imagen">',
            htmlspecialchars($this->model->inputField, ENT_QUOTES),
            $this->model->length,
        );
    }

    private function renderHiddenId(CaptchaResult $result): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($this->model->idField, ENT_QUOTES),
            htmlspecialchars($result->getId(), ENT_QUOTES),
        );
    }

    /**
     * Trampa honeypot: invisible para humanos (mantenida fuera del flujo
     * visual con el CSS empaquetado), pero los bots que autocompletan todos
     * los campos la dejan rellena. verifyRequest() rechaza todo envío que
     * traiga un valor no vacío.
     */
    private function renderHoneypot(): string
    {
        if (!$this->model->honeypot) {
            return '';
        }

        return sprintf(
            '<span class="ct__honeypot" aria-hidden="true">'
            . '<input type="text" name="%s" tabindex="-1" autocomplete="off">'
            . '</span>',
            htmlspecialchars($this->model->honeypotField, ENT_QUOTES),
        );
    }

    private function renderError(string $message = ''): string
    {
        if ($message !== '') {
            $message = htmlspecialchars($message, ENT_QUOTES);
        }

        return sprintf('<div class="ct__error" role="alert" aria-live="polite">%s</div>', $message);
    }
}
