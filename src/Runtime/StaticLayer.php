<?php

declare(strict_types=1);

namespace Captcha\Runtime;

use Captcha\Captcha;
use Captcha\Config\Config;
use Captcha\Config\ConfigFile;
use Captcha\Exception\InvalidConfigException;

/**
 * La capa estática con ámbito de petición detrás del azúcar estático de
 * Captcha.
 *
 * Posee el singleton, las opciones programáticas fijadas con configure() y
 * el descubrimiento del fichero de configuración (env → anclas de raíz
 * firmadas → cwd), con las variables CAPTCHA_* matizando por opción lo que
 * se descubra, de modo que la fachada conserva solo el flujo de dominio. PHP reinicia el estado estático en cada petición, así que el
 * singleton jamás se filtra entre peticiones; reset() lo descarta dentro de
 * una petición (tests de larga vida, reconfiguración).
 *
 * El backend de retos no se decide aquí: lo elige StorageResolver dentro de
 * la propia construcción de Captcha, para que el camino estático y el armado
 * a mano resuelvan lo mismo.
 *
 * @internal
 */
final class StaticLayer
{
    /**
     * Las rutas de configuración relativas a una raíz de proyecto, en orden de
     * precedencia.
     *
     * La lista mezcla las convenciones de cada framework: app/Config/ en
     * CodeIgniter, Janssen y PHP plano, config/ en Laravel, Symfony, CakePHP
     * y Yii — que es donde `install` escribe hoy el fichero para todos ellos.
     * config/packages/ y etc/ quedan por retrocompatibilidad con instalaciones
     * anteriores a ese cambio: no es un capricho del core, es la ruta que
     * emitían las rc; retirarla ahora dejaría a esas apps con un config que
     * existiría y nadie leería.
     */
    private const CONFIG_SUBPATHS = [
        'app/Config/captcha.php',
        'config/captcha.php',
        'config/packages/captcha.php',
        'etc/captcha.php',
    ];

    /**
     * Prefijo de las variables de entorno que sobreescriben opciones de
     * Config por opción (CAPTCHA_LENGTH, CAPTCHA_NOISE...), nombradas como la
     * clave en mayúsculas —y también en su variante guionada,
     * CAPTCHA_RATE_LIMIT_BY_IP para rateLimitByIp—. Conviven con
     * CAPTCHA_CONFIG: la una apunta al fichero, las otras matizan opciones
     * encima de lo que se descubra.
     */
    private const ENV_PREFIX = 'CAPTCHA_';

    /**
     * Opciones booleanas que llegan por env como texto y deben convertirse
     * antes de Config::fromArray(), que solo acepta bool reales.
     *
     * @var list<string>
     */
    private const ENV_BOOLEAN_KEYS = [
        'noise',
        'distortion',
        'injectAssets',
        'honeypot',
        'rateLimitByIp',
    ];

    /**
     * Opciones que por env viajan como JSON (arrays): operations, between y
     * trustedProxies. El resto de claves pasa tal cual: Config ya sabe
     * coaccionar cadenas numéricas y textos.
     *
     * @var list<string>
     */
    private const ENV_ARRAY_KEYS = [
        'operations',
        'between',
        'trustedProxies',
    ];

    private static ?Captcha $instance = null;

    /**
     * Opciones fijadas programáticamente vía Captcha::configure(); tienen
     * precedencia sobre cualquier fichero de configuración descubierto. Un
     * array se valida al fijarlo y se guarda ya convertido a Config, así
     * que bootstrap() no repite la conversión. null significa "sin opciones
     * manuales": decide el descubrimiento.
     */
    private static ?Config $manualOptions = null;

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
     * El array se valida AQUÍ y no en el primer instance(): un typo tiene que
     * quejarse en la línea que lo escribió, no mucho más tarde dentro del
     * bootstrap de la aplicación.
     *
     * @param array<string, mixed>|Config $options
     *
     * @throws InvalidConfigException Cuando un array trae claves o valores
     *                                que no pasan Config::fromArray().
     */
    public static function configure(Config|array $options): void
    {
        self::$manualOptions = match (true) {
            $options === [] => null,
            is_array($options) => Config::fromArray($options),
            default => $options,
        };
        self::$instance = null;
    }

    /**
     * ¿Hay opciones fijadas con configure()? Doctor lo usa para no atribuir
     * al descubrimiento una configuración que, en realidad, vino de la mano.
     */
    public static function hasManualConfig(): bool
    {
        return self::$manualOptions !== null;
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
     * ¿Hay variables CAPTCHA_* aplicando opciones encima del descubrimiento?
     * Doctor lo usa para nombrar lo que ya no viene solo del fichero.
     */
    public static function hasEnvOverrides(): bool
    {
        return self::envOverrides() !== [];
    }

    /**
     * Los nombres de las opciones que las variables CAPTCHA_* fijan, en el
     * orden de Config::KEYS (estable para el reporte).
     *
     * @return list<string>
     */
    public static function envOptionNames(): array
    {
        return array_keys(self::envOverrides());
    }

    /**
     * Las opciones fijadas por env, ya normalizadas para
     * Config::fromArray(). Un valor vacío se ignora —igual que el
     * CAPTCHA_CONFIG vacío del descubrimiento— y un valor inválido lanza.
     * Cada clave se consulta en su forma canónica y, si no está declarada,
     * en la guionada (ver envRaw()).
     *
     * @throws InvalidConfigException
     *
     * @return array<string, mixed>
     */
    private static function envOverrides(): array
    {
        $overrides = [];

        foreach (Config::KEYS as $key) {
            $raw = self::envRaw($key);

            if (is_string($raw) && $raw !== '') {
                $overrides[$key] = self::envValue($key, $raw);
            }
        }

        return $overrides;
    }

    /**
     * La variable cruda de una opción: primero la forma canónica
     * (CAPTCHA_RATELIMITBYIP), después la guionada (CAPTCHA_RATE_LIMIT_BY_IP).
     * Si existen las dos, gana la canónica aunque esté vacía: la precedencia
     * no depende del orden de consulta y un vacío sigue significando
     * "ignorar la opción". false de getenv() significa "no declarada".
     */
    private static function envRaw(string $key): string|false
    {
        $canonica = self::ENV_PREFIX . strtoupper($key);
        $valor = getenv($canonica);

        if (is_string($valor)) {
            return $valor;
        }

        return getenv(self::ENV_PREFIX . self::enSnake($key));
    }

    /**
     * La clave en mayúsculas con guiones bajos (rateLimitByIp ⇒
     * RATE_LIMIT_BY_IP). El guionado se calcula sobre la grafía camelCase
     * original: strtoupper() aplana las letras y perdería los límites de
     * palabra.
     */
    private static function enSnake(string $key): string
    {
        return strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key);
    }

    /**
     * Normaliza el valor crudo de una variable CAPTCHA_* a lo que el lector de
     * Config espera: los booleanos llegan como texto y se convierten, los
     * arrays viajan como JSON y el resto pasa tal cual — Config coacciona las
     * cadenas numéricas y rechaza lo que no cuadre.
     *
     * @throws InvalidConfigException
     *
     * @return bool|array<mixed>|string|null
     */
    private static function envValue(string $key, string $raw): bool|array|string|null
    {
        return match (true) {
            in_array($key, self::ENV_BOOLEAN_KEYS, true) => self::envBoolean($key, $raw),
            in_array($key, self::ENV_ARRAY_KEYS, true) => self::envArray($key, $raw),
            default => $raw,
        };
    }

    /**
     * Dial booleano por env: se aceptan las grafías habituales (1/0,
     * true/false, yes/no, on/off) y cualquier otra se rechaza — un texto
     * inesperado no debe desactivar un dial de seguridad en silencio.
     *
     * @throws InvalidConfigException
     */
    private static function envBoolean(string $key, string $raw): bool
    {
        return match (strtolower($raw)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new InvalidConfigException(sprintf(
                "'%s' por env debe ser un booleano (1/0, true/false, yes/no, on/off).",
                $key,
            )),
        };
    }

    /**
     * Opción de array por env: el valor es JSON. Un JSON inválido o un tipo
     * que no es lista se rechazan; 'between' admite además el literal 'null'
     * (sin rango), como en el fichero.
     *
     * @throws InvalidConfigException
     *
     * @return array<mixed>|null
     */
    private static function envArray(string $key, string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidConfigException(sprintf("'%s' por env debe ser JSON válido.", $key));
        }
        if (is_array($decoded) || ($key === 'between' && $decoded === null)) {
            return $decoded;
        }

        throw new InvalidConfigException(sprintf("'%s' por env debe ser un array.", $key));
    }

    /**
     * Transparencia del descubrimiento para Console\Doctor: cada candidata
     * en orden de sondeo con la decisión que el descubrimiento tomó o tomaría
     * — cargada (la ganadora), ignorada (un fichero anclado sin la firma del
     * paquete), rota (una CAPTCHA_CONFIG que no apunta a ningún fichero) o
     * sombreada (un fichero válido que perdió contra una candidata
     * anterior). Introspección pura y de solo lectura; jamás construye la
     * instancia.
     *
     * @return list<array{path: string, anchored: bool, env: bool, loaded: bool, signed: bool}>
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
     * @param array{path: string, anchored: bool, env: bool} $candidate
     *
     * @return array{path: string, anchored: bool, env: bool, loaded: bool, signed: bool}
     */
    private static function describeCandidata(array $candidate, ?string $winner): array
    {
        $path = $candidate['path'];

        return [
            'path' => $path,
            'anchored' => $candidate['anchored'],
            'env' => $candidate['env'],
            'loaded' => $winner !== null && $winner === $path,
            'signed' => !$candidate['anchored'] || (is_file($path) && ConfigFile::carriesSignature($path)),
        ];
    }

    /**
     * Descubre la configuración y construye el singleton.
     *
     * Las opciones fijadas con configure() ganan; entonces se descubre un
     * fichero y las variables CAPTCHA_* matizan lo descubierto encima. El
     * orden estricto de candidatas es el de discoverPath() (gana el primer
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
        $options = self::$manualOptions ?? Config::fromArray(self::effectiveOptions());

        return new Captcha(config: $options);
    }

    /**
     * Las opciones de la capa estática: el fichero descubierto (o defaults)
     * con las variables CAPTCHA_* encima. configure() se salta esto — sus
     * opciones viven ya en self::$manualOptions.
     *
     * @throws InvalidConfigException
     *
     * @return array<string, mixed>
     */
    private static function effectiveOptions(): array
    {
        return array_replace(self::discoverOptions(), self::envOverrides());
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
     * Una CAPTCHA_CONFIG vacío o que apunte a un fichero inexistente se salta
     * sin más: en runtime el silencio es a propósito (siguen las anclas y,
     * si no, los defaults), y nombrar la variable rota le toca al reporte de
     * Console\Doctor.
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
     * @param array{path: string, anchored: bool, env: bool} $candidate
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
     * @return list<array{path: string, anchored: bool, env: bool}>
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
     * La ruta que llegue por el entorno, si viene. Una variable presente pero
     * vacía también se expone: el descubrimiento la salta igual que a una
     * ruta inexistente, pero Doctor necesita poder nombrarla en lugar de
     * callarla.
     *
     * @return list<array{path: string, anchored: bool, env: bool}>
     */
    private static function envCandidates(): array
    {
        $env = getenv('CAPTCHA_CONFIG');

        return is_string($env) ? [['path' => $env, 'anchored' => false, 'env' => true]] : [];
    }

    /**
     * Las rutas de configuración relativas a cada raíz; el anclaje marca si la
     * firma es obligatoria para aceptarlas.
     *
     * @param list<string> $roots
     *
     * @return list<array{path: string, anchored: bool, env: bool}>
     */
    private static function configCandidates(array $roots, bool $anchored): array
    {
        $candidates = [];

        foreach ($roots as $root) {
            foreach (self::CONFIG_SUBPATHS as $subpath) {
                $candidates[] = ['path' => $root . '/' . $subpath, 'anchored' => $anchored, 'env' => false];
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
}
