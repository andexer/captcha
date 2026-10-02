<?php

declare(strict_types=1);

namespace Captcha\Exception;

/**
 * Lanzada cuando un backend de almacenamiento no puede leer, escribir o
 * eliminar una entrada.
 *
 * Los mensajes nunca incluyen códigos ni identificadores del captcha.
 */
final class StorageException extends CaptchaException {}
