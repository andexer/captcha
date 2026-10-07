# Rate limiting, honeypot y endurecimiento

> Forma parte de la documentación de [captcha](../README.md). Volver al [inicio](../README.md).

## Rate limit (anti-flood y anti-brute-force)

- Default **activo**: `verifyAttempts` = **5** y `generateAttempts` = **20** por ventana de 300 s. Es un techo *out-of-the-box* pensado para que un forgot-password no se convierta en un oráculo de fuerza bruta, sin que tengas que tocar el config. Ponlo a `0` para desactivarlo (o súbelo si tu tráfico legítimo es mayor).
- **Limiter dual por defecto** (con `rateLimitByIp: true`): `IpRateLimiter` (contadores en archivos JSON por IP en `sys_get_temp_dir()/captcha-limits`, con `flock`) **y** `SessionRateLimiter` (contadores en `$_SESSION['_captcha_limits']`), combinados por `CompositeRateLimiter` como un **AND sin cortocircuito** — por qué importa: si un backend ya rechaza, no quieres que el otro quede intacto para un atacante que limpia galletas.
- Los ficheros por IP cierran el hueco de las peticiones **sin cookies** (curl, bots), que la sesión no puede cerrar.
- **Fail-closed deliberado**: sesión inactiva, fichero corrupto/no escribible o IP de cliente ausente ⇒ el intento se **rechaza** (verify → `blocked` sin consumir; generate → `RateLimitException` → 429). Un limiter que no puede contar jamás abre la puerta.
- **Fuera del SAPI web no se cuenta nada** (`NullRateLimiter`): un proceso CLI es tu propio código de confianza y no tiene cliente al que atribuirle intentos. Una bolsa por PID no frenaría a nadie (un proceso nuevo empieza con el contador a cero) y sí mataría a un worker legítimo a los 20 captchas. El fail-closed de arriba es cosa del caso web, que es el único con un cliente remoto.
- **Claves por operación**: `generate:<cliente>` y `verify:<cliente>` son bolsas independientes (subir el captcha no gasta intentos de verificación ni al revés).
- **0 = off**, y el limiter se crea **lazy**: si ningún límite está activo, la instancia por defecto jamás arranca una sesión ni toca el filesystem.
- Los bloqueados **siguen contando**, y la ventana se resetea al expirar.

## IP del cliente y proxies de confianza

La clave por defecto es `Globals::clientIp($config->trustedProxies)`. `X-Forwarded-For` **nunca se confía por defecto**: con `trustedProxies` vacío solo se usa `REMOTE_ADDR`, así que un cliente no puede rotar su cubeta falseando la cabecera. Solo si `REMOTE_ADDR` coincide con un proxy declarado se recorre la cabecera de derecha a izquierda hasta el primer salto no confiable (fallback a `REMOTE_ADDR`). Las IPs deben ser **exactas** (sin CIDR) y se normalizan las `::ffff:a.b.c.d`.

`rateLimitByIp: false` restaura el modo solo-sesión (para hosts que garantizan una). También puedes inyectar tu propio `RateLimiterInterface` (Redis, APCu, DB...) como quinto argumento del constructor.

## Honeypot (trampa anti-bots)

Con `honeypot: true`, el widget emite `<span class="ct__honeypot">` con un input invisible (fuera de vista, no `display:none`, para que el autofill lo encuentre). `verifyRequest()` rechaza cualquier petición cuyo `honeypotField` llegue con contenido: `Status::Blocked`, `La petición parece automatizada.`

> **Aviso:** el campo por defecto es `'email'`. En un formulario de login que ya tenga un campo `email`, activar el honeypot sin cambiar `honeypotField` rompería el formulario (¡los humanos también lo rellenan!). Cámbialo a un nombre inexistente en tu formulario, p. ej. `'website'` (como hace el preset `strict`).

## Endurecimiento activo por defecto

- **Input sobredimensionado**: `verify()` rechaza cualquier código de más de **32 bytes** (`Status::Invalid`) **sin consumir el reto** — un código numérico/aritmético ocupa pocos bytes, así que una cadena kilométrica no es una respuesta genuina; el intento sí cuenta para el rate limit. El widget acota la entrada con `maxlength`, así que un humano nunca llega ahí.
- **Cookie de sesión del paquete**: en PHP plano / CLI, las sesiones que el paquete arranca él mismo (no las de tu framework) piden `HttpOnly` + `SameSite=Lax`, y **`Secure`** cuando el cliente conecta por HTTPS. Si tu app ya configuró la cookie, o un framework gestiona la sesión, el paquete **no toca nada** (las decisiones del host se respetan: `src/Http/SessionCookie.php`).
- El endpoint JSON responde con `X-Frame-Options: DENY` y `Referrer-Policy: no-referrer` (ver [El endpoint de recarga](api.md#el-endpoint-de-recarga-ajax)).

## CaptchaGuard: capa de verificación para middlewares

`CaptchaGuard` (`src/Security/`) es la capa pura que decide si una petición cruza el captcha, sin atarse a ninguna interfaz de framework (cero dependencias):

```php
use Captcha\Captcha;
use Captcha\Security\CaptchaGuard;

$guard = new CaptchaGuard(Captcha::instance());
$decision = $guard->decide($requestFields, requireSubmission: true);
```

- `decide(array $data, bool $requireSubmission = false): GuardDecision` es **pura** (no hace I/O): recibe los campos del POST y devuelve una decisión; `requireSubmission: true` hace que un POST que se **salta** el campo oculto sea rechazado con 422 (no se cuela), mientras que con `false` un GET / primera visita devuelve `idle` (la petición fluye y el widget muestra el reto).
- **Aviso: con el `requireSubmission` por defecto (`false`), un id vacío responde SIEMPRE `idle` → `allowed`.** El guard es puro y no ve el método HTTP: si le pasas el body de un POST *sin* `requireSubmission: true`, esa petición **cruza sin captcha**. Por eso toda receta pasa `true` en el POST real. (`Captcha::check()` no tiene esta trampa: él mismo comprueba `isPost` antes de verificar).
- `GuardDecision` (inmutable) expone `allowed`, `idle`, `status` (`Status`), `httpStatus` y `message` (feedback fijo en español). Mapeo: `ok` → 200; `invalid`/`expired`/`missing` → 422; `blocked` (rate limit u honeypot) → 429.
- `vendor/bin/captcha install` emite ese pegamento por ti: **CodeIgniter** (Filter), **Laravel** (middleware), **Symfony** (listener de `kernel.request`, con el atributo `#[AsEventListener]` que lo registra solo), **CakePHP** (middleware PSR-15), **Yii** (action filter), **Yii 3** (middleware PSR-15), **Janssen** (preprocesador) y **PHP puro** (drop-in) — todos con el mismo esqueleto: GET pasa, POST verifica y trunca con `httpStatus` + `message`. El instalador escribe los ficheros y, al terminar, imprime los pasos de registro que no puede automatizar (registrar un provider, añadir un middleware a la pila, publicar una ruta). En Symfony no combines el atributo `#[AsEventListener]` con la etiqueta `kernel.event_listener`: el listener entraría dos veces y la segunda vería un reto ya consumido.

---

Siguiente: [Integración](integracion.md) · [Consola](cli.md) · [API](api.md)
