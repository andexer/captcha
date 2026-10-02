<?php

declare(strict_types=1);

namespace Captcha\Console;

/**
 * Un fichero que `captcha install` deja escrito en la app anfitriona.
 *
 * Existe como dato y no como lógica porque el instalador tiene dos
 * responsabilidades que conviene no entremezclar: decidir qué se escribe (esto
 * vive aquí, en memoria) y hacerlo en disco (eso ocurre en bin/captcha, la
 * única frontera de E/S de la consola).
 *
 * Cada artefacto lleva además los pasos de registro que el paquete no puede
 * automatizar. El instalador nunca edita un fichero que ya pertenece a la
 * aplicación anfitriona — `bootstrap/providers.php`, `config/routes.yaml`, el
 * kernel de Symfony — porque esos cambios pueden colisionar con lo que ya
 * había y dejar el proyecto a medias. En su lugar imprime la línea exacta que
 * hay que pegar, de modo que quien integra no tiene que buscarla en el manual.
 *
 * @internal
 */
final readonly class Artifact
{
    /**
     * @param string $path Destino relativo a la raíz del proyecto anfitrión.
     * @param string $contents Código completo a escribir en ese destino.
     * @param list<string> $notes Pasos de registro a imprimir tras escribir.
     */
    public function __construct(
        public string $path,
        public string $contents,
        public array $notes = [],
    ) {}

    /**
     * Si el artefacto trae algún paso de registro pendiente para quien
     * integra, porque de ellos depende que el framework llegue a usarlo.
     */
    public function hasNotes(): bool
    {
        return $this->notes !== [];
    }
}
