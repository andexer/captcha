<?php

declare(strict_types=1);

namespace Captcha;

use Captcha\Config\Config;
use Captcha\Contract\ExpressionProviderInterface;
use Captcha\Contract\GeneratorInterface;
use Captcha\Contract\RateLimiterInterface;
use Captcha\Contract\RendererInterface;
use Captcha\Contract\StorageInterface;
use Captcha\Exception\GdNotAvailableException;
use Captcha\Exception\InvalidConfigException;
use Captcha\Exception\RateLimitException;
use Captcha\Exception\StorageException;
use Captcha\Generator\NumericGenerator;
use Captcha\Http\Globals;
use Captcha\Renderer\GdRenderer;
use Captcha\Request\Check;
use Captcha\Request\RequestFlow;
use Captcha\Result\CaptchaResult;
use Captcha\Result\VerificationResult;
use Captcha\Runtime\Host;
use Captcha\Runtime\StaticLayer;
use Captcha\Runtime\StorageResolver;
use Captcha\Storage\RateLimiterFactory;
use Captcha\Verification\Status;
use Captcha\Widget\AssetBag;
use Captcha\Widget\Widget;
use Captcha\Widget\WidgetModel;
use Captcha\Widget\WidgetOptions;

/**
 * Fachada pública: genera y verifica captchas de imagen.
 *
 * Orquesta generador, renderizador y almacenamiento sin implementar ella
 * misma ninguna de esas responsabilidades. El flujo de dominio vive aquí;
 * el azúcar estático con ámbito de petición (singleton, descubrimiento de
 * configuración, configure()) se delega en Runtime\StaticLayer.
 *
 * Los métodos públicos de abajo NO son métodos reales: PHP prohíbe que un
 * método estático y otro de instancia compartan nombre, así que se enrutan
 * por __call/__callStatic hacia los implementadores privados *Instance (la
 * lista de nombres admitidos es Captcha::MAGIC_METHODS). Sin las etiquetas
 * de @method que cierran este bloque, toda esta API sería invisible para
 * el análisis estático y para los IDE: `Captcha::generate()` se reportaría
 * como método inexistente. Mantenlas en sincronía con MAGIC_METHODS.
 *
 * @method static Config config()
 * @method static StorageInterface storage()
 * @method static bool submitted()
 * @method static bool valid()
 * @method static string message()
 * @method static Check check()
 * @method static string widget(array<string, mixed> $options = [])
 * @method static string assets(string $mode = 'inline', string $baseUrl = '')
 * @method static VerificationResult verifyRequest(array<string, mixed> $data)
 * @method static CaptchaResult generate()
 * @method static VerificationResult verify(string $id, string $input)
 */
final class Captcha
{
    private const ID_BYTES = 16; // 128 bits.

    /*
    *  Cota del input verificable: la API verifica un código numérico corto y
    *  no debe alimentar hash_equals() ni el storage con megabytes de basura.
    *  El widget ya limita con maxlength; esta es la red a nivel de API.
    */
    private const MAX_INPUT_BYTES = 32;

    /**
     * Nombres servidos por el despacho dinámico en ambas grafías.
     *
     * Cada método público de la fachada está listado aquí: PHP prohíbe un
     * método estático y otro de instancia compartiendo nombre, así que cada
     * implementación vive en un método privado *Instance. Omitir uno aquí no
     * degrada de forma controlada — PHP rechaza `Captcha::eseNombre()` con un
     * Error fatal ("cannot be called statically"), que ningún bloque catch
     * puede manejar.
     */
    private const MAGIC_METHODS = [
        'widget',
        'submitted',
        'valid',
        'message',
        'check',
        'assets',
        'generate',
        'verify',
        'verifyRequest',
        'config',
        'storage',
    ];

    private readonly AssetBag $assets;

    private readonly GeneratorInterface $generator;

    /*
    *  Rate limiter creado de forma perezosa (ver rateLimiter()) solo cuando
    *  hay algún límite configurado, de modo que una instancia default jamás
    *  arranque una sesión ni toque el sistema de ficheros.
    */
    private ?RateLimiterInterface $rateLimiter;

    /*
    *  El flujo de verificación con ámbito de petición, construido como mucho
    *  una vez: valid(), message() y check() responden todos desde este único
    *  estado (valid() consume el reto; el resultado de uso único jamás debe
    *  recomputarse en las llamadas siguientes).
    */
    private ?RequestFlow $flow = null;

    /*
    *  widget() es idempotente dentro de una petición: dos llamadas sobre la
    *  misma instancia reutilizan el mismo reto en lugar de generar uno nuevo
    *  (partials/layouts que rendericen el formulario dos veces no deben
    *  invalidar el código ya emitido).
    */
    private ?CaptchaResult $widgetChallenge = null;

    /*
    *  Se fija solo cuando generate() fue limitado por tasa durante un render
    *  de widget(): el widget degrada a un estado de error (sin reto) en lugar
    *  de dejar que la excepción rompa la página / plantilla del host.
    */
    private ?string $widgetError = null;

    private readonly StorageInterface $storage;

    public function __construct(
        ?StorageInterface $storage = null,
        private readonly Config $config = new Config(),
        GeneratorInterface $generator = new NumericGenerator(),
        private readonly RendererInterface $renderer = new GdRenderer(),
        ?RateLimiterInterface $rateLimiter = null,
    ) {
        $this->storage = $storage ?? StorageResolver::resolve($config);
        $this->generator = self::ajustaGenerador($generator, $config);
        $this->rateLimiter = $rateLimiter;
        $this->assets = self::assetsEmpaquetados();
    }

    /**
     * Cuando Config pide aritmética y el generador sigue siendo el
     * NumericGenerator por defecto (sin operaciones explícitas), se reenvuelve
     * con el conjunto habilitado, la dificultad y el rango "between"
     * (resultados acotados). Un generador inyectado a mano se respeta tal cual.
     */
    private static function ajustaGenerador(GeneratorInterface $generator, Config $config): GeneratorInterface
    {
        $pideAritmetica = $generator instanceof NumericGenerator
            && !$generator->hasOperation()
            && $config->operations !== [];

        return $pideAritmetica
            ? new NumericGenerator($config->operations, $config->difficulty, $config->between)
            : $generator;
    }

    private static function assetsEmpaquetados(): AssetBag
    {
        return new AssetBag(
            __DIR__ . '/resources/assets/captcha.min.css',
            __DIR__ . '/resources/assets/captcha.min.js',
        );
    }

    // ── INTERNOS DE INSTANCIA: CONFIG, STORAGE Y RATE LIMIT ──────────────────

    /**
     * Configuración inmutable usada por esta instancia.
     */
    private function configInstance(): Config
    {
        return $this->config;
    }

    /**
     * Backend de almacenamiento de retos en uso por esta instancia
     * (introspección y pegamento de builder; p. ej. para podar ficheros tras
     * cambiar de motor de almacenamiento).
     */
    private function storageInstance(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * Limiter por defecto, creado solo cuando algún límite está activo: la
     * pila dual (ficheros IP + sesión) con Config::$rateLimitByIp, solo
     * sesión en caso contrario. Bajo un framework propietario de la sesión
     * PHP, el cubo de reintento descarta la mitad de sesión (solo ficheros
     * IP), respetando la misma regla de "jamás tocar la sesión del host" que
     * el backend de almacenamiento. Un limiter inyectado a mano siempre se
     * respeta tal cual.
     */
    private function rateLimiter(): RateLimiterInterface
    {
        return $this->rateLimiter ??= RateLimiterFactory::forConfig($this->config);
    }

    /**
     * Clave de rate limit: el ámbito de operación más la identidad del
     * cliente (la IP con el límite por IP activo, REMOTE_ADDR en los demás
     * casos — Globals::ip() se conserva como clave legada).
     *
     * Acotar por operación mantiene verifyAttempts y generateAttempts como
     * presupuestos independientes. Con una única clave compartida, ambas
     * operaciones sacarían del mismo cubo y la ventana más estricta
     * inanaría a la otra: p. ej. 5 cargas/recargas de página (generate)
     * bloquearían toda la ventana de verify() aunque los intentos de login
     * nunca ocurrieran.
     *
     * Fail-closed a propósito: sin identidad utilizable, el intento no puede
     * contarse, así que se rechaza — verify() responde Blocked sin tocar
     * storage y generate() lanza la excepción mapeada a 429. Un REMOTE_ADDR
     * ausente es exactamente el escenario de inundación para el que existe
     * el limiter, no una excusa para abrir la puerta.
     */
    private function limitKey(string $scope): string
    {
        return $scope . ':' . $this->clientKey();
    }

    /**
     * La identidad contra la que se mide un intento.
     *
     * Vacía solo cuando el límite por IP está activo y no hay dirección
     * disponible, que es justo lo que unlimitable() convierte en rechazo.
     */
    private function clientKey(): string
    {
        if (!$this->config->rateLimitByIp) {
            return Globals::ip();
        }

        return Globals::clientIp($this->config->trustedProxies);
    }

    /**
     * Si el intento actual debe rechazarse porque no puede contarse de forma
     * fiable (límite por IP activo, petición web y sin dirección de cliente
     * disponible a la que atribuirlo).
     *
     * Fuera del SAPI web el limiter por defecto es un NullRateLimiter, así
     * que una dirección de cliente ausente es la ausencia de cliente — lo
     * contrario de uno sin medir — y no hay nada que rechazar.
     */
    private function unlimitable(): bool
    {
        return $this->config->rateLimitByIp
            && Host::isWeb()
            && $this->clientKey() === '';
    }

    // ── CAPA ESTÁTICA Y DESPACHO DINÁMICO ────────────────────────────────────

    /**
     * La instancia con ámbito de petición detrás del azúcar estático.
     *
     * Creada de forma perezosa desde el primer fichero de configuración que
     * case (ver Runtime\StaticLayer), o con defaults cuando no existe
     * ninguno. PHP reinicia el estado estático en cada petición, así que el
     * singleton jamás se filtra entre peticiones; reset() lo descarta dentro
     * de una petición (sobre todo para tests de larga vida).
     *
     * @throws \Captcha\Exception\InvalidConfigException Cuando el fichero de
     *                                                   configuración descubierto no devuelve un array o contiene valores erróneos.
     */
    public static function instance(): self
    {
        return StaticLayer::instance();
    }

    /**
     * Configura la capa estática de forma programática — sin fichero de
     * configuración.
     *
     * Acepta tanto un array plano de opciones con exactamente las mismas
     * claves que el fichero de configuración (ver Config::fromArray(), con el
     * atajo "preset" y la validación estricta de claves desconocidas) como
     * una instancia Config ya construida, que se usa tal cual. Las opciones
     * sustituyen por completo al descubrimiento por fichero: un fichero
     * descubierto solo se lee cuando aquí no se configuró nada. Llamarlo de
     * nuevo sustituye las opciones; pasa un array vacío para volver al
     * descubrimiento (o a los defaults).
     *
     * Pensado para frameworks con un bootstrap estable (public/index.php, un
     * service provider...): una llamada configure() ahí y ya no se requiere
     * ni un fichero captcha.php ni la variable de entorno CAPTCHA_CONFIG.
     * Además suelta el singleton actual, de modo que la siguiente llamada
     * estática reconstruya la instancia con las opciones nuevas.
     *
     * @param array<string, mixed>|Config $options
     *
     * @throws InvalidConfigException En el momento de la llamada, cuando un
     *                                array no pasa la validación estricta.
     */
    public static function configure(Config|array $options = []): void
    {
        StaticLayer::configure($options);
    }

    /**
     * Suelta el singleton con ámbito de petición para que la siguiente
     * llamada estática lo reconstruya.
     *
     * Las opciones fijadas con configure() sobreviven: reset() descarta la
     * instancia, no la configuración elegida (llama configure([]) para
     * liberarla).
     */
    public static function reset(): void
    {
        StaticLayer::reset();
    }

    /**
     * Azúcar estático retrocompatible.
     *
     * PHP prohíbe un método estático y otro de instancia con el mismo nombre,
     * así que las formas estáticas no pueden existir como métodos reales;
     * se enrutan aquí hacia el singleton con ámbito de petición, llamando
     * directamente a la implementación privada *Instance (llamar ->{$name}()
     * reentraría en __call). `instance()`, `reset()` y `configPath()` son
     * métodos estáticos reales y jamás pasan por este manejador.
     *
     * @param array<int, mixed> $arguments
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if (!in_array($name, self::MAGIC_METHODS, true)) {
            throw new \BadMethodCallException(sprintf('El método estático Captcha::%s() no existe.', $name));
        }

        return self::instance()->{$name . 'Instance'}(...$arguments);
    }

    /**
     * Contraparte de instancia de __callStatic: mantiene los mismos nombres
     * de flujo llamables sobre una instancia construida a mano mientras las
     * implementaciones viven en los métodos privados *Instance, preservando
     * el storage y la Config propios de cada instancia. `$captcha->widget()`
     * se distingue de `Captcha::widget()` precisamente porque corre sobre
     * esta instancia, no sobre el singleton.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!in_array($name, self::MAGIC_METHODS, true)) {
            throw new \BadMethodCallException(sprintf('El método Captcha\Captcha::%s() no existe.', $name));
        }

        return $this->{$name . 'Instance'}(...$arguments);
    }

    /**
     * Ruta del fichero de configuración descubierto, o null cuando la capa
     * estática corre sin fichero (defaults) o con opciones configuradas a
     * mano.
     *
     * Refleja el orden de descubrimiento exactamente como lo ve la capa
     * estática, de modo que el diagnóstico (doctor) reporte lo que la capa
     * estática cargará de verdad.
     */
    public static function configPath(): ?string
    {
        return StaticLayer::configPath();
    }

    public function __toString(): string
    {
        return $this->widgetInstance();
    }

    // ── FLUJO DE VERIFICACIÓN DE LA PETICIÓN ─────────────────────────────────

    /**
     * Si la petición actual llegó vía POST, para que el flujo de verificación
     * del lado servidor pueda filtrarse sin tocar $_SERVER.
     */
    private function submittedInstance(): bool
    {
        return Globals::isPost();
    }

    /**
     * Si el código de captcha enviado es correcto.
     *
     * Lee los campos POST declarados por Config y consume el reto de forma
     * atómica (uso único), incluso en caso de fallo. El resultado se cachea
     * por el resto de la petición, así que un message() posterior devuelve el
     * mismo desenlace; un verify() de bajo nivel llamado después reportaría
     * Missing, dado que el reto ya se consumió.
     */
    private function validInstance(): bool
    {
        return $this->flow()->passed();
    }

    /**
     * Retroalimentación en español del resultado de la verificación: un
     * mensaje listo para mostrar ("Código captcha correcto.",
     * "...incorrecto.", "...caducado.", "...no encontrado."). Comparte el
     * resultado cacheado con valid().
     */
    private function messageInstance(): string
    {
        return $this->flow()->message;
    }

    /**
     * El flujo completo del captcha en una sola respuesta: submitted
     * (¿POST?), passed y la retroalimentación de error en español, empaquetados
     * en un value object Check.
     *
     * Las peticiones GET responden idle() sin tocar storage — preguntar no
     * genera ni consume reto alguno. En POST la verificación corre una vez y
     * el resultado queda cacheado por el resto de la petición, de modo que
     * llamadas check() repetidas (y un valid()/message() posterior) lo
     * reutilizan en lugar de reconsumir el reto. La cadena de error se
     * devuelve literal; escapar sigue siendo asunto de la vista.
     */
    private function checkInstance(): Check
    {
        /*
        *  Sin POST no hay envío que verificar: idle sin tocar storage ni
        *  construir el flow (un GET jamás genera ni consume un reto aquí).
        */
        return Globals::isPost() ? $this->flow()->toCheck() : new Check(false, false, null);
    }

    /**
     * El flujo con ámbito de petición, verificado como mucho una vez por
     * instancia.
     *
     * Semánticas legadas preservadas: valid()/message() verifican lo que haya
     * sido enviado (Globals::post(), vacío en GET) cada vez que se les
     * pregunta, de modo que su respuesta no depende del método de la
     * petición; check() filtra por el propio método POST antes de consultar
     * este flujo.
     */
    private function flow(): RequestFlow
    {
        if ($this->flow !== null) {
            return $this->flow;
        }

        $result = $this->verifyRequestInstance(Globals::post());

        return $this->flow = new RequestFlow($result->getStatus(), $result->getMessage());
    }

    // ── WIDGET Y ASSETS ──────────────────────────────────────────────────────

    /**
     * Renderiza el widget del captcha — contrato público de widget()/Captcha::widget().
     *
     * Idempotente por petición: la primera llamada genera y persiste el
     * reto; las siguientes renderizan el mismo aspecto, así que un formulario
     * renderizado desde un partial, layout o bucle jamás invalida un código
     * ya emitido.
     *
     * Salvo que Config::$injectAssets sea false, la primera llamada al widget
     * en una página también inyecta inline el CSS y el JavaScript
     * empaquetados, de modo que quien integra no necesita exponer ninguna
     * ruta de assets. Para CSP estricta, desactiva la inyección y enlaza los
     * assets por tu cuenta con assets().
     *
     * @param array<string, mixed> $options WidgetOptions como array
     *                                      asociativo; ver WidgetOptions
     *                                      para las claves.
     *
     * @throws GdNotAvailableException Cuando GD no puede renderizar la imagen.
     * @throws StorageException Cuando el código no puede persistirse.
     */
    private function widgetInstance(array $options = []): string
    {
        $this->preparaRetoDelWidget();

        $html = (new Widget(
            WidgetModel::fromConfig($this->config),
            WidgetOptions::fromArray($options),
            $this->widgetChallenge,
            $this->widgetError,
        ))->render();

        return $this->config->injectAssets ? $this->assets->once() . $html : $html;
    }

    /**
     * El rate limit de generación no debe tumbar el render de la página: sin
     * reto, el widget se muestra en estado de error y el usuario reintenta
     * cuando se abra la ventana. El endpoint de recarga conserva su HTTP 429.
     */
    private function preparaRetoDelWidget(): void
    {
        if ($this->widgetChallenge !== null || $this->widgetError !== null) {
            return;
        }

        try {
            $this->widgetChallenge = $this->generateInstance();
        } catch (RateLimitException $exception) {
            $this->widgetError = $exception->getMessage();
        }
    }

    /**
     * Assets para inclusión manual: etiquetas inline (default) o por URL.
     *
     * "inline" devuelve un <style>+<script> de un solo uso con los ficheros
     * empaquetados. "url" devuelve <link>/<script src> apuntando a $baseUrl,
     * desde donde quien integra sirve los dos ficheros (p. ej. dominio propio
     * o CDN) — útil bajo Content-Security-Policy estricta.
     *
     * Como los métodos de flujo, el nombre público es llamable como método
     * de instancia ($captcha->assets()) y como estático (Captcha::assets())
     * mediante el despacho dinámico; esta implementación permanece privada
     * para que PHP permita ambas grafías.
     *
     * @throws \InvalidArgumentException Con un modo no soportado o una URL
     *                                   base vacía para "url".
     */
    private function assetsInstance(string $mode = 'inline', string $baseUrl = ''): string
    {
        return match ($mode) {
            'inline' => $this->assets->inline(),
            'url' => $this->urlAssets($baseUrl),
            default => throw new \InvalidArgumentException(sprintf('Modo de assets "%s" no soportado.', $mode)),
        };
    }

    // ── GENERACIÓN Y VERIFICACIÓN ────────────────────────────────────────────

    /**
     * Verifica los campos del formulario enviados, extraídos de un array de
     * petición.
     *
     * Lee Config::$idField y Config::$inputField; los valores no cadena
     * producen un resultado Missing en lugar de un crash.
     *
     * @param array<string, mixed> $data Normalmente $_POST.
     */
    private function verifyRequestInstance(array $data): VerificationResult
    {
        $bloqueado = $this->honeypotSaltado($data);

        if ($bloqueado !== null) {
            return $bloqueado;
        }

        $campos = $this->camposDe($data);
        if ($campos === null) {
            return new VerificationResult('', Status::Missing, 'Código captcha no encontrado.');
        }

        return $this->verifyInstance($campos['id'], $campos['input']);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array{id: string, input: string}|null Null si el POST no trae
     *                                               ambos campos como texto.
     */
    private function camposDe(array $data): ?array
    {
        $id = $data[$this->config->idField] ?? '';
        $input = $data[$this->config->inputField] ?? '';

        if (!is_string($id) || !is_string($input)) {
            return null;
        }

        return ['id' => $id, 'input' => $input];
    }

    /**
     * Honeypot: un bot rellena campos ocultos que un humano jamás ve. Si la
     * trampa aparece con contenido, se rechaza sin verificar nada.
     *
     * @param array<array-key, mixed> $data
     */
    private function honeypotSaltado(array $data): ?VerificationResult
    {
        if (!$this->config->honeypot || ($data[$this->config->honeypotField] ?? '') === '') {
            return null;
        }

        return new VerificationResult('', Status::Blocked, 'La petición parece automatizada.');
    }

    /**
     * @return string Etiquetas <link> y <script> apuntando a la URL base.
     */
    private function urlAssets(string $baseUrl): string
    {
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('Se requiere una URL base para el modo de assets "url".');
        }

        $href = htmlspecialchars(rtrim($baseUrl, '/'), ENT_QUOTES);

        return sprintf('<link rel="stylesheet" href="%s/captcha.min.css">', $href)
            . "\n"
            . sprintf('<script src="%s/captcha.min.js"></script>', $href);
    }

    /**
     * Genera un nuevo reto: almacena el código y devuelve la imagen.
     *
     * El id devuelto debe guardarse del lado servidor (sesión, campo
     * oculto...) y pasarse de vuelta a verify() junto con la entrada del
     * usuario.
     *
     * El rate limit se aplica primero cuando Config::$generateAttempts es
     * distinto de cero: una clave bloqueada lanza RateLimitException para que
     * quien llame pueda responder HTTP 429 en vez de renderizar más imágenes.
     * Con el límite por IP activo, una dirección de cliente inutilizable se
     * rechaza igual (fail-closed: el intento no puede contarse, así que no
     * debe pasar).
     *
     * @throws GdNotAvailableException Cuando GD no puede renderizar la imagen.
     * @throws StorageException Cuando el código no puede persistirse.
     * @throws RateLimitException Cuando se excede la ventana de generación o
     *                            la dirección del cliente es inutilizable.
     */
    /**
     * En modo aritmético la imagen muestra "a op b" pero el código verificable
     * sigue siendo el answer numérico. Se renderiza antes de persistir para no
     * dejar entradas huérfanas en el storage si el rendering (GD) falla.
     */
    private function generateInstance(): CaptchaResult
    {
        $this->enforceGenerateLimit();

        $code = $this->generator->generate($this->config->length);
        $image = $this->renderer->render($this->expressionText($code), $this->config);
        $id = $this->persiste($code);

        return new CaptchaResult($id, $image, $this->renderer->mimeType());
    }

    private function persiste(string $code): string
    {
        $id = bin2hex(random_bytes(self::ID_BYTES));
        $this->storage->put($id, $code, $this->config->ttl);

        return $id;
    }

    /**
     * Aplica el throttle de generación cuando está activado.
     *
     * Throttle también del endpoint: el renderizado con GD es intensivo en
     * CPU, así que a una IP no se le debe permitir inundar generate() a
     * ciegas.
     *
     * @throws RateLimitException Cuando se ha superado el límite.
     */
    private function enforceGenerateLimit(): void
    {
        if ($this->config->generateAttempts <= 0) {
            return;
        }

        if ($this->unlimitable()) {
            throw new RateLimitException('Demasiados captchas generados, espera unos segundos.');
        }

        if (!$this->rateLimiter()->allow($this->limitKey('generate'), $this->config->generateAttempts, $this->config->rateLimitWindow)) {
            throw new RateLimitException('Demasiados captchas generados, espera unos segundos.');
        }
    }

    /**
     * El texto a dibujar: la expresión en modo aritmético y el código mismo
     * en modo clásico.
     */
    private function expressionText(string $code): string
    {
        if ($this->generator instanceof ExpressionProviderInterface && $this->generator->expression() !== '') {
            return $this->generator->expression();
        }

        return $code;
    }

    /**
     * Verifica la entrada del usuario contra un reto almacenado.
     *
     * Uso único: la entrada se consume de forma atómica incluso cuando la
     * entrada es errónea, de modo que un reto jamás pueda rejugarse bajo
     * concurrencia. La comparación es timing-safe.
     *
     * El rate limit se aplica antes de la búsqueda cuando
     * Config::$verifyAttempts es distinto de cero: una clave bloqueada no
     * consume el reto. Con el límite por IP activo, una dirección de cliente
     * inutilizable se bloquea igual (fail-closed: el intento no puede
     * contarse, así que no debe pasar).
     *
     * Un id vacío se decide SIN el rate limiter: es el caso "sin envío" (un
     * render GET, un formulario sin el campo oculto), no un intento de
     * captcha. Cobrarle verifyAttempts dejaría que las cargas de página
     * quemen el presupuesto y bloquee POST legítimos. Reordenarlo mantiene
     * además result()/message() sin efectos secundarios en GET: sin arranque
     * de sesión ni fichero de contador solo por preguntar.
     */
    private function verifyInstance(string $id, string $input): VerificationResult
    {
        $rechazo = $this->verifyGuard($id, $input);

        if ($rechazo !== null) {
            return $rechazo;
        }

        return $this->consumeAndCompare($id, $input);
    }

    /**
     * Los rechazos que se deciden sin tocar el reto; null cuando toca
     * consumir y comparar.
     *
     * Sin reto no hay intento que medir: el id vacío se decide antes que el
     * limiter para que un GET jamás consuma verifyAttempts ni toque
     * sesión/disco. Un input desproporcionado no es una respuesta genuina (un
     * código numérico ocupa pocos bytes): se rechaza sin consumir el reto y
     * sin tocar storage, aunque el intento sí contó para el rate limit; el
     * widget limita con maxlength, así que un humano nunca llega aquí.
     */
    private function verifyGuard(string $id, string $input): ?VerificationResult
    {
        if ($id === '') {
            return new VerificationResult('', Status::Missing, 'Código captcha no encontrado.');
        }

        return $this->isVerifyRateLimited() ?? $this->inputDesbordado($id, $input);
    }

    private function inputDesbordado(string $id, string $input): ?VerificationResult
    {
        if (strlen($input) <= self::MAX_INPUT_BYTES) {
            return null;
        }

        return new VerificationResult($id, Status::Invalid, 'Código captcha incorrecto.');
    }

    /**
     * Consulta el límite de intentos de verificación, si está configurado.
     * Un id con la sesión ya iniciada puede saltárselo; el resto no.
     */
    private function isVerifyRateLimited(): ?VerificationResult
    {
        if ($this->config->verifyAttempts <= 0) {
            return null;
        }

        return $this->unlimitable() ? $this->verifyBloqueado() : $this->verificaLimite();
    }

    private function verificaLimite(): ?VerificationResult
    {
        $permitido = $this->rateLimiter()->allow(
            $this->limitKey('verify'),
            $this->config->verifyAttempts,
            $this->config->rateLimitWindow,
        );

        return $permitido ? null : $this->verifyBloqueado();
    }

    private function verifyBloqueado(): VerificationResult
    {
        return new VerificationResult(
            '',
            Status::Blocked,
            'Demasiados intentos, espera unos segundos y vuelve a intentarlo.',
        );
    }

    /**
     * Consume el reto (uso único) y compara en tiempo constante.
     */
    private function consumeAndCompare(string $id, string $input): VerificationResult
    {
        $expected = $this->consumeExpected($id);

        if ($expected instanceof VerificationResult) {
            return $expected;
        }

        if (!hash_equals($expected, trim($input))) {
            return new VerificationResult($id, Status::Invalid, 'Código captcha incorrecto.');
        }

        return new VerificationResult($id, Status::Ok, 'Código captcha correcto.');
    }

    /**
     * El código esperado tras consumir el reto, o el motivo por el que no hay.
     */
    private function consumeExpected(string $id): string|VerificationResult
    {
        if (!$this->storage->has($id)) {
            return new VerificationResult($id, Status::Missing, 'Código captcha no encontrado.');
        }

        return $this->storage->consume($id)
            ?? new VerificationResult($id, Status::Expired, 'El código captcha ha caducado.');
    }
}
