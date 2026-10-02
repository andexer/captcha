<?php

declare(strict_types=1);

namespace Captcha\Exception;

/**
 * Lanzada cuando la extensión GD de PHP falta o no es utilizable.
 *
 * Se eleva de forma temprana (al construir el renderizador o en su primer
 * uso) para que los entornos mal configurados fallen rápido en lugar de
 * esperar a la primera petición de captcha.
 */
final class GdNotAvailableException extends CaptchaException {}
