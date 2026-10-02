<?php

declare(strict_types=1);

namespace Captcha\Exception;

/**
 * Excepción base para cualquier error lanzado por este paquete.
 *
 * Captúrala para gestionar cualquier fallo del captcha. Las subclases
 * concretas (GdNotAvailableException, StorageException, InvalidConfigException)
 * permiten un manejo de grano más fino.
 */
class CaptchaException extends \RuntimeException {}
