<?php

declare(strict_types=1);

namespace Captcha\Console;

use Throwable;

/**
 * Catálogo de comandos y opciones globales de la consola.
 *
 * Es la única fuente de verdad sobre lo que la CLI sabe hacer. El parser
 * rechaza lo que no está aquí, la ayuda se deriva de aquí y `list` imprime
 * lo que hay aquí: añadir un comando obliga a tocar este fichero, y tocarlo
 * cambia las tres cosas a la vez. Esa es la diferencia entre una CLI que se
 * documenta sola y una que se documenta en tres sitios y miente en dos.
 *
 * @internal
 */
final class Registry
{
    /**
     * Nombre del paquete, tal y como aparece en `composer require`.
     */
    public const PACKAGE = 'andexer/captcha';

    /**
     * Lo que se responde cuando no hay runtime de Composer que pregunte.
     */
    private const VERSION_DESCONOCIDA = 'desconocida (no instalada con Composer)';

    /**
     * @return list<Command>
     */
    public static function commands(): array
    {
        return [
            self::install(),
            self::doctor(),
            self::list(),
            self::help(),
        ];
    }

    /**
     * Opciones que admiten todos los comandos.
     *
     * @return list<Option>
     */
    public static function globalOptions(): array
    {
        return [
            Option::flag('help', 'Muestra la ayuda del comando.', 'h'),
            Option::flag('version', 'Muestra la versión del paquete.', 'V'),
            Option::flag('quiet', 'Reduce la salida a los errores.', 'q'),
            Option::flag('no-ansi', 'Desactiva el color en la salida.'),
        ];
    }

    /**
     * Busca un comando por su nombre o por cualquiera de sus alias.
     */
    public static function find(string $token): ?Command
    {
        foreach (self::commands() as $command) {
            if ($command->resolve($token) !== null) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Los tokens que hacen que se muestre la ayuda general.
     *
     * Se cuentan como alias de `help` para que `captcha -h`, `captcha help` y
     * `captcha` a secas produzcan exactamente la misma pantalla.
     *
     * @return list<string>
     */
    public static function helpTokens(): array
    {
        return ['help', '?', '--help', '-h'];
    }

    /**
     * La versión del paquete, o una etiqueta honesta si no se puede saber.
     *
     * No se inventa un número: se interroga a Composer cuando el proyecto
     * anfitrión lo publica (casi todos lo hacen vía composer-runtime-api) y,
     * si no, se declara lo que se sabe en lugar de un número plausible pero
     * falso, que es peor que no tener versión.
     */
    public static function version(): string
    {
        if (!class_exists('Composer\InstalledVersions')) {
            return self::VERSION_DESCONOCIDA;
        }

        return self::versionDeComposer() ?? self::VERSION_DESCONOCIDA;
    }

    /**
     * La versión que ve la runtime de Composer, o null si no la hay.
     *
     * El paquete no está dado de alta en InstalledVersions cuando se ejecuta
     * desde el propio repositorio o desde un phar, así que el fallo se
     * trata como "versión desconocida" y no como error de diagnóstico.
     */
    private static function versionDeComposer(): ?string
    {
        try {
            $version = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE);

            return is_string($version) && $version !== '' ? $version : null;
        } catch (Throwable) {
            return null;
        }
    }

    private const INSTALL_DESCRIPTION = <<<TXT
        Crea el config de arranque —todas las opciones comentadas, con los
        valores por defecto activos— y el pegamento propio del framework:
        middleware, filter, provider, listener o controlador de la ruta.
        Al terminar imprime, con la ruta por delante, los pasos de
        registro que no puede automatizar sin editar ficheros que ya son
        de la aplicación.

        Los destinos cuelgan de la raíz del proyecto —el directorio con
        composer.json, buscado hacia arriba desde donde se lanza—, no del
        directorio de trabajo.

        Con --dry-run enseña qué crearía sin escribir nada. Nunca
        sobrescribe un fichero que ya existe: para regenerarlo, bórralo
        antes.
        TXT;

    private static function install(): Command
    {
        return new Command(
            name: 'install',
            aliases: ['i', 'init'],
            summary: 'Crea el config y el pegamento de integración del framework.',
            description: self::INSTALL_DESCRIPTION,
            options: self::installOptions(),
            arguments: self::installArguments(),
            examples: self::installExamples(),
        );
    }

    /**
     * @return list<Option>
     */
    private static function installOptions(): array
    {
        return [
            Option::value(
                'framework',
                'Framework de destino; equivale al argumento posicional.',
                'f',
                implode('|', Installer::frameworks()),
                'laravel',
            ),
            Option::flag('dry-run', 'Muestra qué crearía, sin escribir nada.', 'n'),
        ];
    }

    /**
     * @return list<Argument>
     */
    private static function installArguments(): array
    {
        return [
            new Argument(
                'framework',
                false,
                'Uno de: ' . implode('|', Installer::frameworks()) . '. Por defecto, plain.',
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private static function installExamples(): array
    {
        return [
            'vendor/bin/captcha install',
            'vendor/bin/captcha install laravel',
            'vendor/bin/captcha install --framework=symfony',
            'vendor/bin/captcha install -n --framework=yii',
        ];
    }

    private const DOCTOR_DESCRIPTION = <<<TXT
        Revisa los requisitos de runtime (versión de PHP y ext-gd), el
        config que el descubrimiento encuentra, la configuración
        efectiva con su postura de seguridad y la presencia del endpoint
        de recarga. No escribe nada.

        Termina con código distinto de cero cuando falta ext-gd o la
        fachada no arranca, para poder vigilarlo desde un script de
        despliegue. Los avisos —honeypot apagado, rate limits a cero,
        ejecución en CLI— no rompen la salida por defecto porque casi
        siempre son deliberados; con --strict pasan a ser fatales.
        TXT;

    private static function doctor(): Command
    {
        return new Command(
            name: 'doctor',
            aliases: ['d'],
            summary: 'Comprueba el entorno y la configuración efectiva.',
            description: self::DOCTOR_DESCRIPTION,
            options: [Option::flag('strict', 'Trata también los avisos como error.', 's')],
            examples: ['vendor/bin/captcha doctor', 'vendor/bin/captcha doctor --strict'],
        );
    }

    private static function list(): Command
    {
        return new Command(
            name: 'list',
            aliases: ['ls'],
            summary: 'Lista los comandos disponibles.',
            description: 'Muestra cada comando con su resumen y sus alias. Es lo mismo que `captcha` sin argumentos.',
            examples: ['vendor/bin/captcha list', 'vendor/bin/captcha ls'],
        );
    }

    private static function help(): Command
    {
        return new Command(
            name: 'help',
            aliases: ['?'],
            summary: 'Muestra la ayuda de un comando.',
            description: 'Sin argumentos, muestra la ayuda general. Con un comando, muestra la suya.',
            arguments: [new Argument('comando', false, 'Comando sobre el que preguntar.')],
            examples: ['vendor/bin/captcha help', 'vendor/bin/captcha help install'],
        );
    }
}
