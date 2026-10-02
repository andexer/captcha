<?php

declare(strict_types=1);

namespace Captcha\Result;

/**
 * Resultado inmutable de una generación de captcha exitosa.
 *
 * Transporta el id del reto (guárdalo en el servidor), la imagen binaria y
 * su tipo MIME. Nunca expone el código del captcha.
 */
final readonly class CaptchaResult
{
    /**
     * @param string $id Identificador único del reto (hex de 128 bits).
     * @param string $image Contenido binario de la imagen.
     * @param string $mimeType Tipo MIME de la imagen.
     */
    public function __construct(
        private string $id,
        private string $image,
        private string $mimeType,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Imagen como data URI, lista para incrustar en un <img src="...">.
     */
    public function getDataUri(): string
    {
        return sprintf(
            'data:%s;base64,%s',
            $this->mimeType,
            base64_encode($this->image),
        );
    }

    /**
     * Etiqueta HTML <img> lista para imprimir, con el captcha incrustado.
     */
    public function getHtml(string $alt = 'captcha'): string
    {
        return sprintf(
            '<img src="%s" alt="%s">',
            htmlspecialchars($this->getDataUri(), ENT_QUOTES),
            htmlspecialchars($alt, ENT_QUOTES),
        );
    }

    /**
     * Escribe los bytes crudos de la imagen en un fichero.
     *
     * @return bool False si la escritura falla (p. ej. ruta inexistente o sin permisos).
     */
    public function saveTo(string $path): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        return file_put_contents($path, $this->image) !== false;
    }
}
