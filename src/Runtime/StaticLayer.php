<?php

declare(strict_types=1);

namespace Captcha\Runtime;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Config\ConfigFile;
use Captcha\Contract\StorageInterface;
use Captcha\Exception\InvalidConfigException;
use Captcha\Storage\ArrayStorage;
use Captcha\Storage\FileStorage;
use Captcha\Storage\SessionStorage;

/**
 * La capa estática con ámbito de petición detrás del azúcar estático de
 * Captcha.
 *
 * Posee el singleton, las opciones programáticas fijadas con configure() y
 * el descubrimiento del fichero de configuración (env → anclas de raíz
 * firmadas → cwd), de modo que la fachada conserva solo el flujo de
 * dominio. PHP reinicia el estado estático en cada petición, así que el
 * singleton jamás se filtra entre peticiones; reset() lo descarta dentro de
 * una petición (tests de larga vida, reconfiguración).
 *
 * @internal
 */
final class StaticLayer
{
    /**
     * Auto-storage bajo un framework: el backend de ficheros vive en un
     * directorio estable por host (sys_get_temp_dir()) compartido por
     * formulario y endpoint.
     */
    private const FILE_STORAGE_DIR = '/captcha';

    /**
     * Las rutas de configuración relativas a una raíz de proyecto, en orden de
     * precedencia.
     *
     * La lista mezcla las convenciones de cada framework porque es el mismo
     * paquete el que `install` coloca el fichero donde su framework lo espera:
     * app/Config/ en CodeIgniter y PHP plano, config/ en Laravel, CakePHP y
     * Yii, config/packages/ en Symfony, que no es un capricho del core sino lo
     * que emite el propio instalador para ese framework — sin esta entrada,
     * el config que `install --framework=symfony` deja escrito no lo
     * encontraría nadie.
     */
    private const CONFIG_SUBPATHS = [
        'app/Config/captcha.php',
        'config/captcha.php',
        'config/packages/captcha.php',
        'etc/captcha.php',
    ];

    private static ?Captcha $instance = null;

    /**
     * Opciones fijadas programáticamente vía Captcha::configure(); tienen
     * precedencia sobre cualquier fichero de configuración descubierto. Una
     * instancia Config se usa tal cual; un array se parsea (y se valida de
     * forma estricta). null significa "sin opciones manuales": decide el
     * descubrimiento.
     */
    /**
     * @var Config|array<string, mixed>|null
     */
    private static Config|array|null $manualOptions = null;

    /**
     * La instancia con ámbito de petición, creada en el primer uso.
     *
     * @throws InvalidConfigException Cuando el fichero de configuración
     *                                descubierto no devuelve un array o
     *                                contiene valores erróneos.
     */
    public static function instance(): Captcha
    {
        return self::$instance ??= self::bootstrap();
    }

    /**
     * Suelta el singleton; el siguiente instance() lo reconstruye. Las
     * opciones manuales sobreviven — pertenecen a configure(), no a la
     * instancia.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Guarda las opciones programáticas y suelta el singleton para que la
     * siguiente llamada estática reconstruya con ellas. Un array vacío libera
     * las opciones y restaura el descubrimiento por fichero.
     *
     * @param array<string, mixed>|Config $options
     */
    public static function configure(Config|array $options): void
    {
        self::$manualOptions = $options === [] ? null : $options;
        self::$instance = null;
    }

    /**
     * Ruta del fichero de configuración que el descubrimiento cargaría, o
     * null cuando ni existen opciones manuales ni ningún fichero
     * descubrible. Refleja el orden de bootstrap() para que el diagnóstico
     * reporte lo que la capa estática carga de verdad; las opciones manuales
     * responden null sin tocar el disco.
     */
    public static function configPath(): ?string
    {
        if (self::$manualOptions !== null) {
            return null;
        }

        return self::discoverPath();
    }

    /**
     * Transparencia del descubrimiento para Console\Doctor: cada candidata
     * en orden de sondeo con la decisión que el descubrimiento tomó o tomaría
     * — cargada (la ganadora), ignorada (un fichero anclado sin la firma del
     * paquete) o sombreada (un fichero válido que perdió contra una
     * candidata anterior). Introspección pura y de solo lectura; jamás
     * construye la instancia.
     *
     * @return list<array{path: string, anchored: bool, loaded: bool, signed: bool}>
     */
    public static function candidatesForDiagnostics(): array
    {
        $winner = self::discoverPath();
        $out = [];

        foreach (self::candidates() as $candidate) {
            $out[] = self::describeCandidata($candidate, $winner);
        }

        return $out;
    }

    /**
     * @param array{path: string, anchored: bool} $candidate
     *
     * @return array{path: string, anchored: bool, loaded: bool, signed: bool}
     */
    private static function describeCandidata(array $candidate, ?string $winner): array
    {
        $path = $candidate['path'];

        return [
            'path' => $path,
            'anchored' => $candidate['anchored'],
            'loaded' => $winner !== null && $winner === $path,
            'signed' => !$candidate['anchored'] || (is_file($path) && ConfigFile::carriesSignature($path)),
        ];
    }

    /**
     * Descubre la configuración y construye el singleton.
     *
     * Las opciones fijadas con configure() ganan; en caso contrario se
     * descubre un fichero de configuración en orden estricto (gana el primer
     * acierto), defaults cuando nada casa:
     * 1. getenv("CAPTCHA_CONFIG")
     * 2. {raíz del proyecto}/app/Config/captcha.php      (firmado — ver ConfigFile)
     * 3. {raíz del proyecto}/config/captcha.php          (firmado)
     * 4. {raíz del proyecto}/config/packages/captcha.php (firmado)
     * 5. {raíz del proyecto}/etc/captcha.php             (firmado)
     * 6. {cwd}/app/Config/captcha.php
     * 7. {cwd}/config/captcha.php
     * 8. {cwd}/config/packages/captcha.php
     * 9. {cwd}/etc/captcha.php
     *
     * El ancla de {raíz del proyecto} no depende del cwd web (un framework
     * sirve desde su docroot, p. ej. public/, no desde la raíz del proyecto);
     * se sube caminando desde la ubicación de instalación del paquete. El
     * paquete vive o bien en {root}/vendor/andexer/captcha/src (cuatro
     * niveles) o, con un symlink de path-repository, en
     * {root}/<cualquiera>/captcha/src (tres niveles tras resolver el
     * symlink), así que se sondean ambos anclajes.
     *
     * @throws InvalidConfigException
     */
    private static function bootstrap(): Captcha
    {
        $config = self::$manualOptions !== null
            ? self::manualConfig()
            : Config::fromArray(self::discoverOptions());

        return new Captcha(storage: self::resolvedStorage($config), config: $config);
    }

    /**
     * configure() sustituye el descubrimiento: lo que se pasó a mano gana, y
     * sin llamada manual se busca el fichero; sin ninguna de las dos, defaults.
     */
    private static function manualConfig(): Config
    {
        return self::$manualOptions instanceof Config
            ? self::$manualOptions
            : Config::fromArray(self::$manualOptions ?? []);
    }

    /**
     * Array de opciones desde el primer fichero descubrible, o [] para los
     * defaults.
     *
     * @throws InvalidConfigException Cuando el fichero no devuelve un array.
     *
     * @return array<string, mixed>
     */
    private static function discoverOptions(): array
    {
        $path = self::discoverPath();

        if ($path === null) {
            return [];
        }

        $loaded = require $path;

        if (!is_array($loaded)) {
            throw new InvalidConfigException(self::configNoEsArray());
        }
        return self::normalizaClaves($loaded);
    }

    /**
     * Las claves del config se pasan a string: PHP convierte a string los
     * índices numéricos de un array, y Config::fromArray() espera textuales.
     *
     * @param array<array-key, mixed> $loaded
     *
     * @return array<string, mixed>
     */
    private static function normalizaClaves(array $loaded): array
    {
        return array_combine(array_map(strval(...), array_keys($loaded)), array_values($loaded));
    }

    private static function configNoEsArray(): string
    {
        return 'El fichero de configuración debe devolver un array.';
    }

    /**
     * Primera candidata de configuración existente y aceptada, o null.
     *
     * Las candidatas ancladas a la raíz deben llevar la firma del paquete
     * (opt-in): el anclaje es la única vía de descubrimiento que adivina, así
     * que en un checkout anidado (paquete dentro de un proyecto host) podría
     * elegir el config de otra app y cambiar la postura de seguridad en
     * silencio. Los ficheros aportados por env o cwd son explícitos y jamás
     * exigen el marcador.
     */
    private static function discoverPath(): ?string
    {
        foreach (self::candidates() as $candidate) {
            if (self::esAnclaValida($candidate)) {
                return $candidate['path'];
            }
        }

        return null;
    }

    /**
     * Una candidatura se acepta si existe y (si es ancla) lleva la firma. El
     * diagnóstico de los anclados sin firma queda para Console\Doctor.
     *
     * @param array{path: string, anchored: bool} $candidate
     */
    private static function esAnclaValida(array $candidate): bool
    {
        if (!is_file($candidate['path'])) {
            return false;
        }

        if ($candidate['anchored'] && !ConfigFile::carriesSignature($candidate['path'])) {
            return false;
        }

        return true;
    }

    /**
     * @return list<array{path: string, anchored: bool}>
     */
    private static function candidates(): array
    {
        return array_merge(
            self::envCandidates(),
            self::configCandidates(self::rootAnchors(), anchored: true),
            self::configCandidates(self::cwdRoots(), anchored: false),
        );
    }

    /**
     * La ruta que llegue por el entorno, si viene.
     *
     * @return list<array{path: string, anchored: bool}>
     */
    private static function envCandidates(): array
    {
        $env = getenv('CAPTCHA_CONFIG');

        return is_string($env) && $env !== '' ? [['path' => $env, 'anchored' => false]] : [];
    }

    /**
     * Las rutas de configuración relativas a cada raíz; el anclaje marca si la
     * firma es obligatoria para aceptarlas.
     *
     * @param list<string> $roots
     *
     * @return list<array{path: string, anchored: bool}>
     */
    private static function configCandidates(array $roots, bool $anchored): array
    {
        $candidates = [];

        foreach ($roots as $root) {
            foreach (self::CONFIG_SUBPATHS as $subpath) {
                $candidates[] = ['path' => $root . '/' . $subpath, 'anchored' => $anchored];
            }
        }

        return $candidates;
    }

    /**
     * El directorio de trabajo actual, como raíz sin anclar.
     *
     * @return list<string>
     */
    private static function cwdRoots(): array
    {
        $cwd = getcwd();

        return is_string($cwd) ? [$cwd] : [];
    }

    /**
     * Raíces de proyecto plausibles relativas a la propia ubicación del
     * paquete.
     *
     * Prueba tanto la ruta tal como se cargó como la resuelta tras symlink,
     * a los tres y cuatro niveles de padre que distinguen una instalación
     * /vendor/andexer/captcha de una copia por path-repository dentro del
     * proyecto. Los duplicados se eliminan, conservando intacta la semántica
     * de primer acierto.
     *
     * @return list<string>
     */
    private static function rootAnchors(): array
    {
        $anchors = [];

        foreach (self::packagePaths() as $path) {
            foreach ([3, 4] as $levels) {
                $anchor = dirname($path, $levels);
                if (self::anclaUtilizable($anchor, $path)) {
                    $anchors[] = $anchor;
                }
            }
        }

        return array_values(array_unique($anchors));
    }

    private static function anclaUtilizable(string $anchor, string $path): bool
    {
        return $anchor !== $path && $anchor !== '' && $anchor !== '/';
    }

    /**
     * El directorio del propio paquete tal como se cargó y, cuando difiera,
     * el resuelto tras symlink.
     *
     * dirname(__DIR__) = el directorio src/, SIN componente '..' literal:
     * dirname() con niveles cuenta un '..' del path como un nivel más y
     * los anclajes caerían un directorio por encima del raíz real (bug
     * cazado por el playtest: en web el cwd es el docroot y el config
     * dejaba de descubrirse en cualquier layout).
     *
     * @return list<string>
     */
    private static function packagePaths(): array
    {
        $packageDir = dirname(__DIR__);
        $real = realpath($packageDir);

        if (is_string($real) && $real !== $packageDir) {
            return [$packageDir, $real];
        }

        return [$packageDir];
    }

    /**
     * Almacenamiento de retos implícito en la opción "storage" de Config.
     *
     * "auto" (el default) jamás preempta el gestor de sesión de un framework
     * propietario de las sesiones PHP: resuelve a FileStorage en tal host y a
     * SessionStorage en una app de PHP plano, una corrida CLI o la propia
     * suite de tests del paquete. "session", "file" y "array" fuerzan el
     * backend correspondiente sin importar el host.
     */
    private static function resolvedStorage(Config $config): StorageInterface
    {
        return match ($config->storage) {
            'file' => new FileStorage(sys_get_temp_dir() . self::FILE_STORAGE_DIR),
            'array' => new ArrayStorage(),
            'session' => new SessionStorage(),
            default => Host::frameworkSessionManaged()
                ? new FileStorage(sys_get_temp_dir() . self::FILE_STORAGE_DIR)
                : new SessionStorage(),
        };
    }
}
