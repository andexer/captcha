<?php

declare(strict_types=1);

namespace Captcha\Widget;

use InvalidArgumentException;

/**
 * Opciones inmutables por widget.
 *
 * Los nombres de los campos del formulario viven en Config (fuente única de
 * verdad tanto para el markup del widget como para verifyRequest()).
 */
final readonly class WidgetOptions
{
    public const THEMES = ['auto', 'light', 'dark'];

    public const DEFAULT_ENDPOINT = '/captcha/endpoint';

    /*
    *  El widget nace en modo claro: un captcha debe leerse igual en cualquier
    *  página, sin depender de la preferencia del sistema del visitante.
    *  'auto' (prefers-color-scheme) y 'dark' siguen disponibles pasando el
    *  parámetro theme explícitamente.
    */
    public const DEFAULT_THEME = 'light';

    public function __construct(
        public string $endpoint = self::DEFAULT_ENDPOINT,
        public string $theme = self::DEFAULT_THEME,
    ) {}

    /**
     * Construye desde el array que el visitante pasó a Captcha::widget().
     *
     * La validación es explícita y no una expansión de argumentos con nombre:
     * una errata en la clave solo reventaría al final, y un valor de otro
     * tipo se convertiría en un TypeError que no nombra la clave culpable.
     * Aquí ambas cosas fallan juntas y con el nombre en el mensaje.
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidArgumentException Ante una clave desconocida o un valor que no sea texto.
     */
    public static function fromArray(array $options = []): self
    {
        $valores = [];

        foreach ($options as $clave => $valor) {
            $valores[$clave] = self::textoDe($clave, $valor);
        }

        return new self(
            endpoint: $valores['endpoint'] ?? self::DEFAULT_ENDPOINT,
            theme: $valores['theme'] ?? self::DEFAULT_THEME,
        );
    }

    /**
     * Valida nombre y valor de una opción en el mismo paso, para que el
     * mensaje que sale lleve siempre la clave culpable.
     *
     * @throws InvalidArgumentException Ante una clave desconocida o un valor que no sea texto.
     */
    private static function textoDe(string|int $clave, mixed $valor): string
    {
        self::assertKnownKey($clave);

        if (is_string($valor)) {
            return $valor;
        }

        throw new InvalidArgumentException(sprintf(
            "La opción de widget '%s' debe ser texto.",
            (string) $clave,
        ));
    }

    /**
     * El recorrido sigue siendo uno solo y en el orden dado por quien llama a
     * propósito: la primera clave culpable es la que se reporta. Separar el
     * bucle en "validar nombres" y luego "validar valores" cambiaría cuál de
     * los dos mensajes sale cuando hay un nombre inválido y un valor mal
     * tipado a la vez.
     *
     * @throws InvalidArgumentException Ante una clave que no sea endpoint ni theme.
     */
    private static function assertKnownKey(string|int $clave): void
    {
        if ($clave === 'endpoint' || $clave === 'theme') {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            "Opción de widget desconocida: '%s'. Las válidas son: endpoint, theme.",
            (string) $clave,
        ));
    }

}
