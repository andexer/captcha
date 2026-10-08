# Configuración

> Forma parte de la documentación de [captcha](../README.md). Volver al [inicio](../README.md).

## La capa estática y cómo se carga

El paquete ofrece una **capa estática** con auto-configuración (para la mayoría de los casos) y la **instancia manual** con dependencias inyectadas (para casos avanzados).

- `Captcha::instance()` construye (y cachea por petición) el singleton con el config descubierto.
- `Captcha::reset()` descarta el singleton.
- `Captcha::configure([...])` configura la capa estática **por programa**, sin necesitar archivo; acepta un array de opciones **o una instancia ya armada de `Config`** (`new Config(...)`, `Config::forLogin()`, `Config::builder()->...->build()`). Una llamada a `configure([])` libera la configuración manual y devuelve el control al descubrimiento.
- `Captcha::check()` / `widget()` / `valid()` / `message()` / `submitted()` funcionan igual como estáticos (sobre el singleton) que como métodos de instancia.

## Descubrimiento del config (solo para la capa estática)

Primer acierto gana; si ninguno existe se usan los valores por defecto:

```text
1. getenv("CAPTCHA_CONFIG")
2. <raíz del proyecto>/app/Config/captcha.php
3. <raíz del proyecto>/config/captcha.php
4. <raíz del proyecto>/config/packages/captcha.php   (retrocompatibilidad con rc)
5. <raíz del proyecto>/etc/captcha.php
6. <cwd>/app/Config/captcha.php
7. <cwd>/config/captcha.php
8. <cwd>/config/packages/captcha.php                 (retrocompatibilidad con rc)
9. <cwd>/etc/captcha.php
```

(Con una sola raíz resoluble. Si el paquete está enlazado, se prueban dos
raíces candidatas —tres y cuatro niveles— y cada una repite las cuatro
subrutas de arriba.)

La **raíz del proyecto** se resuelve desde la ubicación de instalación del
paquete, no desde el cwd del proceso: se prueban los anclajes de **tres y cuatro
niveles** por encima de `src/`, cubriendo tanto una instalación estándar en
`<raíz>/vendor/andexer/captcha` (cuatro niveles) como un repo path con
symlink en `<raíz>/<cualquier dir>/captcha` (tres niveles tras resolver el
symlink). Así un framework que sirve desde `public/` (CodeIgniter, Laravel...)
encuentra su `app/Config/captcha.php` **sin variable de entorno**, aunque el
cwd web sea el docroot. El archivo debe `return array;` (si devuelve otra cosa:
`InvalidConfigException`). La env `CAPTCHA_CONFIG` sigue siendo la vía
recomendada cuando quieras saltarte por completo el escaneo.

**Firma obligatoria en los anclajes.** Las candidaturas de la raíz del
proyecto (2–5 del orden) son *opt-in*: solo se aceptan si el fichero lleva
la línea de comentario `// captcha config v2` en su cabecera. Así el
paquete no absorbe por accidente el `captcha.php` de una aplicación ajena
en un checkout anidado: un fichero en el ancla sin la firma se **ignora**
(no lanza) y el `doctor` lo reporta como `ignorado`. Las vías explícitas —
env y cwd— nunca exigen la firma. Las plantillas de `install` ya la llevan;
si escribes el config a mano, conserva esa línea.

`Captcha::configPath()` te dice (pura inspección) qué fichero cargaría la capa
estática con el estado actual del entorno; el comando `doctor` lo reporta
junto con cada candidatura y su decisión (`cargado`, `ignorado` por firma
ausente, `válido` sombreado por una anterior).

### Variables CAPTCHA_* por opción (12-factor)

Además de `CAPTCHA_CONFIG` (que apunta al fichero), **cada opción** de
`Config::KEYS` puede fijarse por su homóloga en mayúsculas con el prefijo
`CAPTCHA_`: `CAPTCHA_LENGTH`, `CAPTCHA_TTL`, `CAPTCHA_NOISE`, `CAPTCHA_PRESET`...
Así un despliegue cambia un dial sin tocar el fichero, y `doctor` lo nombra en
su reporte (`Env por opción: N variable(s) CAPTCHA_* (...)`).

**Precedencia**: `Captcha::configure([...])` > variables `CAPTCHA_*` > fichero
descubierto (env, anclas, cwd) > defaults. `CAPTCHA_CONFIG` sigue siendo solo la
puntera al fichero, nunca una opción.

**Formato** (los valores llegan como cadenas):

| Familia | Formato | Ejemplo |
| --- | --- | --- |
| Enteros (`length`, `ttl`, `fontSize`...) | cadena numérica | `CAPTCHA_LENGTH=5` |
| Booleanos (`noise`, `distortion`, `injectAssets`, `honeypot`, `rateLimitByIp`) | `1/0`, `true/false`, `yes/no`, `on/off` | `CAPTCHA_NOISE=0` |
| Arrays (`operations`, `between`, `trustedProxies`) | JSON | `CAPTCHA_OPERATIONS='["+","-"]'`, `CAPTCHA_BETWEEN='[2, 20]'` |
| `preset` | nombre del paquete | `CAPTCHA_PRESET=login` |

Un valor **vacío se ignora** (igual que el `CAPTCHA_CONFIG` vacío) y una
sufijo desconocido (`CAPTCHA_NOPE=1`) no hace nada. Un valor **inválido no se
salta en silencio**: el arranque lanza `InvalidConfigException` nombrando la
opción — igual que un fichero con un tipo erróneo —, porque una errata nunca
apaga un dial de seguridad por sorpresa.

```bash
CAPTCHA_PRESET=login CAPTCHA_NOISE=0 php -S localhost:8000
```

> **Consejo:** usa la misma vía para formulario y endpoint. El endpoint de recarga debe ir sobre `Captcha::instance()`, de modo que comparte *por construcción* la misma configuración y el mismo backend de retos que el formulario — nunca hay dos configs que mantener sincronizadas.

> **Out-of-the-box en frameworks:** cuando el paquete detecta un framework que
> gestiona la sesión PHP (CodeIgniter, Laravel, Symfony, **CakePHP, Yii, Yii 3** —
> clases kernel ya cargadas), el storage por defecto pasa automáticamente de
> sesión a **archivos** en `sys_get_temp_dir()/captcha` y el limiter de
> retry se queda solo-IP.
> El captcha funciona con cero configuración y **jamás preempta la sesión del
> host** (los handlers de CodeIgniter/Laravel llaman a `ini_set()` de sesión al
> construirse: arrancar la sesión antes los rompe). Fuera de framework sigue el
> legacy `SessionStorage`. Forza explícito con `'storage' => 'session'|'file'|'array'`.

### Runners persistentes y orden de arranque

La capa estática asume que PHP reinicia los estáticos en cada petición (FPM, CLI). En un **runner persistente** —FrankenPHP en modo worker, RoadRunner, Swoole— el proceso vive entre peticiones, así que el singleton y la config también: llama a `Captcha::reset()` en cada petición (y `Captcha::configure([...])` de nuevo si usas opciones manuales) para que ninguna respuesta herede el estado de la anterior.

Con `storage => 'auto'` la elección de backend mira qué kernels de framework están **ya cargados** en el momento del primer `Captcha::instance()`. Si esa primera llamada llega antes de que el host cargue su framework, el paquete se ve como PHP plano y elige sesión; corrígelo con `Captcha::reset()` una vez arrancado el framework, o no llames a captcha hasta después de su bootstrap.

El patrón en la práctica (receta completa en `src/examples/06-runners-persistentes.php`):

**Laravel Octane** — una limpieza por petición:

```php
use Captcha\Captcha;
use Laravel\Octane\Events\RequestReceived;

app('events')->listen(RequestReceived::class, static function (): void {
    Captcha::reset();
});
```

**RoadRunner / Swoole** — el `finally` del bucle garantiza que ninguna petición herede el singleton de la anterior:

```php
use Captcha\Captcha;

while ($request = $worker->waitRequest()) {
    try {
        $worker->send($kernel->handle($request));
    } finally {
        Captcha::reset();
    }
}
```

**FrankenPHP en modo worker** (o cualquier bootstrap global): `Captcha::reset()` al cierre de cada request, **después** de que el framework haya cargado sus kernels, de modo que el `storage => 'auto'` de la siguiente petición los vea.

`reset()` suelta la instancia pero **no** el config manual (`configure()` sobrevive a propósito, porque lo fija el bootstrap). Si tu arranque es por petición, repite `Captcha::configure([...])` después del `reset()`; para devolver el mandado al descubrimiento, `Captcha::configure([])`.

## Opción A — copiar la plantilla

```bash
vendor/bin/captcha install                         # PHP plano / CodeIgniter 4 → app/Config/captcha.php
vendor/bin/captcha install laravel                # Laravel → config/captcha.php
vendor/bin/captcha install --framework=symfony     # Symfony → config/captcha.php
```

Genera el config inicial del framework indicado (**`plain`, `codeigniter`, `laravel`, `symfony`, `cakephp`, `yii`, `yii3`, `janssen`**) en su ruta canónica: es un `return []` activo, así que el fichero es válido desde el primer segundo. En las siete plantillas con fragmento (`plain`…`yii3`) aparecen **todas** las opciones, cada una comentada junto a su default, y basta descomentar la que necesites; la de Janssen, que se reparte en un preprocesador y un controlador, llega con los valores ya activos y solo las claves que ese patrón necesita. **Nunca sobrescribe** un fichero existente. Las plantillas se generan a partir del fragmento compartido de opciones (`src/app/Config/fragments/options.php`) — regenéralas con `composer config:templates` tras editarlo; las copias distribuidas viven en `src/app/Config/templates/` y ya llevan la firma del anclaje. Ver [Integración por framework](integracion.md).

## Opción B — armar tu propio array

Crea `app/Config/captcha.php` (o `config/captcha.php`, o fija `CAPTCHA_CONFIG`) con un `return` de opciones. Recuerda: en la raíz del proyecto el fichero necesita la línea de firma del descubrimiento (la env nunca la pide). Este es un ejemplo **mínimo y completo**, con los ajustes típicos para un formulario:

```php
<?php

declare(strict_types=1);

// captcha config v2

return [
    // Código de 5 dígitos.
    'length' => 5,
    // Imagen de 200x60 píxeles.
    'width' => 200,
    'height' => 60,
    // El código es válido durante 2 minutos.
    'ttl' => 120,
    // Dificultad media: en modo aritmético, los operandos se reparten dentro
    // del techo por longitud (una décima parte, con suelo 9).
    'difficulty' => 'medium',
    // Tipografía y tamaño del glifo: fuente bitmap de GD (1-5) y altura en px.
    'font' => 5,
    'fontSize' => 35,
    // Imagen con ruido y distorsión para dificultar el OCR.
    'noise' => true,
    'distortion' => true,
    // Rate limit: máx. verificaciones y generaciones por ventana.
    'verifyAttempts' => 10,
    'generateAttempts' => 30,
    // Modo aritmético: suma y resta. La imagen muestra "a + b" o "a - b";
    // el usuario teclea el RESULTADO, que siempre estará entre 0 y 20.
    'operations' => ['+', '-'],
    'between' => [0, 20],
];
```

O en una sola línea si lo prefieres (todo lo que no pongas usa su valor por defecto):

```php
return ['preset' => 'login', 'operations' => ['add', 'subtract'], 'between' => [2, 20]];
```

## Atajo de paquete (`preset`)

Un grupo de opciones listas para cada caso. **Las claves explícitas ganan** sobre las del preset (la unión se resuelve con precedencia a la izquierda), así que `preset` + retoques conviven bien:

| Preset | Concepto | Qué fija |
|---|---|---|
| `default` | Defaults del constructor | Nada (se usa el valor de cada clave por defecto). |
| `login` | Formularios de acceso | 5 dígitos, 200×60, dificultad baja, sin ruido ni distorsión, rate limit 5 verify / 30 generate. No toca el TTL: queda el default (120). |
| `strict` | Máxima dureza anti-spam | 6 dígitos, 220×64, dificultad alta, con ruido y distorsión, 5 verify / 20 generate, honeypot activo (`website`). Tampoco toca el TTL (120). |

## Tabla completa de opciones

Todas son **opcionales**. Las que aceptan número también aceptan su texto numérico (p. ej. `'6'`), **pero solo por array**: `fromArray()`, `configure()` y el fichero de config. El constructor y los setters del builder exigen tipos nativos (`new Config(length: '6')` lanza `TypeError`).

| Clave | Default | Acepta | Descripción |
|---|---|---|---|
| `length` | `6` | entero **3…10** | Dígitos del código. Más dígitos = más seguridad, menos legibilidad. |
| `width` | `180` | entero 1…5000 | Ancho de la imagen en píxeles. |
| `height` | `60` | entero 1…2000 | Alto de la imagen en píxeles. |
| `ttl` | `120` | entero ≥ 1 | Segundos que el código permanece válido. |
| `output` | `'png'` | `'png'` | Formato de imagen (por ahora solo PNG). |
| `difficulty` | `'medium'` | `'low'`/`'medium'`/`'high'` (o enum `Difficulty`) | Gradúa ruido, distorsión, inclinación de glifos y —en modo aritmético— el tamaño de los operandos. |
| `font` | `5` | entero **1…5** | Fuente bitmap integrada de GD para los glifos. **Sin TTF nunca.** |
| `fontSize` | `null` | `null` o entero ≥ 1 | Altura objetivo del glifo en píxeles; `null` la deriva del lienzo (~78 % del alto, tope ×4). |
| `noise` | `true` | bool | Dibuja puntos y líneas aleatorios sobre la imagen. |
| `distortion` | `true` | bool | Aplica una distorsión de onda horizontal. |
| `idField` | `'captcha_id'` | texto no vacío | Nombre del campo oculto que transporta el id del reto. Es la **única fuente de verdad** para el widget y para `verifyRequest()`. |
| `inputField` | `'captcha'` | texto no vacío | Nombre del campo donde el usuario teclea el código. |
| `injectAssets` | `true` | bool | Inyecta el CSS/JS empaquetados con el primer `widget()`. Con `false`, enlázalos tú mismo (CSP estricta) vía `Captcha::assets()` o `$captcha->assets()`. |
| `operations` | `[]` | array | Operaciones aritméticas habilitadas (ver [Modo aritmético](tipos-de-captcha.md#modo-aritmético-captcha-matemático)). Vacío = dígitos clásicos. |
| `between` | `null` | `[min, max]` | Rango de resultados aritméticos inclusivo; **requiere** `operations`. |
| `verifyAttempts` | `5` | entero ≥ 0 | Máx. verificaciones por clave y ventana; `0` = desactivado. Superado → `verify()` devuelve «bloqueado» sin consumir el reto. Solo aplica en el SAPI web. |
| `generateAttempts` | `20` | entero ≥ 0 | Máx. captchas generados por clave y ventana (frena el flood de CPU con GD); `0` = desactivado. Superado → el endpoint responde **HTTP 429**. Solo aplica en el SAPI web. |
| `rateLimitWindow` | `300` | entero ≥ 1 | Anchura en segundos de la ventana del rate limit (común a verify y generate). |
| `honeypot` | `false` | bool | Renderiza un campo trampa invisible; si llega con contenido, la petición se rechaza antes de verificar. |
| `honeypotField` | `'email'` | texto no vacío | Nombre del campo trampa. **Debe ser distinto de los campos reales** del formulario. |
| `rateLimitByIp` | `true` | bool | Incluye la IP del cliente en la clave del límite. En PHP plano el limiter es dual IP+sesión; bajo un framework que gestione la sesión (ver `storage`) se queda solo-IP. `false` = solo sesión. |
| `trustedProxies` | `[]` | lista de IPs válidas | IPs exactas (sin rangos/CIDR) autorizadas a pasar `X-Forwarded-For`. Vacío = la cabecera se ignora. |
| `storage` | `'auto'` | `'auto'`/`'session'`/`'file'`/`'array'` | Backend de los retos. `'auto'` elige en runtime: `file` bajo un framework que gestione la sesión PHP, `session` en PHP plano/CLI; nunca toca la sesión del host sin que se lo pidas. Los otros valores fuerzan ese backend. Rige en la capa estática **y** en `new Captcha(...)` cuando no inyectas un storage propio. |

> El bloque marcado «1b» de la plantilla (`src/app/Config/captcha.php`) es el mismo array completo comentado, listo para copiar y pegar.

## Config desde PHP: modo estricto, merge y builder

`Config` es un **value object `final readonly`**, y los arrays se validan al construir. La misma API te sirve para configurar desde PHP sin ningún fichero:

- **`Config::fromArray(array $opts, bool $strict = true)`** — valida tipos y rangos. Por defecto es **estricto**: una clave no reconocida lanza `InvalidConfigException` en español (`Claves de configuración no reconocidas: "verifyAtempts".`) — un typo nunca desactiva silenciosamente el rate limit ni cae en defaults. Pasa `strict: false` si prefieres ignorar lo desconocido (compatibilidad) **por esta vía**: `Captcha::configure()` y el descubrimiento de fichero aplican siempre el modo estricto, y `configure()` lo aplica **en el momento de la llamada** — un typo se queja en la línea que lo escribió, no en el primer `instance()`.
- **`Config::toArray()`** — exporta el config efectivo (preset ya expandido, `operations` como valores canónicos `add|subtract|multiply|divide`, `difficulty` como `low|medium|high`), simétrico con `fromArray()` para poder serializar/deserializar sin pérdida.
- **`Config::merge(array|Config $overrides)`** — inmutable: devuelve un `Config` nuevo aplicando un parche (array o `Config`) sobre el actual, revalidado. Una clave explícita del parche o de la base gana siempre; un `preset` del parche solo rellena los huecos que la base no cubre. Sirve para combinar un presets con overrides de entorno:
  ```php
  $config = Config::forLogin()->merge(['verifyAttempts' => 10, 'length' => 7]);
  ```
  > **Un preset ya materializado no se reanuda.** `Config::forLogin()` dejó sus valores como explícitos, así que `->merge(['preset' => 'strict'])` solo rellenaría huecos que ya no existen: sigue mandando `login`. Para cambiar de preset, empieza de nuevo con `Config::fromArray(['preset' => 'strict'])` (o `Config::forStrict()`).
- **Fábricas**: `Config::defaults()` (= `new Config()`), `Config::forLogin()` y `Config::forStrict()` (equivalen a `fromArray(['preset' => ...])`, listas para `merge()`).
- **`Config::builder()`** — API fluida y tipada (los setters se llaman igual que las claves): `Config::builder()->length(5)->operations(['+','-'])->verifyAttempts(5)->build()`. Acepta `preset()` y `from()` (un array, un `Config` o otro builder); `build()` valida vía `fromArray()` estricto y devuelve el `Config` inmutable.

Todos los caminos convergen en el mismo `Config` validado, así que el fichero, `configure()`, el constructor y el builder no pueden divergir:

```php
// Las cuatro vías al mismo Config (longitud 5, el resto por defecto):
new Config(length: 5);
Config::fromArray(['length' => 5]);
Config::builder()->length(5)->build();
Captcha::configure(['length' => 5]);
```

El constructor exige tipos nativos: `difficulty` recibe el enum `Captcha\Config\Difficulty::Low`, no la cadena `'low'`, y no admite `preset` — ese atajo es exclusivo del camino de array.

---

## Validaciones

Toda opción se valida **al construir** el `Config` («inmutable»): un valor inválido lanza `InvalidConfigException` (hija de `CaptchaException`) y la instancia jamás se observa en un estado inválido. En arrays, las **claves desconocidas lanzan** `InvalidConfigException` por defecto (modo **estricto**, para que un typo no desactive silenciosamente protección alguna); con `Config::fromArray($opts, strict: false)` se ignoran, como hacía la versión anterior.

> **Tipos en la ruta `new Config(...)`:** esa ruta exige los tipos PHP nativos (ver la nota de la tabla completa), así que un valor de otro tipo —`noise: 'yes'`— es un `TypeError` de PHP y no un `InvalidConfigException`: lo captura `catch (\TypeError $e)`, **no** el `catch (\Captcha\Exception\CaptchaException $e)` del paquete. Es lo contrario que por array, que no tiene tipos y por eso reporta el desajuste como `InvalidConfigException` en español. En los dos caminos, un valor **con** el tipo correcto pero fuera de rango o desconocido es siempre `InvalidConfigException`.

> **Única excepción de momento:** el cruce `between` ↔ `length` se comprueba en `generate()`, no al construir. El paquete prefiere **recortar** el rango a denegar el captcha: si `max` no cabe en `length` dígitos se acota al techo, y solo lanza `InvalidConfigException` cuando el `min` no cabe — y con el mensaje de la tabla de abajo.

| Clave | Regla | Mensaje exacto (en español) |
|---|---|---|
| `length` | entero **3…10** (admite `'6'`) | `La longitud debe estar entre 3 y 10; se recibió N.` |
| `width` | entero **1…5000** | `El ancho debe estar entre 1 y 5000; se recibió N.` |
| `height` | entero **1…2000** | `El alto debe estar entre 1 y 2000; se recibió N.` |
| `ttl` | entero ≥ 1 | `La TTL debe ser de al menos 1 segundo; se recibió N.` |
| `font` | entero **1…5** | `'font' debe estar entre 1 y 5 (fuente bitmap de GD); se recibió N.` |
| `fontSize` | `null` o entero ≥ 1 | `'fontSize' debe ser un entero positivo; se recibió N.` |
| `output` | solo `'png'` | `Formato de salida "X" no soportado; solo se admite "png".` |
| `difficulty` | `'low'`/`'medium'`/`'high'` o enum | `'difficulty' no es válido; use "low", "medium", "high".` |
| `noise`, `distortion`, `injectAssets`, `honeypot`, `rateLimitByIp` | bool estricto | `'X' debe ser booleano.` |
| `idField`, `inputField`, `honeypotField`, `output` | texto | `'X' debe ser texto.` |
| `idField` / `inputField` | no vacíos | `Los nombres de los campos del formulario no pueden estar vacíos.` |
| `honeypotField` (con `honeypot`) | no vacío | `El nombre del campo honeypot no puede estar vacío si está habilitado.` |
| `preset` | `'default'`/`'login'`/`'strict'` | `Preset "X" no reconocido; use "default", "login", "strict".` |
| `operations` | array (4 formatos) | `Operación no reconocida: "X". Use símbolos ("+", "-", "*", "/") o nombres ("addition", "subtraction", "multiplication", "division").` |
| `between` | lista de 2 enteros | `'between' debe ser un array de dos enteros, p. ej. [2, 20].` |
| `between` | min ≥ 0 | `El límite mínimo de 'between' no puede ser negativo; se recibió N.` |
| `between` | min ≤ max | `El límite mínimo de 'between' (N) no puede ser mayor que el máximo (M).` |
| `between` | requiere `operations` | `'between' solo tiene efecto en modo aritmético; defina 'operations'.` |
| `between` | `min` cabe en `length` dígitos (revisión en `generate()`; un `max` que desborda se recorta, no se rechaza) | `El rango de "between" [N, M] no cabe en un código de L dígitos (máx. X).` |
| `verifyAttempts` | entero ≥ 0 | `El límite de intentos de verificación no puede ser negativo; se recibió N.` |
| `generateAttempts` | entero ≥ 0 | `El límite de generación no puede ser negativo; se recibió N.` |
| `rateLimitWindow` | entero ≥ 1 | `La ventana del rate limit debe ser de al menos 1 segundo; se recibió N.` |
| `trustedProxies` | array de IPs válidas (exactas) | `'trustedProxies' debe contener IPs válidas.` |
| `storage` | `'auto'`/`'session'`/`'file'`/`'array'` | `'storage' no es válido; use "auto", "session", "file" o "array".` |
| archivo de config | debe `return array` | `El fichero de configuración debe devolver un array.` |

Las claves numéricas («integers»): si llega un tipo no numérico → `'X' debe ser un entero.`; si es texto no numérico → `'X' debe ser un entero.`. El `preset` no es texto → `'preset' debe ser texto.`. El `difficulty` con un tipo raro → `'difficulty' debe ser un texto o un valor Difficulty.`. Y los arrays: `'operations' debe ser un array.`, `'between' debe ser un array de dos enteros, p. ej. [2, 20].`, `'trustedProxies' debe ser un array de IPs.`

> Todos estos strings son los literales reales del paquete. Si quieres exactitud byte a byte, míralos en `src/Config/Config.php`.

---

## Cachés y redeclaración

El descubrimiento **lee el fichero una sola vez** por petición y guarda el `Config` ya construido: no hay una segunda pasada en la que un cambio posterior pueda colarse. Eso tiene tres consecuencias prácticas cuando la aplicación cachea su configuración:

- **opcache con `validate_timestamps=0`** (recomendado en producción): los cambios en `captcha.php` no se ven hasta que recargues opcache o reinicies PHP. Es el caso más frecuente de «he editado el config y no hace nada».
- **Laravel — `php artisan config:cache`**: el framework serializa sus ficheros de `config/` a un único array. Nuestro descubrimiento no participa de ese proceso (lee el fichero directamente), así que si cacheas la config de Laravel, la de `captcha` sigue leyéndose del disco tal cual está: sigue funcionando, pero la regeneración de la caché no la incluye.
- **Symfony — `bin/console cache:warmup`**: lo mismo, con la salvedad de que el config anclado con firma sigue pudiendo ser ignorado en silencio si se despliega sin él; `captcha doctor` lo reporta como aviso.

La otra cara es `Captcha::configure()`: fija las opciones en la capa estática **de por vida de la petición**, y `Captcha::reset()` solo suelta la instancia — las opciones manuales sobreviven. No es un mecanismo para mutar la configuración a mitad de petición: el config es declarativo. Fíjalo una vez (bootstrap, arranque de la app o el primer punto de entrada) y deja que `reset()` limpie la instancia sin tocarlo.

---

Siguiente: [Tipos de captcha](tipos-de-captcha.md) · [API](api.md) · [Integración](integracion.md) · [Consola](cli.md)
