<?php

declare(strict_types=1);

namespace Captcha\Verification;

/**
 * Resultado de un intento de verificación de captcha.
 */
enum Status: string
{
    case Ok = 'ok';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Missing = 'missing';
    case Blocked = 'blocked';
}
