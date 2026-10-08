<?php

declare(strict_types=1);

/**
 * Stub de Laravel para pruebas y para PHPStan: el paquete no depende de
 * illuminate/*, pero el proveedor de auto-discovery sí extiende su
 * ServiceProvider y llama al helper config(). Este fichero se carga desde
 * tests/bootstrap.php (en proceso) y desde phpstan.neon.dist (análisis), de
 * modo que la clase y la función existan sin instalar el framework.
 *
 * La extensión .php.tpl es a propósito —mismo motivo que las plantillas de
 * integración—: queda fuera de las puertas que filtran por extensión .php
 * (ProductionRulesTest, CommentStyleTest, SizeBudgetTest y php-cs-fixer).
 */

namespace Illuminate\Support {
    /**
     * Mínimo punto de extensión del ServiceProvider de Laravel: el proveedor
     * del paquete solo necesita que la clase exista para poder extenderla.
     * No es final a propósito: el real tampoco lo es.
     */
    class ServiceProvider
    {
    }
}

namespace {
    /**
     * Helper de configuración del anfitrión en su forma mínima, solo para la
     * clave 'captcha' que lee el proveedor. El valor sale de la variable de
     * prueba que inyecta ServiceProviderTest; cualquier otra clave devuelve el
     * default, que este stub no necesita modelar.
     *
     * @param array<string, mixed> $default
     *
     * @return array<string, mixed>|null
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key !== null && $key !== 'captcha') {
            return $default;
        }

        $valor = $GLOBALS['captcha_config_prueba'] ?? $default;

        return is_array($valor) ? $valor : null;
    }
}
