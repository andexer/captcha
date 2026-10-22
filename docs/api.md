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

El HTML que emite es **estático** (sin script inline propio): CSS/JS empaquetados (`captcha.min.css`/`.min.js`) se inyectan inline la primera vez, o los sirves tú con `Captcha::assets('url', $baseUrl)`. El JavaScript recarga la imagen, aplica el feedback de rechazo y expone su propia API (siguiente sección); si el endpoint falla (404, red, cuerpo ilegible) **recarga la página** como último recurso; un error real del endpoint con `error` (p. ej. un 429) se muestra sin recargar.

Si `generate()` queda limitado por tasa durante un render, el widget **degrada** a un estado de error (banda con el mensaje, sin imagen/campo/reto) en vez de romper tu plantilla; el endpoint de recarga conserva su `429` real.

### Feedback de rechazo y reto nuevo

Cuando `check()` ya rechazó el POST, el widget sale con `data-status` (`invalid`, `expired`, `missing` o `blocked`) y el JS, al arrancar, aplica borde rojo y temblor de 900 ms, vacía el campo y deja el foco listo. El servidor ya emitió un reto nuevo en ese mismo render (el que el cliente debe usar en el siguiente intento), así que no hace falta pedir otro: no hay generación duplicada ni riesgo de bucle. No hay nada que cablear: el flujo nativo de `check()` + `widget()` lo hereda solo.

Si prefieres que además pida otro reto al endpoint, marca el widget con `data-refresh-on-fail="true"` (el widget tiene que tener endpoint). En flujos fetch sin recarga de página, `Captcha.reject()` sí recarga por defecto, porque ahí el DOM conserva el reto consumido.

Si ya pintas el mensaje de error en tu plantilla, el widget **no lo duplica**: el `data-status` solo mueve la animación. Para que además lo muestre, añade `data-message` al contenedor o llama a `reject()` con el texto (abajo).

## API JavaScript del widget

El JS empaquetado se expone como `window.Captcha` (o `module.exports` en Node) con una API orientada a objetos: todo lo que hace el widget se puede leer, sustituir o desactivar sin copiar el fichero. Sin configurar nada, los valores por defecto dejan el comportamiento descrito arriba.

| Miembro | Qué hace |
|---|---|
| `Captcha.version` | Versión del asset. |
| `Captcha.defaults()` | Copia de las opciones efectivas (built-in + `configure()`). |
| `Captcha.configure(options)` | Fija opciones globales; los objetos `feedback`/`messages` se fusionan, no se reemplazan. Devuelve la configuración resultante. |
| `Captcha.init(root?)` / `Captcha.autoInit` | Inicializa los `[data-captcha]` del ámbito (el documento por defecto) y devuelve las instancias. Idempotente. |
| `Captcha.get(elOSelector)` | Instancia `Widget` del elemento (la crea si aún no existe). |
| `Captcha.reject(el, message?, status?, options?)` | Dispara el feedback y, por defecto, recarga el reto. |
| `Captcha.refresh(el)` | Recarga el reto contra el endpoint. |
| `new Captcha.Widget(el, options?)` | Widget con opciones propias, sin pasar por `init()`. |

### Opciones

| Opción | Default | Qué controla |
|---|---|---|
| `endpoint` | `data-endpoint` o `''` | URL que devuelve `{ok, image, id}`. Sin endpoint se recarga la página. |
| `timeout` | `8000` | Milisegundos antes de abortar la petición de recarga. |
| `reloadOnFail` | `true` | Recargar el reto tras `reject()`/`refresh()`. `false` deja el control en tus manos. `data-status` no recarga salvo `data-refresh-on-fail="true"`. |
| `clearOnFail` | `true` | Vaciar el campo del código al rechazar. |
| `focusOnFail` | `true` | Devolver el foco al campo si no se recarga (con `reloadOnFail: false`). |
| `feedback.duration` | `900` | Duración del temblor en ms (el rango pedido: 800–1000). |
| `feedback.distance` | `4` | Amplitud del temblor en px. |
| `feedback.shake` | `true` | Activar/desactivar el temblor (el borde rojo sigue). |
| `feedback.borderColor` | `''` | Color del borde; vacío usa el rojo del tema. |
| `feedback.shadow` | `''` | Halo rojo (`box-shadow`); vacío usa el del tema. |
| `feedback.invalidClass` | `ct--invalid` | Clase que pinta el borde y el halo. |
| `feedback.shakeClass` | `ct--shake` | Clase que aplica la animación. |
| `messages` | textos en español | `invalid`, `expired`, `missing`, `blocked`, `reloadError`. |
| `onReject(widget, detalle)` | `null` | Callback al rechazar (`{status, message}`). |
| `onRefresh(widget, data)` | `null` | Callback con el JSON del reto nuevo. |
| `onError(widget, mensaje)` | `null` | Callback ante un error del endpoint. |

El widget emite además los eventos `captcha:reject`, `captcha:refresh` y `captcha:error` (burbujean), con el mismo detalle; úsalos si prefieres escuchar en vez de pasar callbacks.

### Configurar sin tocar el JS

El markup admite opciones declarativas por widget:

- `data-endpoint`: mantiene la forma corta de siempre.
- `data-captcha-options`: JSON con cualquier opción, p. ej. `data-captcha-options='{"reloadOnFail": false, "feedback": {"duration": 800}}'`.
- `data-refresh-on-fail`: `true`/`false`; atajo de `reloadOnFail` y opt-in de la recarga automática tras un `data-status` del servidor.
- `data-status` / `data-message`: los emite el servidor; también valen en markup propio.

```js
// Global: una vez, antes o después de que el widget se inicialice.
Captcha.configure({
    feedback: { duration: 800, distance: 3 },
    reloadOnFail: false,
});

// Por widget, con control total.
var widget = new Captcha.Widget(document.querySelector('[data-captcha]'), {
    endpoint: '/captcha/generar',
    feedback: { shake: true, duration: 1000 },
    onReject: function (widget, detalle) {
        console.log('Rechazado:', detalle.status);
    },
});
```

### Respuesta asíncrona: el caso que rompía el reintento

Si verificas por fetch, el DOM conserva el reto ya consumido; hay que reemplazarlo. `reject()` hace las dos cosas (feedback y reto nuevo) con el `status` y el `message` que devuelva tu API:

```js
const respuesta = await fetch('/api/login', {
    method: 'POST',
    body: new FormData(formulario),
    headers: { 'Accept': 'application/json' },
});
const data = await respuesta.json();

if (!data.valid) {
    // status: 'invalid' | 'expired' | 'missing' | 'blocked'
    Captcha.reject(formulario.querySelector('[data-captcha]'), data.message, data.status);
}
```

Tras el temblor, el widget pide un reto nuevo al endpoint, escribe el `id` en el campo oculto y vacía el código: el segundo intento ya no llega caducado. Para un manejo 100 % propio, `new Captcha.Widget(el, { reloadOnFail: false })` y llama tú a `widget.refresh()` cuando toque.

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

**Única excepción a ese `catch`:** un argumento con el tipo PHP equivocado en `new Config(...)` (o en un setter del builder) es un `\TypeError` nativo, que no hereda de `CaptchaException`. Solo puede ocurrir en código tuyo —las rutas sin tipos (`fromArray()`, `configure()`, el fichero de config) reportan el mismo desajuste como `InvalidConfigException`—, y un valor con el tipo correcto pero fuera de rango es `InvalidConfigException` en las dos. Detalle en [configuracion.md](configuracion.md#validaciones).

### Contratos (extensibilidad)

- `GeneratorInterface::generate(int $length): string` — generador propio (p. ej. palabras, emojis...).
- `ExpressionProviderInterface::expression(): string` — si tu generador dibuja algo distinto del código (el texto del reto), como hace `NumericGenerator`.
- `RendererInterface::render(string $code, Config $config): string` / `mimeType()` — renderer propio.
- `StorageInterface` + `RateLimiterInterface::allow(string $key, int $limit, int $windowSeconds): bool` — backend propio.

---

Siguiente: [Seguridad](seguridad.md) · [Integración](integracion.md) · [Consola](cli.md) · [Desarrollo](desarrollo.md)
