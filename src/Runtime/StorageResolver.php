<?php

declare(strict_types=1);

namespace Captcha\Runtime;

use Captcha\Config\Config;
use Captcha\Contract\StorageInterface;
use Captcha\Storage\ArrayStorage;
use Captcha\Storage\FileStorage;
use Captcha\Storage\SessionStorage;

/**
 * Elige el backend de retos a partir de `Config::storage`. Es la misma
 * decisión de runtime para la capa estática (que construye el singleton) y
 * para una instancia armada a mano, de modo que la opción significa lo mismo
 * en los dos caminos: `auto` consulta si un framework gestiona la sesión PHP
 * (ver Host) y resuelve fichero en tal caso, sesión en PHP plano o CLI.
 *
 * @internal
 */
final class StorageResolver
{
    private const DIR_RETOS = '/captcha';

    public static function resolve(Config $config): StorageInterface
    {
        return match ($config->storage) {
            'file' => new FileStorage(sys_get_temp_dir() . self::DIR_RETOS),
            'array' => new ArrayStorage(),
            'session' => new SessionStorage(),
            default => Host::frameworkSessionManaged()
                ? new FileStorage(sys_get_temp_dir() . self::DIR_RETOS)
                : new SessionStorage(),
        };
    }
}
