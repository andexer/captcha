<?php

declare(strict_types=1);

namespace Captcha\Console;

use Captcha\Exception\InvalidConfigException;

/**
 * Planificación pura detrás de `vendor/bin/captcha install --framework=...`.
 *
 * Calcula la ruta de destino y carga la plantilla de arranque de cada
 * framework soportado. El script del bin ejecuta la I/O real (escribir,
 * jamás sobrescribir un fichero existente) para que src/ quede libre de echo
 * y de efectos secundarios de file_put_contents, siguiendo la misma
 * división que Doctor.
 *
 * Las plantillas de arranque son ficheros completos y válidos desde el
 * primer momento: un esqueleto `return []` activo con todas las opciones
 * comentadas dentro, de modo que el config generado aplica los defaults
 * hasta que quien integra descomente una línea.
 *
 * artifacts() amplía el alcance respecto al config solo: además del arranque,
 * devuelve el pegamento de cada framework (provider, middleware, listener,
 * ruta del endpoint) leído de Integration::PER_FRAMEWORK. Sigue sin escribir
 * nada; eso lo hace el bin.
 *
 * @internal
 */
final class Installer
{
    /**
     * Framework → { ruta de destino relativa a la raíz del proyecto, plantilla
     * de arranque bajo src/app/Config/templates/ }.
     *
     * @var array<string, array{path: string, template: string}>
     */
    private const FRAMEWORKS = [
        'plain' => ['path' => 'app/Config/captcha.php', 'template' => 'plain.php'],
        'codeigniter' => ['path' => 'app/Config/captcha.php', 'template' => 'codeigniter.php'],
        'laravel' => ['path' => 'config/captcha.php', 'template' => 'laravel.php'],
        'symfony' => ['path' => 'config/packages/captcha.php', 'template' => 'symfony.php'],
        'cakephp' => ['path' => 'config/captcha.php', 'template' => 'cakephp.php'],
        'yii' => ['path' => 'config/captcha.php', 'template' => 'yii.php'],
        'janssen' => ['path' => 'app/Config/captcha.php', 'template' => 'janssen.php'],
    ];

    /**
     * Los identificadores de framework soportados, en orden de definición.
     *
     * @return list<string>
     */
    public static function frameworks(): array
    {
        return array_keys(self::FRAMEWORKS);
    }

    /**
     * La ruta de destino de un framework dentro de una raíz de proyecto.
     *
     * @throws InvalidConfigException Cuando el framework es desconocido.
     */
    public static function targetPath(string $projectRoot, string $framework): string
    {
        return rtrim($projectRoot, '/') . '/' . self::entry($framework)['path'];
    }

    /**
     * La plantilla de arranque cruda de un framework.
     *
     * @throws InvalidConfigException Cuando el framework es desconocido o el
     *                                fichero de plantilla falta.
     */
    public static function templateContents(string $framework): string
    {
        $path = __DIR__ . '/../app/Config/templates/' . self::entry($framework)['template'];
        $contents = is_file($path) ? (string) file_get_contents($path) : false;

        if ($contents === false) {
            throw new InvalidConfigException(sprintf('No se pudo leer la plantilla del framework "%s".', $framework));
        }

        return $contents;
    }

    /**
     * Todo lo que `install` deja escrito para un framework: primero el config
     * de arranque y después el pegamento que ese framework necesita.
     *
     * Los dos grupos salen de sitios distintos a propósito. El config lo
     * describe el mapa FRAMEWORKS de arriba y se renderiza desde un fragmento
     * compartido; el pegamento lo describe Integration::PER_FRAMEWORK y cada
     * fichero es una plantilla propia, porque un service provider de Laravel y
     * un listener de Symfony no comparten ni una línea.
     *
     *
     * @throws InvalidConfigException Cuando el framework es desconocido o falta
     *                                alguna plantilla.
     *
     * @return list<Artifact>
     */
    public static function artifacts(string $projectRoot, string $framework): array
    {
        return array_merge(
            [self::configArtifact($projectRoot, $framework)],
            self::integrationArtifacts($projectRoot, $framework),
        );
    }

    private static function configArtifact(string $projectRoot, string $framework): Artifact
    {
        return new Artifact(
            self::targetPath($projectRoot, $framework),
            self::templateContents($framework),
        );
    }

    /**
     * El pegamento del framework, en el orden en que Integration lo declara.
     *
     *
     * @throws InvalidConfigException Cuando falta alguna plantilla.
     *
     * @return list<Artifact>
     */
    private static function integrationArtifacts(string $projectRoot, string $framework): array
    {
        $artifacts = [];

        foreach (Integration::for($framework) as $entry) {
            $artifacts[] = new Artifact(
                rtrim($projectRoot, '/') . '/' . $entry['path'],
                Integration::contents($entry['template']),
                $entry['notes'],
            );
        }

        return $artifacts;
    }

    /**
     * @throws InvalidConfigException Cuando el framework es desconocido.
     *
     * @return array{path: string, template: string}
     */
    private static function entry(string $framework): array
    {
        if (!isset(self::FRAMEWORKS[$framework])) {
            throw new InvalidConfigException(sprintf(
                'Framework "%s" no reconocido; usa "%s".',
                $framework,
                implode('", "', self::frameworks()),
            ));
        }

        return self::FRAMEWORKS[$framework];
    }
}
