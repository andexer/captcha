<?php

declare(strict_types=1);

namespace Captcha\Runtime;

/**
 * Detección del host en runtime para los defaults que dependen de la app
 * circundante.
 *
 * La capa estática de fábrica jamás debe preemptar al gestor de sesión de un
 * framework propietario de las sesiones PHP (CodeIgniter, Laravel,
 * Symfony...): esos handlers llaman a ini_set() sobre ajustes de sesión
 * mientras se construyen, lo que eleva un warning de PHP en cuanto ya hay
 * una sesión activa. Cuando uno de esos kernels está cargado, el storage y
 * el rate limiter por defecto pasan por tanto a los backends sin sesión
 * (FileStorage / ficheros por IP) para que el captcha funcione con cero
 * configuración y cero interferencia de sesión.
 *
 * La detección es deliberadamente conservadora: class_exists() con autoload
 * desactivado, de modo que solo cuentan kernels ya cargados por el
 * framework. Una app de PHP plano, una corrida CLI o la propia suite de
 * tests del paquete jamás la disparan. Quien integra siempre puede forzar
 * un backend con la opción "storage" de Config ('session', 'file', 'array').
 *
 * @internal
 */
final class Host
{
    /**
     * Clases núcleo cuya presencia significa "el host es dueño de la sesión
     * PHP" y el captcha no debe llamar a session_start().
     *
     * CodeIgniter, Laravel y Symfony respaldan los guards por defecto;
     * CakePHP y Yii se incluyen para que el paquete los reconozca del mismo
     * modo (su auto-storage aterriza en FileStorage y la cookie de sesión
     * jamás se endurece ahí, honrando el contrato de "jamás preemptar al
     * host").
     *
     * @var list<class-string>
     */
    private const SESSION_HOSTS = [
        \CodeIgniter\CodeIgniter::class,
        \Illuminate\Foundation\Application::class,
        \Symfony\Component\HttpKernel\Kernel::class,
        \Cake\Core\Application::class,
        \yii\BaseYii::class,
    ];

    /**
     * Override reservado a la suite de tests; null = autodetección.
     */
    private static ?bool $frameworkSessionForced = null;

    /**
     * Override de la suite de tests para isWeb(); null = autodetección.
     * Necesario porque phpunit siempre corre bajo el SAPI CLI, así que la
     * rama web (fail-closed ante una dirección de cliente ausente) es
     * inalcanzable desde un test por otra vía.
     */
    private static ?bool $webForced = null;

    /**
     * Si el runtime actual lo gestiona un framework propietario de la sesión
     * PHP, de modo que el paquete no debe arrancar una por su cuenta.
     */
    public static function frameworkSessionManaged(): bool
    {
        if (self::$frameworkSessionForced !== null) {
            return self::$frameworkSessionForced;
        }

        foreach (self::SESSION_HOSTS as $class) {
            if (class_exists($class, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si el proceso sirve peticiones HTTP.
     *
     * Un proceso CLI no tiene cliente remoto, así que no es un cliente que
     * pueda inundar nada: el rate limiter es un presupuesto por cliente, y
     * un REMOTE_ADDR ausente en CLI significa "nadie está llamando", no "un
     * llamador sin medir está llamando". Quienes llaman lo usan para
     * distinguir ambos casos antes de aplicar una política fail-closed, que
     * pertenece solo al caso web.
     */
    public static function isWeb(): bool
    {
        if (self::$webForced !== null) {
            return self::$webForced;
        }

        return PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg';
    }

    /**
     * @internal Override de la suite de tests. Pasar null restaura la
     *           autodetección.
     */
    public static function forceWeb(?bool $value): void
    {
        self::$webForced = $value;
    }

    /**
     * @internal Override de la suite de tests. Pasar null restaura la
     *           autodetección.
     */
    public static function forceFrameworkSession(?bool $value): void
    {
        self::$frameworkSessionForced = $value;
    }
}
