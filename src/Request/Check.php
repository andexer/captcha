<?php

declare(strict_types=1);

namespace Captcha\Request;

/**
 * Resultado inmutable, con ámbito de petición, del flujo completo del
 * captcha.
 *
 * Empaqueta las tres respuestas que necesita una plantilla de formulario
 * (¿se envió el captcha?, ¿pasó?, ¿cuál es el error?) tras una única llamada
 * a Captcha::check(), reemplazando el trío submitted()/valid()/message() en
 * el caso común. Value object readonly plano: la fachada y su estado de
 * flujo lo construyen inline.
 *
 * El mensaje de error se devuelve literal: proviene de las cadenas fijas en
 * español del propio paquete, y escapar es un asunto de la vista, así que
 * las plantillas siguen aplicando htmlspecialchars() como ya hacían (evita
 * la doble codificación cuando el mismo valor va además a JSON o a logs).
 */
final readonly class Check
{
    /**
     * Si la petición actual envió de verdad el captcha (POST).
     */
    public bool $submitted;

    /**
     * Si el captcha enviado fue correcto; siempre false mientras idle.
     */
    public bool $passed;

    /**
     * Retroalimentación en español para una verificación fallida, literal
     * (escápala en la vista); null mientras idle o tras un pase.
     */
    public ?string $error;

    public function __construct(
        bool $submitted,
        bool $passed,
        ?string $error,
    ) {
        $this->submitted = $submitted;
        $this->passed = $passed;
        $this->error = $error;
    }
}
