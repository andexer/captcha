<?php

declare(strict_types=1);

namespace Captcha\Exception;

/**
 * Lanzada cuando un valor de configuración falta, está fuera de rango o no
 * está soportado.
 *
 * Semánticamente equivale a un error de argumento inválido; extiende
 * CaptchaException para que un único catch gestione cualquier fallo del
 * paquete.
 */
final class InvalidConfigException extends CaptchaException {}
