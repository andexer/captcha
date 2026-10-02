<?php

declare(strict_types=1);

namespace Captcha\Exception;

/**
 * Elevada cuando se excede una ventana de rate limit.
 *
 * La lanza generate() cuando se produjeron demasiados captchas para la misma
 * clave dentro de la ventana configurada; la petición debe rechazarse
 * (p. ej. HTTP 429) en lugar de renderizar otra imagen.
 */
final class RateLimitException extends CaptchaException {}
