<?php

declare(strict_types=1);

namespace Captcha\Config;

use Captcha\Exception\InvalidConfigException;

/**
 * Configuración inmutable y validada para la generación y el renderizado
 * del captcha.
 *
 * Todas las opciones se comprueban en tiempo de construcción: un valor
 * inválido lanza de inmediato y la instancia nunca es observable en un
 * estado inválido.
 */
final readonly class Config
{
    // ── DEFAULTS ─────────────────────────────────────────────────────────────

    public const DEFAULT_LENGTH = 6;
    public const DEFAULT_WIDTH = 180;
    public const DEFAULT_HEIGHT = 60;
    public const DEFAULT_TTL = 120;

    /**
     * Presupuestos de rate limit activos de fábrica.
     *
     * Ambos eran 0 (desactivados) por defecto, lo que dejaba una instalación
     * sin configurar expuesta a fuerza bruta: el flujo de verificación no
     * tenía techo de intentos. Los defaults son los valores que trae el
     * preset "strict", elegidos para que un usuario normal (unas cuantas
     * cargas de página y un envío) no los dispare. Desactivarlos sigue
     * siendo posible con un 0 explícito — nunca por omisión.
     */
    public const DEFAULT_VERIFY_ATTEMPTS = 5;
    public const DEFAULT_GENERATE_ATTEMPTS = 20;

    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 10;

    /**
     * Topes de tamaño del renderizador: acotar la imagen mantiene el consumo
     * de memoria bajo control (imagecreatetruecolor asigna ancho × alto × 4
     * bytes).
     */
    public const MAX_WIDTH = 5000;
    public const MAX_HEIGHT = 2000;

    /**
     * Cada clave de opción que fromArray() acepta, incluido el atajo
     * "preset".
     *
     * El modo estricto (el default) rechaza cualquier clave fuera de esta
     * lista: una errata en una opción de seguridad debe fallar de forma
     * ruidosa en lugar de dejar en silencio el default desactivado (p. ej.
     * "generateATempts" dejando el rate limiter apagado).
     */
    public const KEYS = [
        'preset',
        'length',
        'width',
        'height',
        'ttl',
        'difficulty',
        'font',
        'fontSize',
        'noise',
        'distortion',
        'output',
        'idField',
        'inputField',
        'injectAssets',
        'operations',
        'between',
        'verifyAttempts',
        'generateAttempts',
        'rateLimitWindow',
        'honeypot',
        'honeypotField',
        'rateLimitByIp',
        'storage',
        'trustedProxies',
    ];

    /**
     * Opciones que fromArray() coerciona a entero (o a null, solo fontSize).
     *
     * Viven aquí y no en la llamada porque son el contrato de la lectura: cada
     * vez que se añada una opción entera hay que decidir si admite cadena
     * numérica, y tener el grupo a la vista evita que se cuecla en un
     * site del lector.
     */
    private const INTEGER_KEYS = [
        'length',
        'width',
        'height',
        'ttl',
        'font',
        'fontSize',
        'verifyAttempts',
        'generateAttempts',
        'rateLimitWindow',
    ];

    /** @see self::INTEGER_KEYS */
    private const BOOLEAN_KEYS = [
        'noise',
        'distortion',
        'injectAssets',
        'honeypot',
        'rateLimitByIp',
    ];

    /** @see self::INTEGER_KEYS */
    private const TEXT_KEYS = [
        'output',
        'idField',
        'inputField',
        'honeypotField',
        'storage',
    ];

    /**
     * Paquetes de opciones listos para Config::fromArray('preset' => ...).
     *
     * "default" mantiene los defaults del constructor; "login" afina el
     * captcha para formularios de autenticación (pocos dígitos, glifos
     * grandes y legibles, sin ruido ni distorsión, rate limit activo);
     * "strict" sube todos los diales anti-spam. Las claves explícitas del
     * array de opciones siempre ganan sobre las del preset.
     */
    public const PRESETS = [
        'default' => [],
        'login' => [
            'length' => 5,
            'width' => 200,
            'height' => 60,
            'difficulty' => 'low',
            'noise' => false,
            'distortion' => false,
            'verifyAttempts' => 5,
            'generateAttempts' => 30,
        ],
        'strict' => [
            'length' => 6,
            'width' => 220,
            'height' => 64,
            'difficulty' => 'high',
            'noise' => true,
            'distortion' => true,
            'verifyAttempts' => 5,
            'generateAttempts' => 20,
            'honeypot' => true,
            'honeypotField' => 'website',
        ],
    ];

    // ── CONSTRUCCIÓN Y VALIDACIÓN ────────────────────────────────────────────

    /**
     * @param int $length Número de dígitos del código (3-10).
     * @param int $width Ancho de la imagen en píxeles.
     * @param int $height Alto de la imagen en píxeles.
     * @param int $ttl Tiempo de vida de un código almacenado, en segundos.
     * @param Difficulty $difficulty Gradúa la intensidad de ruido y distorsión.
     * @param int $font Número de la fuente bitmap integrada de GD (1-5);
     *                  5 = "large" (9×15), el default; 1 es la más pequeña y
     *                  4 la más alta (8×16). Tipografía sin TTF. Las métricas
     *                  se leen de GD en tiempo de renderizado, así que los
     *                  píxeles exactos dependen de ext-gd.
     * @param int|null $fontSize Alto objetivo del glifo en píxeles; null deriva
     *                           la escala entera desde el lienzo (~78 % del
     *                           alto de la imagen, tope ×4). Un valor explícito
     *                           gana, pero de ningún modo desborda la imagen
     *                           (la escala se recorta a los presupuestos
     *                           horizontal y vertical: el código siempre
     *                           cabe en el lienzo).
     * @param bool $noise Si se dibuja ruido aleatorio sobre la imagen.
     * @param bool $distortion Si la imagen se distorsiona con ondas.
     * @param string $output Formato de salida; por ahora solo "png".
     * @param string $idField Nombre del campo oculto que transporta el id del reto.
     * @param string $inputField Nombre del campo de texto donde se teclea el código.
     * @param bool $injectAssets Si widget() inyecta inline el CSS/JS empaquetados.
     * @param list<Operation> $operations Operaciones aritméticas habilitadas
     *                                    (vacío = dígitos clásicos). La imagen
     *                                    muestra "a op b" y el código es el
     *                                    resultado numérico; solo se eligen
     *                                    las operaciones habilitadas.
     * @param array{int, int}|null $between Rango inclusivo de resultados
     *                                      [min, max] en modo aritmético: toda
     *                                      respuesta cae dentro. Requiere
     *                                      operations (si no, InvalidConfig).
     *                                      Ignorado en modo dígitos (rechazado
     *                                      en fromArray/constructor).
     * @param int $verifyAttempts Rate limit: máx. llamadas a verify() por
     *                            clave dentro de $rateLimitWindow. Default 5;
     *                            0 desactiva el límite (no recomendado).
     * @param int $generateAttempts Rate limit: máx. llamadas a generate() por
     *                              clave dentro de $rateLimitWindow (DoS del
     *                              endpoint). Default 20; 0 desactiva.
     * @param int $rateLimitWindow Anchura de la ventana de rate limit, en segundos.
     * @param bool $honeypot Si el widget renderiza un campo trampa oculto que
     *                       los bots rellenan y los humanos ignoran; un valor
     *                       no vacío rechaza el envío antes de verificar.
     * @param string $honeypotField Nombre del campo trampa (debe ser distinto
     *                              de los campos reales del captcha).
     * @param bool $rateLimitByIp Si la clave del rate limit deriva de la IP del
     *                            cliente (REMOTE_ADDR, más X-Forwarded-For solo
     *                            detrás de trustedProxies). Junto con el
     *                            limiter de sesión forma el limiter dual por
     *                            defecto; false conserva el comportamiento
     *                            legado de solo sesión.
     * @param string $storage Backend de almacenamiento del reto: "auto"
     *                        (default) elige FileStorage bajo un framework que
     *                        gestiona la sesión PHP (CodeIgniter, Laravel,
     *                        Symfony...) y SessionStorage en el resto de los
     *                        casos, de modo que el default jamás preempta la
     *                        sesión del host; "session", "file" y "array"
     *                        fuerzan un backend concreto.
     * @param list<string> $trustedProxies IPs exactas de proxy (sin CIDR)
     *                                     autorizadas a hablar por un cliente
     *                                     mediante la cabecera
     *                                     X-Forwarded-For. Vacío por defecto:
     *                                     la cabecera se ignora.
     *
     * @throws InvalidConfigException Cuando alguna opción está fuera de rango.
     * @throws \TypeError Cuando un argumento no cumple su tipo nativo: el
     *                    constructor exige int/bool/string/Difficulty y no
     *                    convierte; la misma entrada por array la reporta
     *                    InvalidConfigException, que es quien estrecha ahí.
     */
    public function __construct(
        public int $length = self::DEFAULT_LENGTH,
        public int $width = self::DEFAULT_WIDTH,
        public int $height = self::DEFAULT_HEIGHT,
        public int $ttl = self::DEFAULT_TTL,
        public Difficulty $difficulty = Difficulty::Medium,
        public int $font = 5,
        public ?int $fontSize = null,
        public bool $noise = true,
        public bool $distortion = true,
        public string $output = 'png',
        public string $idField = 'captcha_id',
        public string $inputField = 'captcha',
        public bool $injectAssets = true,
        public array $operations = [],
        public ?array $between = null,
        public int $verifyAttempts = self::DEFAULT_VERIFY_ATTEMPTS,
        public int $generateAttempts = self::DEFAULT_GENERATE_ATTEMPTS,
        public int $rateLimitWindow = 300,
        public bool $honeypot = false,
        public string $honeypotField = 'email',
        public bool $rateLimitByIp = true,
        public string $storage = 'auto',
        public array $trustedProxies = [],
    ) {
        $this->validate();
    }

    /**
     * Construye una Config inmutable desde un array plano (fichero de
     * configuración, tests...).
     *
     * La clave opcional "preset" se expande primero ('default', 'login' o
     * 'strict'; ver PRESETS) y rellena solo las claves que el llamador omite,
     * de modo que cada opción explícita gana sobre la del preset.
     *
     * Las claves desconocidas se rechazan por defecto: el modo "strict"
     * (true) lanza una InvalidConfigException enumerando las claves
     * ofensivas, de modo que una errata jamás desactive en silencio un dial
     * de seguridad. Pasa false para conservar el comportamiento legado
     * tolerante (claves desconocidas ignoradas). Cada clave conocida se
     * comprueba por tipo y se reporta en español si falla; las opciones
     * numéricas aceptan también una cadena numérica ("6"); los booleanos
     * deben ser bool reales; difficulty acepta su enum o el nombre de sus
     * casos ("low", "medium", "high"); operations acepta cuatro formas
     * equivalentes, todas normalizadas a list<Operation> habilitada: una
     * lista de símbolos (["+", "-"]), una lista de nombres completos
     * (["addition", "subtraction"]), una lista de casos de Operation o el
     * mapa booleano asociativo por nombre ("addition" => true). Símbolos y
     * nombres desconocidos se rechazan; las claves del mapa no reconocidas se
     * ignoran; vacío significa dígitos clásicos.
     * "between" acepta una lista de dos enteros ([2, 20]) — se permiten
     * cadenas numéricas — y solo tiene sentido con operaciones aritméticas.
     * Los valores atraviesan después la validación de rangos del constructor.
     *
     * @param array<string, mixed> $options
     * @param bool $strict Rechazar claves desconocidas (default) o
     *                     ignorarlas de forma tolerante.
     *
     * @throws InvalidConfigException Cuando una clave es desconocida y el modo
     *                                estricto está activo, o una clave
     *                                conocida tiene un tipo erróneo o un valor
     *                                fuera del rango de Config.
     */
    public static function fromArray(array $options, bool $strict = true): self
    {
        /*
        *  El modo estricto revisa ANTES de expandir el preset: una clave no
        *  reconocida no debe quedar camuflada por las que el preset añade.
        */
        if ($strict) {
            self::assertKnownKeys($options);
        }

        return new self(...self::readOptions(self::applyPreset($options)));
    }

    /**
     * Reúne los argumentos del constructor leyendo cada familia de claves.
     *
     * Cada lector devuelve solo lo que venía presente y ya está coercionado
     * a su tipo, de modo que aquí no queda ningún if: las claves de un
     * lector y de otro son disjuntas y ninguna sobrescribe a otra.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function readOptions(array $options): array
    {
        return array_merge(
            self::readIntegers($options),
            self::readBooleans($options),
            self::readTexts($options),
            self::readTrustedProxies($options),
            self::readDifficulty($options),
            self::readOperations($options),
            self::readBetween($options),
        );
    }

    /**
     * Opciones enteras, agrupadas porque comparten conversión: se acepta un
     * entero o una cadena numérica ("6"), nunca un float ni un bool.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function readIntegers(array $options): array
    {
        $args = [];

        foreach (self::INTEGER_KEYS as $key) {
            if (array_key_exists($key, $options)) {
                $args[$key] = self::readInteger($options, $key);
            }
        }

        return $args;
    }

    /**
     * El tipo se estrecha en el lector para que toInteger() no tenga que
     * aceptar mixed; un null explícito no es un 0, así que pasa intacto para
     * que sea toInteger quien decida si esa clave lo admite (solo 'fontSize').
     *
     * @param array<string, mixed> $options
     */
    private static function readInteger(array $options, string $key): ?int
    {
        $value = $options[$key];

        if (!is_int($value) && !is_string($value) && $value !== null) {
            throw new InvalidConfigException(self::enteroInvalido($key));
        }

        return self::toInteger($key, $value);
    }

    private static function enteroInvalido(string $key): string
    {
        return sprintf("'%s' debe ser un entero.", $key);
    }

    /**
     * Los diales booleanos, tal cual vinieron.
     *
     * Un null explícito no es un false: se rechaza, porque un null
     * silenciosamente convertido en false desactivaría un dial de seguridad
     * (ruido, rate limit...) sin que nadie lo pidiera.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function readBooleans(array $options): array
    {
        $args = [];

        foreach (self::BOOLEAN_KEYS as $key) {
            if (array_key_exists($key, $options)) {
                $args[$key] = self::toBoolean($key, $options[$key]);
            }
        }

        return $args;
    }

    /**
     * @throws InvalidConfigException Cuando el valor no es booleano.
     */
    private static function toBoolean(string $key, mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidConfigException(sprintf("'%s' debe ser booleano.", $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function readTexts(array $options): array
    {
        $args = [];

        foreach (self::TEXT_KEYS as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }

            $args[$key] = self::toText($key, $options[$key]);
        }

        return $args;
    }

    /**
     * Convierte una clave textual del array de opciones; solo se acepta
     * string, porque estos valores acaban en atributos HTML o en nombres de
     * transporte.
     *
     * @throws InvalidConfigException
     */
    private static function toText(string $key, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new InvalidConfigException(sprintf("'%s' debe ser texto.", $key));
    }

    /**
     * 'fontSize' es la única opción entera nullable: null = glifo automático,
     * y toArray() lo exporta así (round-trip simétrico). Para el resto, un
     * null explícito es un 0 ausente y debe fallar.
     *
     * @param int|string|null $value ya estrechado por el lector; null solo es
     *                               admisible en 'fontSize'.
     *
     * @throws InvalidConfigException
     */
    private static function toInteger(string $key, int|string|null $value): ?int
    {
        if ($key === 'fontSize' && $value === null) {
            return null;
        }

        if ($value === null) {
            throw new InvalidConfigException(self::enteroInvalido($key));
        }

        return self::toInt($key, $value);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function readTrustedProxies(array $options): array
    {
        if (!array_key_exists('trustedProxies', $options)) {
            return [];
        }

        $value = $options['trustedProxies'];

        if (!is_array($value)) {
            throw new InvalidConfigException("'trustedProxies' debe ser un array de IPs.");
        }

        return ['trustedProxies' => self::toTrustedProxies(array_values($value))];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function readDifficulty(array $options): array
    {
        if (!array_key_exists('difficulty', $options)) {
            return [];
        }

        $value = $options['difficulty'];

        if (!$value instanceof Difficulty && !is_string($value)) {
            throw new InvalidConfigException("'difficulty' debe ser un texto o un valor Difficulty.");
        }

        return ['difficulty' => self::toDifficulty($value)];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function readOperations(array $options): array
    {
        if (!array_key_exists('operations', $options)) {
            return [];
        }

        $value = $options['operations'];

        if (!is_array($value)) {
            throw new InvalidConfigException("'operations' debe ser un array.");
        }

        return ['operations' => self::toOperations($value)];
    }

    /**
     * Lee 'between'. null = sin rango acotado, y null tampoco puede faltar en
     * el round-trip simétrico con toArray(), por eso se devuelve presente.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function readBetween(array $options): array
    {
        if (!array_key_exists('between', $options)) {
            return [];
        }

        $value = $options['between'];

        if ($value === null) {
            return ['between' => null];
        }

        return ['between' => self::toBetween(self::debeSerRango($value))];
    }

    /**
     * @throws InvalidConfigException Cuando no es un array.
     *
     * @return array<mixed>
     */
    private static function debeSerRango(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidConfigException("'between' debe ser un array de dos enteros, p. ej. [2, 20].");
        }

        return $value;
    }

    /**
     * La configuración efectiva como array plano de opciones — el reverso
     * exacto de fromArray() (un round trip preserva la Config).
     *
     * El atajo "preset" nunca se exporta: se consume al parsear y solo
     * quedan los valores efectivos. Las operations se exportan como los
     * nombres de operación habilitada y difficulty como su valor de cadena,
     * ambos normalizados de vuelta por fromArray(). Útil para reflejar la
     * configuración efectiva (doctor, contenedores de framework, caché) y
     * como base de merge().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->imageOptions(),
            ...$this->formOptions(),
            ...$this->challengeOptions(),
            ...$this->securityOptions(),
            ...$this->storageOptions(),
        ];
    }

    /**
     * Cómo se dibuja el reto: dimensiones, tipografía y desorden visual.
     *
     * @return array<string, mixed>
     */
    private function imageOptions(): array
    {
        return [
            'length' => $this->length,
            'width' => $this->width,
            'height' => $this->height,
            'ttl' => $this->ttl,
            'output' => $this->output,
            'difficulty' => $this->difficulty->value,
            'font' => $this->font,
            'fontSize' => $this->fontSize,
            'noise' => $this->noise,
            'distortion' => $this->distortion,
        ];
    }

    /**
     * Cómo viaja el reto en el formulario: nombres de campo y assets.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'idField' => $this->idField,
            'inputField' => $this->inputField,
            'injectAssets' => $this->injectAssets,
        ];
    }

    /**
     * Qué se pregunta al usuario: operaciones aritméticas y su rango.
     *
     * @return array<string, mixed>
     */
    private function challengeOptions(): array
    {
        return [
            'operations' => array_map(
                static fn(Operation $operation): string => $operation->value,
                $this->operations,
            ),
            'between' => $this->between,
        ];
    }

    /**
     * Los diales anti-spam: límites, honeypot y clave del cubo.
     *
     * @return array<string, mixed>
     */
    private function securityOptions(): array
    {
        return [
            'verifyAttempts' => $this->verifyAttempts,
            'generateAttempts' => $this->generateAttempts,
            'rateLimitWindow' => $this->rateLimitWindow,
            'rateLimitByIp' => $this->rateLimitByIp,
            'honeypot' => $this->honeypot,
            'honeypotField' => $this->honeypotField,
        ];
    }

    /**
     * Dónde viven los retos y a quién se le cree la IP.
     *
     * @return array<string, mixed>
     */
    private function storageOptions(): array
    {
        return [
            'storage' => $this->storage,
            'trustedProxies' => $this->trustedProxies,
        ];
    }

    /**
     * Sobrescritura inmutable: devuelve una nueva Config aplicando
     * $overrides sobre esta instancia, revalidada en conjunto.
     *
     * $overrides acepta tanto un array plano de opciones (la misma forma de
     * fromArray(), atajo "preset" incluido) como otra Config. El resultado se
     * construye apoyando las claves del override sobre las opciones efectivas
     * de la base y pasando el todo por fromArray(): una clave explícita (de
     * la base o del override) siempre vence al preset, y un "preset" en el
     * override solo rellena los huecos que la base no cubriese ya. Como la
     * base es la configuración efectiva (todas las opciones presentes), la
     * composición por preset pertenece a la base (forLogin()/forStrict());
     * merge está pensado para parches en runtime como los overrides de
     * entorno. La instancia nunca se muta.
     *
     * @param array<string, mixed>|self $overrides
     *
     * @throws InvalidConfigException Cuando el resultado combinado es inválido.
     */
    public function merge(array|self $overrides): self
    {
        $base = $this->toArray();
        $override = $overrides instanceof self ? $overrides->toArray() : $overrides;

        return self::fromArray([...$base, ...$override]);
    }

    // ── ATAJOS Y FACTORÍAS ───────────────────────────────────────────────────

    /**
     * Configuración por defecto (la misma que un new Config()).
     */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Paquete de preset afinado para formularios de autenticación (5 dígitos,
     * imagen grande y limpia, rate limits activos); equivalente a
     * ['preset' => 'login'].
     */
    public static function forLogin(): self
    {
        return self::fromArray(['preset' => 'login']);
    }

    /**
     * Paquete de preset con todos los diales anti-spam al máximo; equivalente
     * a ['preset' => 'strict'].
     */
    public static function forStrict(): self
    {
        return self::fromArray(['preset' => 'strict']);
    }

    /**
     * Un builder fluido y tipado para un cableado de servicios legible — ver
     * ConfigBuilder.
     */
    public static function builder(): ConfigBuilder
    {
        return new ConfigBuilder();
    }

    // ── PARSEO INTERNO ───────────────────────────────────────────────────────

    /**
     * Rechaza las claves de opción de primer nivel desconocidas cuando el
     * modo estricto está activo.
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidConfigException Cuando una clave no pertenece a KEYS.
     */
    private static function assertKnownKeys(array $options): void
    {
        $unknown = array_values(array_diff(array_keys($options), self::KEYS));

        if ($unknown !== []) {
            throw new InvalidConfigException(sprintf(
                'Claves de configuración no reconocidas: "%s".',
                implode('", "', $unknown),
            ));
        }
    }

    /**
     * Expande el atajo 'preset' antes del parseo regular: el preset aporta
     * las claves ausentes y cada opción explícita conserva la precedencia.
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidConfigException Cuando el preset no es una cadena o no
     *                                es un nombre de preset conocido.
     *
     * @return array<string, mixed>
     */
    private static function applyPreset(array $options): array
    {
        if (!array_key_exists('preset', $options)) {
            return $options;
        }

        $preset = self::presetValido($options['preset']);

        unset($options['preset']);

        /* Unión con precedencia a la izquierda: las claves explícitas ganan. */
        return $options + self::PRESETS[$preset];
    }

    /**
     * @throws InvalidConfigException Cuando no es una cadena o no es un nombre
     *                                de preset conocido.
     */
    private static function presetValido(mixed $preset): string
    {
        if (!is_string($preset)) {
            throw new InvalidConfigException("'preset' debe ser texto.");
        }

        if (!isset(self::PRESETS[$preset])) {
            throw new InvalidConfigException(self::presetDesconocido($preset));
        }

        return $preset;
    }

    private static function presetDesconocido(string $preset): string
    {
        return sprintf('Preset "%s" no reconocido; usa "%s".', $preset, implode('", "', array_keys(self::PRESETS)));
    }

    /**
     * Valida la lista de proxies de confianza: solo IPs exactas (sin rangos
     * CIDR), de modo que una errata no pueda ampliar en silencio el perímetro
     * de confianza.
     *
     * @param list<mixed> $value
     *
     * @return list<string>
     */
    private static function toTrustedProxies(array $value): array
    {
        $proxies = [];

        foreach ($value as $proxy) {
            if (!is_string($proxy) || filter_var($proxy, FILTER_VALIDATE_IP) === false) {
                throw new InvalidConfigException("'trustedProxies' debe contener IPs válidas.");
            }
            if (!in_array($proxy, $proxies, true)) {
                $proxies[] = $proxy;
            }
        }

        return $proxies;
    }

    /**
     * Parsea el rango de resultados "between" a enteros.
     *
     * La forma es exactamente una lista de dos elementos [min, max]; se
     * aceptan cadenas numéricas por simetría con las opciones enteras. El
     * orden de los límites y la exigencia del modo aritmético las aplica
     * validate().
     *
     * El tipo se estrecha aquí y no dentro de toBound() para que aquel no tenga
     * que aceptar mixed: el elemento puede ser cualquier cosa (el array viene
     * de un fichero de configuración) y la forma se valida en la frontera.
     *
     * @param array<mixed> $value
     *
     * @throws InvalidConfigException Cuando no es una lista [min, max].
     *
     * @return array{int, int}
     */
    private static function toBetween(array $value): array
    {
        if (!array_is_list($value) || count($value) !== 2) {
            throw new InvalidConfigException("'between' debe ser un array de dos enteros, p. ej. [2, 20].");
        }

        $min = $value[0];
        $max = $value[1];

        if (!is_int($min) && !is_string($min) || !is_int($max) && !is_string($max)) {
            throw new InvalidConfigException("'between' debe contener enteros, p. ej. [2, 20].");
        }

        return [self::toBound($min), self::toBound($max)];
    }

    /**
     * Normaliza un límite de 'between'; acepta enteros y cadenas numéricas.
     *
     * @param int|string $bound
     *
     * @throws InvalidConfigException Cuando el límite no es un entero.
     */
    private static function toBound(int|string $bound): int
    {
        if (is_int($bound)) {
            return $bound;
        }

        if (ctype_digit($bound)) {
            return (int) $bound;
        }

        throw new InvalidConfigException("'between' debe contener enteros, p. ej. [2, 20].");
    }

    /**
     * @param int|string $value
     */
    private static function toInt(string $key, int|string $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!ctype_digit($value)) {
            throw new InvalidConfigException(sprintf("'%s' debe ser un entero.", $key));
        }

        return (int) $value;
    }

    /**
     * @param string|Difficulty $value
     */
    private static function toDifficulty(string|Difficulty $value): Difficulty
    {
        if ($value instanceof Difficulty) {
            return $value;
        }

        return Difficulty::tryFrom($value) ?? throw new InvalidConfigException(sprintf(
            "'difficulty' no es válido; use \"%s\".",
            implode('", "', array_column(Difficulty::cases(), 'value')),
        ));
    }

    /**
     * Nombres canónicos y abreviaturas. El enum Operation serializa con su
     * propio value ('add', 'subtract'), así que esas grafías deben sobrevivir
     * al round-trip de toArray().
     *
     * @var array<string, Operation>
     */
    private const OPERATION_KEYS = [
        'addition' => Operation::Add,
        'subtraction' => Operation::Subtract,
        'multiplication' => Operation::Multiply,
        'division' => Operation::Divide,
        'add' => Operation::Add,
        'subtract' => Operation::Subtract,
        'multiply' => Operation::Multiply,
        'divide' => Operation::Divide,
    ];

    /**
     * Alias que solo tienen sentido en la forma lista: símbolos ASCII y
     * unicode, más abreviaturas que no son grafía del enum. Los símbolos
     * unicode son solo alias de entrada; la fuente bitmap renderiza ASCII.
     *
     * @var array<string, Operation>
     */
    private const OPERATION_ALIASES = [
        '+' => Operation::Add,
        '-' => Operation::Subtract,
        '*' => Operation::Multiply,
        '/' => Operation::Divide,
        '×' => Operation::Multiply,
        '÷' => Operation::Divide,
        'sub' => Operation::Subtract,
        'sum' => Operation::Add,
        'subs' => Operation::Subtract,
    ];

    /**
     * Parsea la configuración de operaciones habilitadas a list<Operation>.
     *
     * Se aceptan cuatro formas equivalentes, normalizadas a la misma
     * representación, para que quien integre elija la que mejor le lea:
     *
     * 1. Símbolos: ["+", "-"] (también "×" y "÷" como alias de entrada).
     * 2. Nombres abreviados o completos: ["add", "sub"], ["addition", "subtraction"].
     * 3. Instancias del enum Operation (simetría con difficulty).
     * 4. El mapa booleano asociativo: ["addition" => true, "division" => false];
     *    true habilita, false deshabilita, las claves desconocidas se ignoran.
     *
     * En las formas de lista cada entrada debe ser reconocida — una errata
     * ("adittion", "triangulo") debe fallar de forma ruidosa, no caer en
     * silencio a dígitos. Un array vacío siempre significa dígitos clásicos.
     *
     * @param array<mixed> $value
     *
     * @throws InvalidConfigException Cuando una entrada de lista es desconocida
     *                                o una clave del mapa asociativo no es bool.
     *
     * @return list<Operation>
     */
    private static function toOperations(array $value): array
    {
        if (array_is_list($value)) {
            return self::toOperationList($value);
        }

        return self::mapearOperaciones($value);
    }

    /**
     * Forma mapa: solo se miran las claves conocidas y su bandera se coerciona
     * con la misma regla que el resto de booleanos, de modo que "yes" falla.
     *
     * @param array<array-key, mixed> $value
     *
     * @return list<Operation>
     */
    private static function mapearOperaciones(array $value): array
    {
        $enabled = [];

        foreach ($value as $key => $flag) {
            if (!self::esOperacionConNombre($key) || !self::toBoolean($key, $flag)) {
                continue;
            }

            $enabled = self::agregaOperacion($enabled, $key);
        }

        return $enabled;
    }

    private static function esOperacionConNombre(mixed $key): bool
    {
        return is_string($key) && isset(self::OPERATION_KEYS[$key]);
    }

    /**
     * @param list<Operation> $enabled
     *
     * @return list<Operation>
     */
    private static function agregaOperacion(array $enabled, string $key): array
    {
        $op = self::OPERATION_KEYS[$key];

        if (!in_array($op, $enabled, true)) {
            $enabled[] = $op;
        }

        return $enabled;
    }

    /**
     * @param array<mixed> $value
     *
     * @return list<Operation>
     */
    private static function toOperationList(array $value): array
    {
        $enabled = [];

        foreach ($value as $entry) {
            $operation = self::debeSerOperacion($entry);

            if (!in_array($operation, $enabled, true)) {
                $enabled[] = $operation;
            }
        }

        return $enabled;
    }

    /**
     * @throws InvalidConfigException Cuando la entrada no nombra una operación.
     */
    private static function debeSerOperacion(mixed $entry): Operation
    {
        if ($entry instanceof Operation) {
            return $entry;
        }

        if (is_string($entry) && self::buscaOperacion($entry) !== null) {
            return self::buscaOperacion($entry);
        }

        throw new InvalidConfigException(self::operacionDesconocida($entry));
    }

    private static function buscaOperacion(string $entry): ?Operation
    {
        return self::OPERATION_ALIASES[$entry] ?? self::OPERATION_KEYS[$entry] ?? null;
    }

    private static function operacionDesconocida(mixed $entry): string
    {
        return sprintf(
            'Operación no reconocida: "%s". Use símbolos ("+", "-", "*", "/") o nombres ("addition", "subtraction", "multiplication", "division").',
            is_scalar($entry) ? (string) $entry : get_debug_type($entry),
        );
    }

    /**
     * Valida los rangos y las coherencias entre opciones.
     *
     * Son guardas independientes, así que viven agrupadas por lo que
     * describen en vez de en un solo cuerpo: cada grupo es un apartado del
     * contrato ("la imagen", "los límites de seguridad"...) y un mensaje roto
     * se localiza leyendo el grupo, no barriendo 130 líneas.
     *
     * @throws InvalidConfigException
     */
    private function validate(): void
    {
        $this->validateImage();
        $this->validateChallenge();
        $this->validateOutputAndStorage();
        $this->validateFormFields();
        $this->validateLimits();
        $this->validateHoneypot();
        $this->validateOperations();
        $this->validateTrustedProxies();
    }

    /**
     * @throws InvalidConfigException
     */
    private function validateImage(): void
    {
        foreach ($this->imageDimensions() as [$etiqueta, $valor, $min, $max]) {
            if ($valor < $min || $valor > $max) {
                throw new InvalidConfigException(sprintf(
                    '%s debe estar entre %d y %d; se recibió %d.',
                    $etiqueta,
                    $min,
                    $max,
                    $valor,
                ));
            }
        }
    }

    /**
     * Dimensiones a validar, en el orden en que seSetup informe el primer
     * fallo: longitud, ancho y luego alto.
     *
     * La etiqueta entra completa ("La longitud", "El ancho", "El alto") para
     * que el mensaje salga idéntico byte a byte sin mirar el género.
     *
     * @return list<array{string, int, int, int}>
     */
    private function imageDimensions(): array
    {
        return [
            ['La longitud', $this->length, self::MIN_LENGTH, self::MAX_LENGTH],
            ['El ancho', $this->width, 1, self::MAX_WIDTH],
            ['El alto', $this->height, 1, self::MAX_HEIGHT],
        ];
    }

    /**
     * @throws InvalidConfigException
     */
    private function validateChallenge(): void
    {
        $this->validateTtl();
        $this->validateFont();
        $this->validateFontSize();
    }

    private function validateTtl(): void
    {
        if ($this->ttl < 1) {
            throw new InvalidConfigException(sprintf('La TTL debe ser de al menos 1 segundo; se recibió %d.', $this->ttl));
        }
    }

    private function validateFont(): void
    {
        if ($this->font < 1 || $this->font > 5) {
            throw new InvalidConfigException(sprintf(
                "'font' debe estar entre 1 y 5 (fuente bitmap de GD); se recibió %d.",
                $this->font,
            ));
        }
    }

    private function validateFontSize(): void
    {
        if ($this->fontSize !== null && $this->fontSize < 1) {
            throw new InvalidConfigException(sprintf(
                "'fontSize' debe ser un entero positivo; se recibió %d.",
                $this->fontSize,
            ));
        }
    }
    /**
     * @throws InvalidConfigException
     */
    private function validateFormFields(): void
    {
        if ($this->idField === '' || $this->inputField === '') {
            throw new InvalidConfigException('Los nombres de los campos del formulario no pueden estar vacíos.');
        }
    }

    /**
     * Si la trampa comparte nombre con un campo real del captcha, el propio
     * widget la rellenaría con la respuesta legítima y la verificación
     * bloquearía a usuarios reales ("La petición parece automatizada."). La
     * plantilla lo advierte; aquí se hace cumplir.
     *
     * @throws InvalidConfigException
     */
    private function validateHoneypot(): void
    {
        if ($this->honeypot && $this->honeypotField === '') {
            throw new InvalidConfigException('El nombre del campo honeypot no puede estar vacío si está habilitado.');
        }

        if ($this->honeypot && ($this->honeypotField === $this->idField || $this->honeypotField === $this->inputField)) {
            throw new InvalidConfigException(sprintf(
                "El campo honeypot '%s' no puede coincidir con un campo real del captcha ('%s', '%s').",
                $this->honeypotField,
                $this->idField,
                $this->inputField,
            ));
        }
    }

    /**
     * @throws InvalidConfigException
     */
    private function validateOutputAndStorage(): void
    {
        if ($this->output !== 'png') {
            throw new InvalidConfigException(sprintf(
                'Formato de salida "%s" no soportado; solo se admite "png".',
                $this->output,
            ));
        }

        if (!in_array($this->storage, ['auto', 'session', 'file', 'array'], true)) {
            throw new InvalidConfigException(sprintf(
                "'storage' no es válido; use \"auto\", \"session\", \"file\" o \"array\".",
            ));
        }
    }

    /**
     * Los tres diales que cortan el trabajo de un atacante. Todos se niegan en
     * negativo o cero, porque un límite de 0 o un presupuesto negativo no es
     * "sin límite": es un número que no significa nada y que el limiter
     * interpretaría al revés.
     *
     * @throws InvalidConfigException
     */
    private function validateLimits(): void
    {
        if ($this->verifyAttempts < 0) {
            throw new InvalidConfigException(sprintf('El límite de intentos de verificación no puede ser negativo; se recibió %d.', $this->verifyAttempts));
        }

        if ($this->generateAttempts < 0) {
            throw new InvalidConfigException(sprintf('El límite de generación no puede ser negativo; se recibió %d.', $this->generateAttempts));
        }

        if ($this->rateLimitWindow < 1) {
            throw new InvalidConfigException(sprintf('La ventana del rate limit debe ser de al menos 1 segundo; se recibió %d.', $this->rateLimitWindow));
        }
    }

    /**
     * @throws InvalidConfigException
     */
    private function validateOperations(): void
    {
        foreach ($this->operations as $operation) {
            if (!$operation instanceof Operation) {
                throw new InvalidConfigException("'operations' debe contener solo valores Operation.");
            }
        }

        if ($this->between !== null) {
            $this->validateBetween($this->between);
        }
    }

    /**
     * @param list<int>|array<int, mixed> $between Rango ya presente: el
     *                                             llamador estrecha el null.
     *
     * @throws InvalidConfigException
     */
    private function validateBetween(array $between): void
    {
        [$min, $max] = $this->assertBetweenShape($between);
        $this->assertBetweenOrder($min, $max);

        if ($this->operations === []) {
            throw new InvalidConfigException("'between' solo tiene efecto en modo aritmético; defina 'operations'.");
        }
    }

    /**
     * @param array<array-key, mixed> $between Lo que el llamador ya estrechó.
     *
     * @throws InvalidConfigException Si no es una lista de exactamente dos enteros.
     *
     * @return array{int, int} Los dos límites, ya estrechos a entero.
     */
    private function assertBetweenShape(array $between): array
    {
        if (!array_is_list($between) || count($between) !== 2
            || !is_int($between[0]) || !is_int($between[1])) {
            throw new InvalidConfigException("'between' debe ser un array de dos enteros, p. ej. [2, 20].");
        }

        return [$between[0], $between[1]];
    }

    /**
     * @throws InvalidConfigException Si el mínimo es negativo o supera al máximo.
     */
    private function assertBetweenOrder(int $min, int $max): void
    {
        if ($min < 0) {
            throw new InvalidConfigException(sprintf("El mínimo de 'between' no puede ser negativo; se recibió %d.", $min));
        }
        if ($min > $max) {
            throw new InvalidConfigException(self::ordenEntreMinimo($min, $max));
        }
    }

    private static function ordenEntreMinimo(int $min, int $max): string
    {
        return sprintf("El límite mínimo de 'between' (%d) no puede ser mayor que el máximo (%d).", $min, $max);
    }

    /**
     * @throws InvalidConfigException
     */
    private function validateTrustedProxies(): void
    {
        foreach ($this->trustedProxies as $proxy) {
            if (!is_string($proxy) || filter_var($proxy, FILTER_VALIDATE_IP) === false) {
                throw new InvalidConfigException("'trustedProxies' debe contener IPs válidas.");
            }
        }
    }
}
