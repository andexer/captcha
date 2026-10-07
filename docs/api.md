# API

> Forma parte de la documentación de [captcha](../README.md). Volver al [inicio](../README.md).

## Cómo cargar el paquete en tu aplicación

### Instancia manual (inyección de dependencias)

```php
$captcha = new \Captcha\Captcha(
    storage:  new \Captcha\Storage\FileStorage('/ruta/escribible'),
    config:   \Captcha\Config\Config::fromArray(['length' => 5, 'verifyAttempts' => 5]),
    generator: new \Captcha\Generator\NumericGenerator(
        [\Captcha\Config\Operation::Add],
        \Captcha\Config\Difficulty::Medium,
        [2, 20],
    ),
    // renderer y $rateLimiter inyectables a mano; aquÍ van los defaults.
);
```

Firma del constructor:

```php
new Captcha(
    ?StorageInterface     $storage = null,   // null = resolver desde Config::storage
    Config                $config  = new Config(),
    GeneratorInterface    $generator = new NumericGenerator(),
    RendererInterface     $renderer = new GdRenderer(),
    ?RateLimiterInterface $rateLimiter = null,
)
```

Si no inyectas `$storage`, se resuelve desde `Config::storage` con la misma regla de la capa estática (`'auto'` mira si hay un framework con la sesión PHP a cargo). Un storage explícito manda siempre sobre la opción.

Nota interesante: si el config pide aritmética y el generador sigue siendo el `NumericGenerator` por defecto, se **reenvuelve automáticamente** con tus `operations`, `difficulty` y `between`. Un generador inyectado a mano se respeta tal cual.

La fachada es **estado puro** (la única capa que «recuerda» entre llamadas es la estática); cada dependencia entra por el constructor.

### API pública

| Método | Qué hace |
|---|---|
| `generate()` | Genera un reto: `CaptchaResult` `{id, image (PNG binario), mimeType}`. Nunca expone el código. |
| `verify(string $id, string $input)` | Verifica el código de un reto; consume el reto (uso único) y aplica rate limit si `verifyAttempts > 0`. Un `$input` de más de 32 bytes se rechaza sin consumir. Devuelve `VerificationResult`. |
| `verifyRequest(array $data)` | Igual que `verify()` pero lee los campos declarados por el config (`idField`/`inputField`) de un array (típicamente `$_POST`); honra el honeypot. |
| `check()` | Todo el flujo en un `Check`: `submitted`, `passed`, `error` (feedback en español, sin escapar — la vista aplica `htmlspecialchars()`). En GET responde el estado *idle* sin tocar el almacén. Caché por petición. |
| `valid()` / `message()` | Versión simple: `bool` y mensaje en español. Comparten la caché de `check()`. |
| `submitted()` | `true` si la petición es POST. |
| `widget(array $options = [])` | HTML del widget (imagen, id oculto, input, recarga, honeypot). Idempotente por petición; inyecta CSS/JS la primera vez. `__toString()` = widget(). |
| `config()` | El `Config` inmutable de la instancia. |
| `assets(string $mode = 'inline', string $baseUrl = '')` | Etiquetas del CSS/JS: `'inline'` (única vez) o `'url'` (`<link>`/`<script src>` contra `$baseUrl`) para CSP estricta. |

Todos los métodos públicos de la tabla (`generate`, `verify`, `verifyRequest`, `check`, `valid`, `message`, `submitted`, `widget`, `config`, `storage` y `assets`) existen a la vez como **estáticos** (`Captcha::widget()`) y como **métodos de instancia** (`$captcha->widget()`). PHP prohíbe un método estático y otro de instancia con el mismo nombre, así que el paquete los expone vía `__call`/`__callStatic` hacia implementaciones privadas que preservan el estado de cada instancia. Un nombre no reconocido lanza `BadMethodCallException` en español, nunca un `Error` fatal. `instance()`/`reset()`/`configure()` son estáticos reales.

`CaptchaResult` también te da `getDataUri()` (para el `<img>`), `getHtml()` (la etiqueta lista) y `saveTo(string $path)` (guardar el PNG en un archivo).

### Resultados de verificación (`Status`)

| Estado | Significado | Mensaje |
|---|---|---|
| `ok` | Verificado | `Código captcha correcto.` |
| `invalid` | Código equivocado | `Código captcha incorrecto.` |
| `expired` | TTL vencido | `El código captcha ha caducado.` |
| `missing` | Id desconocido o campos no textuales | `Código captcha no encontrado.` |
| `blocked` | Honeypot o rate limit | `La petición parece automatizada.` / `Demasiados intentos, espera unos segundos y vuelve a intentarlo.` |

Cuando **cualquier** verificación corre, el reto se consume; por eso tras un intento fallido el formulario siempre muestra un captcha nuevo.

## El widget

```php
<?= \Captcha\Captcha::widget(['endpoint' => '/ruta/al/endpoint.php']) ?>
```

Opciones del widget (`WidgetOptions`, ambas opcionales):

- `endpoint` — URL que devuelve `{ok, image, id}` para el botón de recarga. Default: `/captcha/endpoint`.
- `theme` — `'light'` (default), `'dark'` o `'auto'` (sigue `prefers-color-scheme`). Cualquier otro valor cae a `light`.

El HTML que emite es **estático** (sin script inline propio): CSS/JS empaquetados (`captcha.min.css`/`.min.js`) se inyectan inline la primera vez, o los sirves tú con `Captcha::assets('url', $baseUrl)`. El JavaScript solo recarga la imagen; si el endpoint falla (404, red, cuerpo ilegible) **recarga la página** como último recurso; un error real del endpoint con `data-error` (p. ej. un 429) se muestra sin recargar.

Si `generate()` queda limitado por tasa durante un render, el widget **degrada** a un estado de error (banda con el mensaje, sin imagen/campo/reto) en vez de romper tu plantilla; el endpoint de recarga conserva su `429` real.

## El endpoint de recarga (AJAX)

Dos caminos equivalentes:

**A. Un controlador propio en tu framework**

Crea una ruta que llame a `Endpoint::dispatch($request->getQuery('action'))` y devuelva el JSON. Este enfoque se integra nativamente con tu framework y respeta su routing.

```php
(new \Captcha\Http\Endpoint(\Captcha\Captcha::instance()))->handle($_GET['action'] ?? '');
```

**B. Tu propio controlador** (así lo harías en un framework con front controller)

```php
use Captcha\Captcha;
use Captcha\Http\Endpoint;

$action = $_GET['action'] ?? 'generate';
$action = is_string($action) ? $action : 'generate';  // ?action[]= un array → 400, nunca 500
$response = (new Endpoint(Captcha::instance()))->dispatch($action);
http_response_code($response['status']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
```

`Endpoint::dispatch()` devuelve una respuesta pura y testeable:

| Acción | Estado | Cuerpo |
|---|---|---|
| `generate` | `200` | `{"ok": true, "id": "...", "image": "data:image/png;base64,...", "mime": "image/png", "width": 200, "height": 60}` |
| acción desconocida | `400` | `{"ok": false, "error": "Acción desconocida."}` |
| excedido `generateAttempts` | `429` | `{"ok": false, "error": "Demasiados captchas generados, espera unos segundos."}` |

Las respuestas JSON llevan `Cache-Control: no-store, no-cache, must-revalidate`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`, **`X-Frame-Options: DENY` y `Referrer-Policy: no-referrer`**, para que ni el navegador ni proxies intermedios sirvan retos caducos ni la respuesta pueda incrustarse o filtrar la URL de tu página.

---

## Referencia de extensión

### Almacenamiento

| Clase | Dónde vive el reto | Cuándo elegirla |
|---|---|---|
| `SessionStorage` (legacy) | `$_SESSION['_captcha']` | PHP plano / CLI. Arranca la sesión de forma **diferida**, en el primer acceso real (construir la clase nunca preempta la sesión del host). |
| `FileStorage` | Un JSON por reto, nombre = `sha256(id)` | **Default `'auto'` bajo frameworks** que gestionan la sesión (CodeIgniter, Laravel, Symfony, CakePHP, Yii, Yii 3): funciona sin tocar la sesión del host. También host sin sesiones o multi-nodo con directorio común. `consume()` con `flock` = anti-replay real bajo concurrencia. |
| `ArrayStorage` | Memoria del proceso | CLI, colas, tests (reloj inyectable, sin `sleep()`). |

La opción `storage` decide el backend en los dos caminos (`'auto'` = el
detector de host decide; ver la [tabla de opciones](configuracion.md#tabla-completa-de-opciones)):
en la capa estática y también en `new Captcha(config: ...)` cuando no
inyectes un storage propio — en ese caso manda lo que pases a mano.
`Captcha::instance()->storage()` lo inspecciona, y `doctor` lo reporta.

Implementa `Captcha\Contract\StorageInterface` (`put`, `get`, `consume`, `has`, `forget`) para tu propio backend (Redis, DB...). El contrato exige que `consume()` sea atómico: **exactamente un** llamador obtiene el código bajo concurrencia.

### Excepciones

Todas extienden `CaptchaException` (que a su vez extiende `\RuntimeException`):

| Excepción | Cuándo |
|---|---|
| `InvalidConfigException` | Opción con tipo/rango inválido, config file que no devuelve array, preset desconocido... |
| `GdNotAvailableException` | Falta `ext-gd` o falla la codificación PNG. Lanza antes de renderizar para fallar temprano. |
| `StorageException` | El backend no puede leer/escribir/borrar una entrada (mensajes sin códigos ni ids). |
| `RateLimitException` | `generate()` excede la ventana (o la IP es inutilizable) → responde 429. |

Un solo `catch (\Captcha\Exception\CaptchaException $e)` cubre todo el paquete.

### Contratos (extensibilidad)

- `GeneratorInterface::generate(int $length): string` — generador propio (p. ej. palabras, emojis...).
- `ExpressionProviderInterface::expression(): string` — si tu generador dibuja algo distinto del código (el texto del reto), como hace `NumericGenerator`.
- `RendererInterface::render(string $code, Config $config): string` / `mimeType()` — renderer propio.
- `StorageInterface` + `RateLimiterInterface::allow(string $key, int $limit, int $windowSeconds): bool` — backend propio.

---

Siguiente: [Seguridad](seguridad.md) · [Integración](integracion.md) · [Consola](cli.md) · [Desarrollo](desarrollo.md)
