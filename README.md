# captcha

[![Versión en Packagist](https://img.shields.io/packagist/v/andexer/captcha?style=flat&label=packagist)](https://packagist.org/packages/andexer/captcha)
[![Versión de PHP](https://img.shields.io/packagist/php/andexer/captcha?style=flat&color=777bb3)](https://packagist.org/packages/andexer/captcha)
[![Licencia](https://img.shields.io/packagist/l/andexer/captcha?style=flat&color=green)](LICENSE)
[![CI](https://github.com/andexer/captcha/actions/workflows/ci.yml/badge.svg)](https://github.com/andexer/captcha/actions/workflows/ci.yml)

Captcha numérico de imagen para **PHP 8.2+**, sin ninguna dependencia de runtime más allá de `ext-gd`. Genera retos de dígitos (o de operaciones aritméticas), los dibuja como PNG con GD, los guarda en sesión o en archivos y los verifica en el servidor con uso único y a prueba de repetición.

```
composer require andexer/captcha
```

> Repositorio: [github.com/andexer/captcha](https://github.com/andexer/captcha) · [CHANGELOG](CHANGELOG.md) · [Política de seguridad](SECURITY.md)

Todo *visible al usuario* — mensajes de verificación, errores, la consola y la interfaz del widget — está en **español**. El código interno y la API pública están en inglés (estándar de librerías PHP), pero los strings de feedback listos para mostrar son siempre en español.

---

## Características

| Área | Qué obtienes |
|---|---|
| **Cero dependencias** | Solo PHP >= 8.2 y `ext-gd`. No requiere doctrina, sesiones ajenas ni servicios externos. |
| **Widget listo para usar** | `Captcha::widget()` imprime imagen + campo + botón de recarga con su propio CSS/JS empaquetados e inyectados *inline* (compatible con CSP estricta). |
| **Dos llamadas y listo** | `Captcha::widget()` en el formulario y `Captcha::check()` en el controlador del POST. Un único archivo de configuración opcional. |
| **Captcha matemático** | El reto muestra `a + b`, `a − b`, `a × b` o `a ÷ b` y el usuario teclea el *resultado*. Dificultad graduable y rango de resultados (`between`). |
| **Tipografía GD** | Fuentes bitmap integradas de GD (1 a 5), tamaño del glifo configurable. Sin archivos TTF. |
| **Anti-spam de verdad** | Rate limit dual por IP + sesión **activo por defecto** (5 verify / 20 generate), fail-closed en web, honeypot opcional, dificultad y ruido/distorsión ajustables. |
| **Uso único garantizado** | Verificación atómica bajo concurrencia (`consume()`), comparación *timing-safe* con `hash_equals()` y TTL por reto. |
| **Almacenamiento intercambiable** | Sesión PHP por defecto, archivos, o tu propio backend vía `StorageInterface`. |
| **Sin superglobales esparcidas** | El contexto web entra solo por `Http\Globals`; `src/` no toca `$_SERVER`/`$_POST` (solo sesión y el script de arranque del endpoint). |

---

## Requisitos

- **PHP >= 8.2** (enums, `readonly`, `match`, `array_is_list`...).
- **`ext-gd`** con soporte PNG.
- **Composer 2** para instalar el paquete.

Comprobación rápida:

```bash
php -m | grep -i gd
php -r "var_dump(imagecreatetruecolor(10, 10));"
```

Si la extensión falta, cualquier render lanzará una `GdNotAvailableException` con un mensaje claro. `vendor/bin/captcha doctor` te ayuda a diagnosticar el entorno (ver [Consola](#consola)).

---

## Descargar e instalar

### 1. Composer

```bash
composer require andexer/captcha
```

El paquete se autoloada por PSR-4 (`Captcha\` → `src/`) y expone el binario `vendor/bin/captcha`.

#### Instalar desde el código fuente

Mientras el paquete no esté publicado en Packagist, o si quieres una versión que aún no has etiquetado, declara el repositorio antes de requerirlo:

```bash
composer config repositories.andexer/captcha git https://github.com/andexer/captcha.git
composer require andexer/captcha:@dev
```

`@dev` es obligatorio para las ramas de desarrollo: Composer solo acepta una versión sin etiqueta si se pide de forma explícita. En cuanto exista una etiqueta `vX.Y.Z`, lo normal es `composer require andexer/captcha:^1.0`.

### 2. Consola

```bash
vendor/bin/captcha install   # crea app/Config/captcha.php (plantilla comentada)
vendor/bin/captcha doctor    # comprueba PHP, GD, config descubierto y endpoint
vendor/bin/captcha help      # ayuda completa; también `help <comando>`
```

- `install` copia la plantilla del paquete a `app/Config/captcha.php`, **comentada**, para que descomentes solo lo que necesites. **Nunca sobrescribe** un archivo existente.
- `doctor` genera un reporte como este (nunca lanza errores):

```
captcha — doctor del entorno
[ok] PHP >= 8.2 (8.5.11 instalado)
[ok] ext-gd disponible (2.3.3)
[ok] Config descubierto: /ruta/del/proyecto/app/Config/captcha.php
[ok]     cargado  /ruta/del/proyecto/app/Config/captcha.php
[ok] Config efectivo: 6 dígitos, imagen 180x60 px, TTL 300s, dificultad medium, ruido sí, distorsión sí
[ok] Storage efectivo: sesión (SessionStorage) (opción 'storage' = "auto")
[ok] Rate limit de verificación: 5 intentos / 300s
[ok] Rate limit de generación: 20 captchas / 300s (HTTP 429)
[!!] Honeypot desactivado
[ok] Rate limit por IP: activo (dual IP + sesión)
[..] Proxies de confianza: ninguno (X-Forwarded-For ignorado)
[..] Host: PHP plano / CLI (auto-storage = sesión)
[!!] Resumen: 1 aviso, 2 aviso informativo, 9 correcto

Siguientes pasos: Captcha::configure([...]) o Captcha::configure(new Config(...)) (o un captcha.php descubierto), Captcha::check() en el POST y Captcha::widget() dentro del <form>.
```

Eso es todo: instalación completa = `composer require` + (opcional) un archivo `.php` de configuración.

---

## Cómo funciona: el flujo completo

```
┌─ NAVEGADOR ─────────────────────────────┐        ┌─ SERVIDOR (PHP) ───────────────────────────────┐
│                                         │        │                                                │
│  GET /formulario                        │──>────▶│  Captcha::widget()                              │
│                                         │        │   ├─ NumericGenerator::generate(length)        │
│  El widget pinta la imagen (data-URI)   │        │   │   → "47391" o "12*3"                       │
│  + campo oculto (id) + input + recarga  │        │   ├─ GdRenderer::render(texto, config) → PNG   │
│                                         │        │   └─ storage->put(id, codigo, ttl)             │
│  El <img> lleva la imagen como data-URI │◀───────│      (el usuario solo conoce el id, jamás el   │
│  y el <input type="hidden"> el id      │        │       código)                                  │
│                                         │        │                                                │
│  "Recargar" → GET endpoint?action=      │──>────▶│  Endpoint::dispatch('generate') → JSON 200     │
│  generate                              │        │   (nuevo id + nueva imagen; se reconsume el    │
│                                         │        │    presupuesto de generación)                  │
│  POST (submit) con captcha_id + captcha │──>────▶│  Captcha::check()                              │
│                                         │        │   ├─ (honeypot lleno?) → Bloqueado            │
│  Mensaje en español + widget nuevo      │◀───────│   ├─ rate limit verify? → Bloqueado            │
│  (el reto ya quedó consumido)           │        │   ├─ storage->consume(id) → codigo             │
│                                         │        │   ├─ hash_equals(codigo, trim(input))?        │
│                                         │        │   └─ Resultado: correcto / incorrecto /        │
│                                         │        │      caducado / no encontrado / bloqueado      │
└─────────────────────────────────────────┘        └────────────────────────────────────────────────┘
```

### Reglas de seguridad en las que se apoya

1. **El id no es el código.** Al cliente solo viaja un identificador de 128 bits (`random_bytes(16)` en hexadecimal). El código permanece siempre en el almacén del servidor.
2. **Uso único real.** `verify()` consume el reto **atómicamente** (lectura + borrado bajo `flock` en `FileStorage`, borrado directo en sesión), de modo que bajo concurrencia exactamente una petición puede obtener el código. Reintentar con el mismo id responde «no encontrado».
3. **Comparación *timing-safe*.** El `hash_equals()` contra `trim($input)` no filtra información por tiempos de respuesta.
4. **Todo server-side.** La validación ocurre en `Captcha::verify()`/`verifyRequest()`; el widget solo dibuja y transporta el id.
5. **Widget idempotente por petición.** Dos llamadas a `widget()` en el mismo request reutilizan el mismo reto: un form renderizado desde un partial o un layout nunca invalida el código ya emitido.
6. **Sin superficies de abuso.** Un código de más de 32 bytes se rechaza sin consumir el reto; el honeypot se evalúa antes de verificar; el rate limit se consulta antes de `has()`/`consume()` (bloqueado ⇒ el reto no se gasta).
7. **El paquete no debilita tu sesión.** Las sesiones que arranca él mismo piden `HttpOnly` + `SameSite=Lax` (+`Secure` por HTTPS) sin preemptar nunca las de un framework que gestione la sesión.

---

## Inicio rápido (3 pasos)

Un formulario con captcha en dos llamadas y un archivo de configuración opcional:

```php
<?php // controlador.php
require 'vendor/autoload.php';

use Captcha\Captcha;

// 1) Backend: ¿llegó un POST? ¿pasó? Feedback en español.
//    En GET responde "idle" sin tocar el almacén; en POST verifica y
//    consume el reto una sola vez.
$check = Captcha::check();

// captura el techo anti-flood (generateAttempts > 0): la página no debe
// estallar en una excepción, sino mostrar feedback.
try {
    $widget = Captcha::widget();
} catch (\Captcha\Exception\RateLimitException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<body>
    <?php if ($check->error !== null): ?>
        <p class="bad"><?= htmlspecialchars($check->error, ENT_QUOTES) ?></p>
    <?php elseif ($check->passed): ?>
        <p class="ok">Código captcha correcto.</p>
    <?php else: ?>
        <form method="post">
            <?= $widget ?>                  <!-- 2) Frontend: imagen + campo + recarga -->
            <p><button type="submit">Enviar</button></p>
        </form>
    <?php endif; ?>
</body>
</html>
```

`widget()` inyecta el CSS/JS empaquetados la primera vez, así que **no hay nada que servir**: tu docroot ni se entera.

---

## Configuración

### La capa estática y cómo se carga

El paquete ofrece una **capa estática** con auto-configuración (para la mayoría de los casos) y la **instancia manual** con dependencias inyectadas (para casos avanzados).

- `Captcha::instance()` construye (y cachea por petición) el singleton con el config descubierto.
- `Captcha::reset()` descarta el singleton.
- `Captcha::configure([...])` configura la capa estática **por programa**, sin necesitar archivo; acepta un array de opciones **o una instancia ya armada de `Config`** (`new Config(...)`, `Config::forLogin()`, `Config::builder()->...->build()`). Una llamada a `configure([])` libera la configuración manual y devuelve el control al descubrimiento.
- `Captcha::check()` / `widget()` / `valid()` / `message()` / `submitted()` funcionan igual como estáticos (sobre el singleton) que como métodos de instancia.

### Descubrimiento del config (solo para la capa estática)

Primer acierto gana; si ninguno existe se usan los valores por defecto:

```text
1. getenv("CAPTCHA_CONFIG")
2. <raíz del proyecto>/app/Config/captcha.php
3. <raíz del proyecto>/config/captcha.php
4. <raíz del proyecto>/etc/captcha.php
5. <cwd>/app/Config/captcha.php
6. <cwd>/config/captcha.php
7. <cwd>/etc/captcha.php
```

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
proyecto (2–4 del orden) son *opt-in*: solo se aceptan si el fichero lleva
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

> **Consejo:** usa la misma vía para formulario y endpoint. El endpoint de recarga debe ir sobre `Captcha::instance()`, de modo que comparte *por construcción* la misma configuración y el mismo backend de retos que el formulario — nunca hay dos configs que mantener sincronizadas.

> **Out-of-the-box en frameworks:** cuando el paquete detecta un framework que
> gestiona la sesión PHP (CodeIgniter, Laravel, Symfony, **CakePHP, Yii** —
> clases kernel ya cargadas), el storage por defecto pasa automáticamente de
> sesión a **archivos** en `sys_get_temp_dir()/captcha` y el limiter de
> retry se queda solo-IP.
> El captcha funciona con cero configuración y **jamás preempta la sesión del
> host** (los handlers de CodeIgniter/Laravel llaman a `ini_set()` de sesión al
> construirse: arrancar la sesión antes los rompe). Fuera de framework sigue el
> legacy `SessionStorage`. Forza explícito con `'storage' => 'session'|'file'|'array'`.

### Opción A — copiar la plantilla

```bash
vendor/bin/captcha install                         # PHP plano / CodeIgniter 4 → app/Config/captcha.php
vendor/bin/captcha install laravel                # Laravel → config/captcha.php
vendor/bin/captcha install --framework=symfony     # Symfony → config/packages/captcha.php
```

Genera el config inicial del framework indicado (**`plain`, `codeigniter`, `laravel`, `symfony`, `cakephp`, `yii`, `janssen`**) en su ruta canónica, con **todas** las opciones documentadas: es un `return []` activo, así que el fichero es válido desde el primer segundo. En las seis primeras cada clave va comentada junto a su default y basta descomentar la que necesites; la de Janssen llega con los valores ya activos. **Nunca sobrescribe** un fichero existente. Las plantillas se generan a partir del fragmento compartido de opciones (`src/app/Config/fragments/options.php`) — regenéralas con `composer config:templates` tras editarlo; las copias distribuidas viven en `src/app/Config/templates/` y ya llevan la firma del anclaje. Ver [Integración por framework](#integración-por-framework).

### Opción B — armar tu propio array

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
    // El código es válido durante 5 minutos.
    'ttl' => 300,
    // Dificultad media (operandos de hasta 99 en modo aritmético).
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

### Atajo de paquete (`preset`)

Un grupo de opciones listas para cada caso. **Las claves explícitas ganan** sobre las del preset (la unión se resuelve con precedencia a la izquierda), así que `preset` + retoques conviven bien:

| Preset | Concepto | Qué fija |
|---|---|---|
| `default` | Defaults del constructor | Nada (se usa el valor de cada clave por defecto). |
| `login` | Formularios de acceso | 5 dígitos, 200×60, TTL 300, dificultad baja, sin ruido ni distorsión, rate limit 5 verify / 30 generate. |
| `strict` | Máxima dureza anti-spam | 6 dígitos, 220×64, TTL 180, dificultad alta, con ruido y distorsión, 5 verify / 20 generate, honeypot activo (`website`). |

### Tabla completa de opciones

Todas son **opcionales**. Las que aceptan número también aceptan su texto numérico (p. ej. `'6'`).

| Clave | Default | Acepta | Descripción |
|---|---|---|---|
| `length` | `6` | entero **3…10** | Dígitos del código. Más dígitos = más seguridad, menos legibilidad. |
| `width` | `180` | entero 1…5000 | Ancho de la imagen en píxeles. |
| `height` | `60` | entero 1…2000 | Alto de la imagen en píxeles. |
| `ttl` | `300` | entero ≥ 1 | Segundos que el código permanece válido. |
| `output` | `'png'` | `'png'` | Formato de imagen (por ahora solo PNG). |
| `difficulty` | `'medium'` | `'low'`/`'medium'`/`'high'` (o enum `Difficulty`) | Gradúa ruido, distorsión, inclinación de glifos y —en modo aritmético— el tamaño de los operandos. |
| `font` | `5` | entero **1…5** | Fuente bitmap integrada de GD para los glifos. **Sin TTF nunca.** |
| `fontSize` | `null` | `null` o entero ≥ 1 | Altura objetivo del glifo en píxeles; `null` la deriva del lienzo (~78 % del alto, tope ×4). |
| `noise` | `true` | bool | Dibuja puntos y líneas aleatorios sobre la imagen. |
| `distortion` | `true` | bool | Aplica una distorsión de onda horizontal. |
| `idField` | `'captcha_id'` | texto no vacío | Nombre del campo oculto que transporta el id del reto. Es la **única fuente de verdad** para el widget y para `verifyRequest()`. |
| `inputField` | `'captcha'` | texto no vacío | Nombre del campo donde el usuario teclea el código. |
| `injectAssets` | `true` | bool | Inyecta el CSS/JS empaquetados con el primer `widget()`. Con `false`, enlázalos tú mismo (CSP estricta) vía `Captcha::assets()` o `$captcha->assets()`. |
| `operations` | `[]` | array | Operaciones aritméticas habilitadas (ver [Modo aritmético](#modo-aritmético-captcha-matemático)). Vacío = dígitos clásicos. |
| `between` | `null` | `[min, max]` | Rango de resultados aritméticos inclusivo; **requiere** `operations`. |
| `verifyAttempts` | `5` | entero ≥ 0 | Máx. verificaciones por clave y ventana; `0` = desactivado. Superado → `verify()` devuelve «bloqueado» sin consumir el reto. Solo aplica en el SAPI web. |
| `generateAttempts` | `20` | entero ≥ 0 | Máx. captchas generados por clave y ventana (frena el flood de CPU con GD); `0` = desactivado. Superado → el endpoint responde **HTTP 429**. Solo aplica en el SAPI web. |
| `rateLimitWindow` | `300` | entero ≥ 1 | Anchura en segundos de la ventana del rate limit (común a verify y generate). |
| `honeypot` | `false` | bool | Renderiza un campo trampa invisible; si llega con contenido, la petición se rechaza antes de verificar. |
| `honeypotField` | `'email'` | texto no vacío | Nombre del campo trampa. **Debe ser distinto de los campos reales** del formulario. |
| `rateLimitByIp` | `true` | bool | Incluye la IP del cliente en la clave del límite. En PHP plano el limiter es dual IP+sesión; bajo un framework que gestione la sesión (ver `storage`) se queda solo-IP. `false` = solo sesión. |
| `trustedProxies` | `[]` | lista de IPs válidas | IPs exactas (sin rangos/CIDR) autorizadas a pasar `X-Forwarded-For`. Vacío = la cabecera se ignora. |
| `storage` | `'auto'` | `'auto'`/`'session'`/`'file'`/`'array'` | Backend de los retos. `'auto'` elige en runtime: `file` bajo un framework que gestione la sesión PHP, `session` en PHP plano/CLI; nunca toca la sesión del host sin que se lo pidas. Los otros valores fuerzan ese backend. |

> El bloque marcado «1b» de la plantilla (`src/app/Config/captcha.php`) es el mismo array completo comentado, listo para copiar y pegar.

### Config desde PHP: modo estricto, merge y builder

`Config` es un **value object `final readonly`**, y los arrays se validan al construir. La misma API te sirve para configurar desde PHP sin ningún fichero:

- **`Config::fromArray(array $opts, bool $strict = true)`** — valida tipos y rangos. Por defecto es **estricto**: una clave no reconocida lanza `InvalidConfigException` en español (`Claves de configuración no reconocidas: "verifyAtempts".`) — un typo nunca desactiva silenciosamente el rate limit ni cae en defaults. Pasa `strict: false` si prefieres ignorar lo desconocido (compatibilidad).
- **`Config::toArray()`** — exporta el config efectivo (preset ya expandido, `operations` como valores canónicos `add|subtract|multiply|divide`, `difficulty` como `low|medium|high`), simétrico con `fromArray()` para poder serializar/deserializar sin pérdida.
- **`Config::merge(array|Config $overrides)`** — inmutable: devuelve un `Config` nuevo aplicando un parche (array o `Config`) sobre el actual, revalidado. Una clave explícita del parche o de la base gana siempre; un `preset` del parche solo rellena los huecos que la base no cubre. Sirve para combinar un presets con overrides de entorno:
  ```php
  $config = Config::forLogin()->merge(['verifyAttempts' => 10, 'length' => 7]);
  ```
- **Fábricas**: `Config::defaults()` (= `new Config()`), `Config::forLogin()` y `Config::forStrict()` (equivalen a `fromArray(['preset' => ...])`, listas para `merge()`).
- **`Config::builder()`** — API fluida y tipada (los setters se llaman igual que las claves): `Config::builder()->length(5)->operations(['+','-'])->verifyAttempts(5)->build()`. Acepta `preset()` y `from()` (un array, un `Config` o otro builder); `build()` valida vía `fromArray()` estricto y devuelve el `Config` inmutable.

Todos los caminos convergen en el mismo `Config` validado, así que el fichero, `configure()`, el constructor y el builder no pueden divergir:

```php
// Lo mismo por las cuatro vías:
new Config(length: 5, difficulty: 'low');
Config::fromArray(['preset' => 'login']);
Config::builder()->preset('login')->build();
Captcha::configure(Config::forLogin()->merge(['length' => 5]));
```

---

## Tipos de captcha

### Dígitos clásicos

Por defecto (`operations` vacío u omitido), `NumericGenerator` produce un código de `length` dígitos con CSPRNG (`random_int`). La imagen muestra los dígitos, cada uno con *jitter* vertical y una inclinación aleatoria según la dificultad.

### Modo aritmético (captcha matemático)

Con `operations` no vacío, la imagen muestra `a + b` (o `−`, `×`, `÷`) y el código a teclear es el **resultado numérico**. El generador garantiza:

- **Resta no negativa** (`a >= b` siempre).
- **División exacta** (divisor ≥ 2).
- **Techo por longitud**: el resultado nunca excede `10^length − 1`, así que el usuario nunca teclea más dígitos que el `maxlength` del campo.
- **Dificultad** gradua los operandos: `low` hasta 9 (1 dígito), `medium` hasta 99 (2 dígitos), `high` hasta 999 (3 dígitos).
- **CSPRNG con *rejection sampling***: si un intento no cumple las invariantes, se reintenta (máx. 200); si es imposible, `InvalidConfigException` en español, nunca una imagen rota.

`operations` acepta **4 formatos equivalentes**, normalizados a la misma `list<Operation>`:

```php
// 1. Símbolos (ASCII; '×' y '÷' se aceptan como alias de entrada)
['+', '-']                 // 'add'|'sum'|'addition' · 'sub'|'subs'|'subtract'|'subtraction'
'*', '/'                   // alias: 'mul'|'multiply'|'multiplication' · 'div'|'divide'|'division'

// 2. Nombres (abreviados o completos; los valores canónicos del enum
//    — add | subtract | multiply | divide — son los que exporta toArray())
['add', 'subtract']
['addition', 'subtraction', 'multiplication', 'division']

// 3. Casos del enum (simetría con difficulty)
[\Captcha\Config\Operation::Add, \Captcha\Config\Operation::Subtract]

// 4. Mapa booleano (claves desconocidas se ignoran)
['addition' => true, 'multiplication' => false]
```

En los formatos de **lista**, todo valor debe reconocerse: una errata (`'adittion'`) lanza `InvalidConfigException`, **nunca** un fallback silencioso a dígitos. La imagen siempre se dibuja en ASCII (`+`, `-`, `*`, `/`): la fuente bitmap de GD no tiene glifos para `×`/`÷`.

### `between`: acotar los resultados

`between => [min, max]` encierra **todo** resultado aritmético en el rango inclusivo, además del techo de `length` (gana el límite más restrictivo). Ejemplo: `['+', '-']` con `between => [2, 20]` → solo sumas y restas cuyo resultado esté entre 2 y 20.

- Exige `0 <= min <= max`.
- **Requiere** `operations`: en modo dígitos lanza `InvalidConfigException` (nunca se ignora en silencio).
- Si el rango no cabe en `length` dígitos: `El rango de "between" [min, max] no cabe en un código de N dígitos (máx. M).`
- **También confina los operandos al entorno del rango**: techo `max + (max - min) / 4`, jamás por encima del techo que marca `difficulty`. Con `[0, 20]` los operandos quedan ≤ 25; sin esta regla una resta de `[0, 20]` podía pintar `3962 − 3962` (resultado válido, imagen ilegible).

### El tamaño de los operandos lo marca `length`, no la suerte

El generador **no** elige operandos al azar dentro de todo el espacio numérico: los acota a un techo derivado de `length` (`10^length - 1`), de modo que la longitud que pides es la que compras. Cuando configuras `between`, ese techo se repliega al entorno del rango (ver § anterior) y `difficulty` solo manda si su banda es aún más estrecha. Dentro del techo efectivo, `difficulty` reparte el rango:

| `difficulty` | Techo de operandos | Para `length: 6` |
|---|---|---|
| `low` | `max(9, √techo)` | ~999 → sumas de hasta 4 dígitos |
| `medium` (default) | `max(9, techo / 10)` | ~99 999 → resultados de hasta 6 dígitos |
| `high` | `techo / 2` | ~499 999 |

Por qué importa: con `add` en `medium` y operandos libres, el espacio de respuestas se cerraba en 197 valores para `length: 6` — la longitud nominal mentía y un atacante podía enumerarlo entero. Acotando los operandos, el espacio crece con `length` y la dificultad solo ajusta cuánta variedad hay.

### Tipografía y tamaño (fuentes bitmap de GD)

El renderer usa `imagechar()` con las **fuentes bitmap integradas de GD** (1 a 5). No hay TTF: no puedes cargar un archivo de fuente propio, y no hace falta.

| `font` | Descripción |
|---|---|
| 1 | `small` — la más pequeña |
| 2 | `normal` |
| 3 | `medium bold` — negrita |
| 4 | `grande` |
| 5 | `large` — la mayor, **default** |

El tamaño del glifo:

- `fontSize => null` (default): la escala entera se deriva del lienzo — el glifo apunta a **~78 % del alto** de la imagen, con techo **×4** y **recorte al presupuesto horizontal** (el código jamás se sale de la imagen).
- `fontSize => 35` (px): `35 / altoDeLaFuente` redondeado a la escala entera (mínimo ×1), también recortada al ancho del lienzo.

Las métricas exactas de cada fuente se leen de GD **en tiempo real** (`imagefontwidth()`/`imagefontheight()`), porque varían según el build de GD — no las hardcodees. Los glifos se escalan por factor entero con vecino más cercano (bordes nítidos, sin grises borrosos) y, si hay ruido activo y el glifo no está rotado, se pinta una sombra de 1 px que le separa del fondo oscuro.

---

## Validaciones

Toda opción se valida **al construir** el `Config` («inmutable»): un valor inválido lanza `InvalidConfigException` (hija de `CaptchaException`) y la instancia jamás se observa en un estado inválido. En arrays, las **claves desconocidas lanzan** `InvalidConfigException` por defecto (modo **estricto**, para que un typo no desactive silenciosamente protección alguna); con `Config::fromArray($opts, strict: false)` se ignoran, como hacía la versión anterior.

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
| `between` | cabe en `length` dígitos | `El rango de "between" [N, M] no cabe en un código de L dígitos (máx. X).` |
| `verifyAttempts` | entero ≥ 0 | `El límite de intentos de verificación no puede ser negativo; se recibió N.` |
| `generateAttempts` | entero ≥ 0 | `El límite de generación no puede ser negativo; se recibió N.` |
| `rateLimitWindow` | entero ≥ 1 | `La ventana del rate limit debe ser de al menos 1 segundo; se recibió N.` |
| `trustedProxies` | array de IPs válidas (exactas) | `'trustedProxies' debe contener IPs válidas.` |
| archivo de config | debe `return array` | `El fichero de configuración debe devolver un array.` |

Las claves numéricas («integers»): si llega un tipo no numérico → `'X' debe ser un entero.`; si es texto no numérico → `'X' debe ser un entero.`. El `preset` no es texto → `'preset' debe ser texto.`. El `difficulty` con un tipo raro → `'difficulty' debe ser un texto o un valor Difficulty.`. Y los arrays: `'operations' debe ser un array.`, `'between' debe ser un array de dos enteros, p. ej. [2, 20].`, `'trustedProxies' debe ser un array de IPs.`

> Todos estos strings son los literales reales del paquete. Si quieres exactitud byte a byte, míralos en `src/Config/Config.php`.

---

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
    ?StorageInterface     $storage = new SessionStorage(),
    Config                $config  = new Config(),
    GeneratorInterface    $generator = new NumericGenerator(),
    RendererInterface     $renderer = new GdRenderer(),
    ?RateLimiterInterface $rateLimiter = null,
)
```

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

### El widget

```php
<?= \Captcha\Captcha::widget(['endpoint' => '/ruta/al/endpoint.php']) ?>
```

Opciones del widget (`WidgetOptions`, ambas opcionales):

- `endpoint` — URL que devuelve `{ok, image, id}` para el botón de recarga. Default: `/captcha/endpoint`.
- `theme` — `'light'` (default), `'dark'` o `'auto'` (sigue `prefers-color-scheme`). Cualquier otro valor cae a `light`.

El HTML que emite es **estático** (sin script inline propio): CSS/JS empaquetados (`captcha.min.css`/`.min.js`) se inyectan inline la primera vez, o los sirves tú con `Captcha::assets('url', $baseUrl)`. El JavaScript solo recarga la imagen; si el endpoint falla (404, red, cuerpo ilegible) **recarga la página** como último recurso; un error real del endpoint con `data-error` (p. ej. un 429) se muestra sin recargar.

Si `generate()` queda limitado por tasa durante un render, el widget **degrada** a un estado de error (banda con el mensaje, sin imagen/campo/reto) en vez de romper tu plantilla; el endpoint de recarga conserva su `429` real.

### El endpoint de recarga (AJAX)

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

## Rate limiting, honeypot y endurecimiento

### Rate limit (anti-flood y anti-brute-force)

- Default **activo**: `verifyAttempts` = **5** y `generateAttempts` = **20** por ventana de 300 s. Es un techo *out-of-the-box* pensado para que un forgot-password no se convierta en un oráculo de fuerza bruta, sin que tengas que tocar el config. Ponlo a `0` para desactivarlo (o súbelo si tu tráfico legítimo es mayor).
- **Limiter dual por defecto** (con `rateLimitByIp: true`): `IpRateLimiter` (contadores en archivos JSON por IP en `sys_get_temp_dir()/captcha-limits`, con `flock`) **y** `SessionRateLimiter` (contadores en `$_SESSION['_captcha_limits']`), combinados por `CompositeRateLimiter` como un **AND sin cortocircuito** — por qué importa: si un backend ya rechaza, no quieres que el otro quede intacto para un atacante que limpia galletas.
- Los ficheros por IP cierran el hueco de las peticiones **sin cookies** (curl, bots), que la sesión no puede cerrar.
- **Fail-closed deliberado**: sesión inactiva, fichero corrupto/no escribible o IP de cliente ausente ⇒ el intento se **rechaza** (verify → `blocked` sin consumir; generate → `RateLimitException` → 429). Un limiter que no puede contar jamás abre la puerta.
- **Fuera del SAPI web no se cuenta nada** (`NullRateLimiter`): un proceso CLI es tu propio código de confianza y no tiene cliente al que atribuirle intentos. Una bolsa por PID no frenaría a nadie (un proceso nuevo empieza con el contador a cero) y sí mataría a un worker legítimo a los 20 captchas. El fail-closed de arriba es cosa del caso web, que es el único con un cliente remoto.
- **Claves por operación**: `generate:<cliente>` y `verify:<cliente>` son bolsas independientes (subir el captcha no gasta intentos de verificación ni al revés).
- **0 = off**, y el limiter se crea **lazy**: si ningún límite está activo, la instancia por defecto jamás arranca una sesión ni toca el filesystem.
- Los bloqueados **siguen contando**, y la ventana se resetea al expirar.

### IP del cliente y proxies de confianza

La clave por defecto es `Globals::clientIp($config->trustedProxies)`. `X-Forwarded-For` **nunca se confía por defecto**: con `trustedProxies` vacío solo se usa `REMOTE_ADDR`, así que un cliente no puede rotar su cubeta falseando la cabecera. Solo si `REMOTE_ADDR` coincide con un proxy declarado se recorre la cabecera de derecha a izquierda hasta el primer salto no confiable (fallback a `REMOTE_ADDR`). Las IPs deben ser **exactas** (sin CIDR) y se normalizan las `::ffff:a.b.c.d`.

`rateLimitByIp: false` restaura el modo solo-sesión (para hosts que garantizan una). También puedes inyectar tu propio `RateLimiterInterface` (Redis, APCu, DB...) como quinto argumento del constructor.

### Honeypot (trampa anti-bots)

Con `honeypot: true`, el widget emite `<span class="ct__honeypot">` con un input invisible (fuera de vista, no `display:none`, para que el autofill lo encuentre). `verifyRequest()` rechaza cualquier petición cuyo `honeypotField` llegue con contenido: `Status::Blocked`, `La petición parece automatizada.`

> **Aviso:** el campo por defecto es `'email'`. En un formulario de login que ya tenga un campo `email`, activar el honeypot sin cambiar `honeypotField` rompería el formulario (¡los humanos también lo rellenan!). Cámbialo a un nombre inexistente en tu formulario, p. ej. `'website'` (como hace el preset `strict`).

### Endurecimiento activo por defecto

- **Input sobredimensionado**: `verify()` rechaza cualquier código de más de **32 bytes** (`Status::Invalid`) **sin consumir el reto** — un código numérico/aritmético ocupa pocos bytes, así que una cadena kilométrica no es una respuesta genuina; el intento sí cuenta para el rate limit. El widget acota la entrada con `maxlength`, así que un humano nunca llega ahí.
- **Cookie de sesión del paquete**: en PHP plano / CLI, las sesiones que el paquete arranca él mismo (no las de tu framework) piden `HttpOnly` + `SameSite=Lax`, y **`Secure`** cuando el cliente conecta por HTTPS. Si tu app ya configuró la cookie, o un framework gestiona la sesión, el paquete **no toca nada** (las decisiones del host se respetan: `src/Http/SessionCookie.php`).
- El endpoint JSON responde con `X-Frame-Options: DENY` y `Referrer-Policy: no-referrer` (ver [El endpoint de recarga](#el-endpoint-de-recarga-ajax)).

### CaptchaGuard: capa de verificación para middlewares

`CaptchaGuard` (`src/Security/`) es la capa pura que decide si una petición cruza el captcha, sin atarse a ninguna interfaz de framework (cero dependencias):

```php
use Captcha\Captcha;
use Captcha\Security\CaptchaGuard;

$guard = new CaptchaGuard(Captcha::instance());
$decision = $guard->decide($requestFields, requireSubmission: true);
```

- `decide(array $data, bool $requireSubmission = false): GuardDecision` es **pura** (no hace I/O): recibe los campos del POST y devuelve una decisión; `requireSubmission: true` hace que un POST que se **salta** el campo oculto sea rechazado con 422 (no se cuela), mientras que con `false` un GET / primera visita devuelve `idle` (la petición fluye y el widget muestra el reto).
- ⚠️ **Con el `requireSubmission` por defecto (`false`), un id vacío responde SIEMPRE `idle` → `allowed`.** El guard es puro y no ve el método HTTP: si le pasas el body de un POST *sin* `requireSubmission: true`, esa petición **cruza sin captcha**. Por eso toda receta pasa `true` en el POST real. (`Captcha::check()` no tiene esta trampa: él mismo comprueba `isPost` antes de verificar).
- `GuardDecision` (inmutable) expone `allowed`, `idle`, `status` (`Status`), `httpStatus` y `message` (feedback fijo en español). Mapeo: `ok` → 200; `invalid`/`expired`/`missing` → 422; `blocked` (rate limit u honeypot) → 429.
- `vendor/bin/captcha install` emite ese pegamento por ti: **CodeIgniter** (Filter), **Laravel** (middleware), **Symfony** (listener de `kernel.request`), **CakePHP** (middleware PSR-15), **Yii** (action filter), **Janssen** (preprocesador) y **PHP puro** (drop-in) — todos con el mismo esqueleto: GET pasa, POST verifica y trunca con `httpStatus` + `message`. El instalador escribe los ficheros y, al terminar, imprime los pasos de registro que no puede automatizar (registrar un provider, etiquetar un listener, publicar una ruta).

---

## Referencia

### Almacenamiento

| Clase | Dónde vive el reto | Cuándo elegirla |
|---|---|---|
| `SessionStorage` (legacy) | `$_SESSION['_captcha']` | PHP plano / CLI. Arranca la sesión de forma **diferida**, en el primer acceso real (construir la clase nunca preempta la sesión del host). |
| `FileStorage` | Un JSON por reto, nombre = `sha256(id)` | **Default `'auto'` bajo frameworks** que gestionan la sesión (CodeIgniter, Laravel, Symfony, CakePHP, Yii, Janssen): funciona sin tocar la sesión del host. También host sin sesiones o multi-nodo con directorio común. `consume()` con `flock` = anti-replay real bajo concurrencia. |
| `ArrayStorage` | Memoria del proceso | CLI, colas, tests (reloj inyectable, sin `sleep()`). |

El storage efectivo de la capa estática se elige con la opción `storage`
(`'auto'` = el detector de host decide; ver la [tabla de opciones](#tabla-completa-de-opciones)).
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

### Arquitectura y diseño

- `src/Captcha.php` es la **fachada** (no implementa generación ni render); el estado global vive solo en la capa estática (`Runtime\StaticLayer`, `@internal`), que PHP resetea en cada petición. El flujo de verificación por petición se unifica en `Request\RequestFlow` (`@internal`) y el widget se renderiza sobre un view model (`Widget\WidgetModel`, `@internal`) en lugar de la fachada.
- Sin superglobales esparcidas: el acceso a `$_SERVER`/`$_POST` vive únicamente en `Http\Globals` (aislado y testeado); `SessionStorage`/`SessionRateLimiter` tocan `$_SESSION`.
- `Config` es `final readonly`: inmutable y validado al construir. Value objects (resultados) también `readonly`.
- El renderer (`GdRenderer`) es el único que toca GD, y sus métricas de fuente se leen de GD en tiempo real (no hardcodeadas: varían por build).
- Widget en Grid CSS (filas imagen+campo jamás se rompen), `color-scheme: light dark` con override `data-theme` (default `light`), anillo de foco suave, botón de recarga sobre el borde derecho de la imagen (nunca en esquinas: los dígitos a escala alta lo taparían).

---

## Ejemplos y demos

Los ejemplos de uso del paquete (`src/examples/`) están disponibles en el repositorio para desarrollo, pero **no se distribuyen** con el paquete instalado. Si clonas el repo, puedes ejecutarlos sirviendo la carpeta con cualquier servidor web + PHP:

| Archivo | Qué demuestra |
|---|---|
| `index.php` | Demo completa: widget de la capa estática, probador interactivo (tipos de operación, dificultad, ruido, longitud) y galería de variantes `?render=`. |
| `01-basic.php` | Uso mínimo: `generate()` y servir el PNG sin widget. |
| `02-form.php` | Formulario end-to-end con las dos llamadas (`check()`/`widget()`) y captura del 429. |
| `03-file-storage.php` | `FileStorage` sin sesiones; ciclo completo generar → verificar → reintentar (consumido). |
| `04-rest-api.php` | API REST mínima: `GET` genera, `POST id+code` verifica. |
| `05-janssen-login.php` | **Receta no ejecutable** de integración en un CMS con front controller (p. ej. Janssen): un config + cuatro trozos de código (widget, check, endpoint, truncar si no pasa) + el gotcha del *friendly-path* de las rutas GET con query. |
| `simple.php` | Formulario simple de dígitos con `Config` construido a mano. |
| `matematico.php` | Captcha matemático suma/resta con selectores en vivo. |

Las ejecutables llevan `generateAttempts: 0` (o capturan `RateLimitException`) para que puedas recargarlas sin parar: el techo anti-flood real (20 generaciones por IP y ventana) es una feature de seguridad, no un fallo de la demo. En tu app déjalo activo y traduce la excepción a un 429.

---

## Integración por framework

El paquete es agnóstico: cada framework solo aporta la **ruta del config** y su propia forma de exponer las rutas. La plantilla de cada uno está lista en `src/app/Config/templates/` con la **sintaxis *clean code*** completa (secciones `───`, bloques de explicación y cada opción comentada junto a su default); el config resultante es siempre un `return []` activo, válido desde el primer segundo: en las seis primeras, con cada clave comentada junto a su default; en Janssen, con los valores ya activos. Para proteger rutas como capa HTTP, `install` escribe el pegamento de `CaptchaGuard` propio de cada framework y te indica dónde registrarlo ([CaptchaGuard](#captchaguard-capa-de-verificación-para-middlewares)).

| Framework | `install --framework=` | Config generado | Dónde se carga |
|---|---|---|---|
| PHP plano / cualquier app | `plain` | `app/Config/captcha.php` | Descubrimiento automático (raíz de instalación o cwd). |
| CodeIgniter 4 | `codeigniter` | `app/Config/captcha.php` | Descubrimiento automático desde `public/` (docroot). |
| Laravel | `laravel` | `config/captcha.php` | Descubrimiento automático (`config/captcha.php` es ancla), o el provider que lo carga. |
| Symfony | `symfony` | `config/packages/captcha.php` | Descubrimiento automático (`config/packages/captcha.php` es ancla); si tu bundle lo carga por su cuenta, enlázalo con `Captcha::configure(...)` y ambos caminos sirven. |
| CakePHP 4 | `cakephp` | `config/captcha.php` | Descubrimiento automático (array plano, como el resto); no necesitas tocar `Configure`. |
| Yii 2 | `yii` | `config/captcha.php` | Descubrimiento automático (`config/captcha.php` es ancla), o el `require` + `Captcha::configure(...)` del bootstrap. |
| Janssen | `janssen` | `app/Config/captcha.php` | Descubrimiento automático (`app/Config/captcha.php` es ancla). Es la única plantilla que llega con los valores **activos** en vez de comentados. |

Las siete rutas que emite `install` son anclas del descubrimiento, así que el config que escribe se carga solo en los siete casos. La lista de anclas vive en `Runtime\StaticLayer` y es la fuente de verdad: si un framework nueva usara otra ruta, habría que añadirla ahí, no en el instalador. Las anclas de raíz exigen la firma `// captcha config v2`; las del cwd, no.

El patrón de integración es el mismo para todos (`install` lo materializa por framework y la receta `05-janssen-login.php` lo muestra para un CMS):

1. **Config descubierto o `Captcha::configure(...)`**: el config (array o `Config`) alimenta la capa estática; si el host es un framework que gestiona la sesión PHP (CodeIgniter, Laravel, Symfony, **CakePHP, Yii, Janssen**), el storage por defecto ya es `FileStorage` y el limiter solo-IP, sin tocar la sesión del host.
2. **Backend**: `Captcha::check()` en tu controlador de POST.
3. **Frontend**: `Captcha::widget()` dentro del `<form>` (idempotente, inyecta CSS/JS la primera vez).
4. **Endpoint**: un controlador propio que llame a `Endpoint::dispatch($request->getQuery('action'))` (y devuelva el JSON). Trunca la petición si `Check` no pasó (`!$check->passed`).

**Overrides de entorno sin tocar el fichero**: `Captcha::configure(Config::forLogin()->merge(['verifyAttempts' => 10]))` en el bootstrap combina el preset con los ajustes del entorno (p. ej. `getenv(...)`), manteniendo el fichero como fuente declarativa.

---

## Consola

```
vendor/bin/captcha <comando> [opciones]

  install [framework] [-f|--framework=plain|codeigniter|laravel|symfony|cakephp|yii|janssen]
                      [-n|--dry-run]
                      Crea el config del framework y el pegamento de integración
                      (middleware/filter/provider/controlador); nunca sobrescribe
                      un fichero. Default: plain (app/Config/captcha.php).
  doctor [-s|--strict]  Comprueba PHP/GD, config descubierto y efectivo, postura de
                      seguridad (honeypot, rate limits, proxies, host) y endpoint.
  list                 Lista los comandos disponibles.
  help [comando]       Muestra la ayuda general, o la de un comando.
```

Alias: `i`/`init` = `install`, `d` = `doctor`, `ls` = `list`, `?` = `help`. Opciones globales en todos los comandos: `-h, --help`, `-V, --version`, `-q, --quiet`, `--no-ansi`. El color se decide sobre el stream donde se escribe, así que `captcha doctor > informe.txt` guarda texto limpio sin códigos de escape.

`vendor/bin/captcha help install` (o `install --help`) imprime la ayuda completa de cualquier comando, incluidos sus ejemplos.

**Dónde escribe `install`**: en la raíz del proyecto —el directorio que contiene `composer.json`, buscado hacia arriba desde donde lances el comando—, no en el directorio de trabajo tal cual. Lanzado desde un subdirectorio (`cd src && vendor/bin/captcha install`) los ficheros caen igualmente en la raíz, que es donde la aplicación los va a buscar. La línea de salida dice siempre qué raíz ha resuelto.

**Qué pasa con lo que ya existe**: nada. Un fichero que ya está ahí no se toca nunca, aunque sea una versión antigua de la misma plantilla; para regenerarlo, bórralo antes. Un destino que sea un **enlace simbólico** se rechaza con error en vez de seguirlo, para que la escritura no pueda salirse de la raíz del proyecto.

**Permisos**: los ficheros que escribe quedan en `0644` y los directorios en `0755`, fijados antes de volcar el contenido. Sin ese cuidado, `fopen()` crea con `0666` menos el `umask`, y con un `umask` permisivo (habitual en contenedores) el config generado —un PHP que tu aplicación incluye— quedaría escribible por cualquier otro usuario del sistema.

**Antes de escribir, mira**: `--dry-run` (o `-n`) enseña el plan completo —cada ruta y los pasos de registro pendientes— y no deja ni un fichero. Es la forma de revisar la instalación en una app real antes de tocarla.

**Códigos de salida de `doctor`**: `0` si no hay errores; `1` si falta `ext-gd` o la fachada no arranca; con `--strict`, también `1` si hay avisos. Sin `--strict`, un aviso no tumba el proceso: casi siempre es una decisión deliberada (honeypot apagado, ejecución en CLI, rate limit a cero) y convertirlo en puerta cerrada lo haría inútil en un despliegue. El resumen final cuenta los hallazgos por gravedad.

Atajo dentro del propio repositorio del paquete:

```bash
composer doctor            # = vendor/bin/captcha doctor
composer captcha -- help   # cualquier comando
```

En tu aplicación, el bin ya está enlazado en `vendor/bin/captcha` y funciona sin más. Si quieres además los atajos de `composer`, **añádelos tú al `composer.json` de tu app**: los `scripts` de un paquete no se heredan al proyecto que lo instala, así que el atajo se declara donde se va a usar.

```json
{
  "scripts": {
    "captcha": "vendor/bin/captcha",
    "doctor": "vendor/bin/captcha doctor",
    "captcha:install": "vendor/bin/captcha install"
  }
}
```

```bash
composer captcha:install -- --dry-run laravel   # qué escribiría, sin escribirlo
composer captcha:install -- laravel             # = vendor/bin/captcha install laravel
```

Dos apuntes sobre el atajo que escribe ficheros. `install` sin argumentos usa `plain`, así que conviene pasar el framework explícito para no acabar con `app/Config/captcha.php` cuando esperabas otra cosa. Y no lo llames desde un script que se ejecute solo (`post-install-cmd` y compañía): escribir ficheros de la aplicación es una decisión de una persona, y la red de seguridad es que este comando no lo haga nunca por su cuenta.

La línea de comandos es estricta a propósito: una opción desconocida, un valor ausente, un argumento de más o un framework desconocido son un error con código distinto de cero, nunca un valor por defecto. El error va a STDERR acompañado de una sugerencia cuando hay una cerca (`¿Querías decir "--quiet"?`). Es lo que evita que un typo termine escribiendo el config en el sitio equivocado sin que nada se entere hasta que la aplicación falla.

El binario resuelve el autoload del **proyecto que instaló el paquete** (con fallback al `vendor/` local del paquete en desarrollo), de modo que `doctor` opera sobre tu app, no sobre el paquete.

---

## Desarrollo del paquete

El paquete es un proyecto Composer independiente con su propio `composer.json` y `vendor/`:

```bash
composer install
composer test          # PHPUnit (tests/Unit + tests/Integration)
composer cs:check      # php-cs-fixer en modo dry-run
composer check         # tests + CS
composer test:coverage # cobertura (XDEBUG_MODE=coverage)
composer assets:min        # regenera los .min desde la fuente legible
composer config:templates  # regenera las plantillas de install desde el fragmento
composer smoke /ruta/a/una/app  # prueba la API pública en una app que ya lo instaló
composer doctor            # diagnóstico del entorno (no va en `check`: depende de la máquina)
composer captcha -- help   # la CLI del paquete sobre sí misma
```

`composer smoke` es la única puerta que se ejecuta **fuera** del repositorio: recibe la ruta de una
app anfitriona donde el paquete ya está instalado como dependencia y comprueba que el consumidor
recibe algo que funciona — `generate()`, `verify()` con el código correcto, con uno incorrecto y
reintentado, `widget()` y el binario enlazado. La suite normal solo puede ver lo que ya es alcanzable
desde el propio repo, así que sin esta puerta un asset o una plantilla podrían dejar de viajar en el
archivo sin que nada se enterara.

Guías del código (contrato del paquete):

- `declare(strict_types=1)` + PSR-12 siempre; `final readonly` en value objects; enums y `match`; nunca `mixed` salvo casos documentados.
- **Idioma**: identificadores, tipos y PHPDoc **en español** (los identificadores siguen las convenciones inglesas de PHP: `camelCase`, `snake_case`); los strings de feedback (API, excepciones, endpoint, assets, widget, consola) **fijos en español**.
- Prohibido en `src/`: `require` de terceros (solo `php` + `ext-gd`), `eval`/`extract`/`$$var`, `@`, `echo`/`var_dump`/`die`, fuentes TTF, superglobales fuera de `Globals`/`SessionStorage`/boot.
- Cobertura objetivo ≥ 90 % de líneas en `src/` (sin perseguir el 100 %: las fronteras I/O con `exit` y los errores absurdos quedan fuera a propósito).

Los assets servibles del widget (`captcha.js`/`captcha.css`) viven en `src/resources/assets/` junto a sus versiones minificadas que son las que se distribuyen e inyectan; tras editar la fuente, regenéralas con `composer assets:min` (la suite falla si el `.min` distribuido diverge de la fuente). Lo mismo vale para las plantillas de `install`: el bloque de opciones se edita en `src/app/Config/fragments/options.php` y se regenera con `composer config:templates`.

### Publicar una versión

`composer.lock`, `vendor/`, `AGENTS.md`, `.agents/` y las cachas están gitignorados a propósito: el archivo que Composer distribuye se arma con `git archive` y solo lleva lo versionado. El orden importa, porque `bin/captcha --version` lee la etiqueta de git:

```bash
composer check                  # la puerta local: nada se etiqueta sin esto en verde
# actualiza CHANGELOG.md con lo que entra en la versión y ciérrala
git tag -a v1.0.0 -m "v1.0.0"
git push --follow-tags
```

Sin etiqueta, `--version` imprime `1.0.0+no-version-set` (usa la versión declarada en el `composer install` del host, no la tuya). La etiqueta es también lo que fija Packagist.

---

## Limitaciones conocidas

- Solo **PNG** como formato de salida (por ahora).
- Render con **fuentes bitmap de GD** (1-5): no hay TTF, no hay tipografías personalizadas ni texto no-ASCII en la imagen (los símbolos aritméticos se dibujan en ASCII).
- El rate limit por IP usa **un archivo por clave** en `sys_get_temp_dir()`: suficiente en un solo proceso; para granjas con muchos nodos inyecta tu propio `RateLimiterInterface` (Redis, etc.).
- El widget requiere (para el botón de recarga y el `theme` dinámico) algo de JS: es **vanilla/umd**, auto-inicializado, sin dependencias, y degrada bien si el endpoint falla (recarga de página).
- El pegamento de integración está verificado por **sintaxis y contrato**, no arrancando un proyecto real de cada framework: `install` emite ficheros válidos, CI los compila y comprueba que el config emitido lo encuentra el descubrimiento, pero nadie ha ejecutado Laravel, Symfony, CodeIgniter, CakePHP, Yii o Janssen de verdad contra ellos. Los pasos que requieren registrar un provider o etiquetar un listener se imprimen como texto, así que revisa el primer arranque.

---

## Licencia

MIT. Úsalo, estúdialo y modifícalo libremente. El texto completo está en [LICENSE](LICENSE).

## Cómo colaborar

Las Pull Requests son bienvenidas. Antes de abrir una:

```bash
composer install
composer check   # test + estilo + PHPStan nivel 9
```

La puerta local y la de CI son la misma cosa: `composer check` es exactamente lo que ejecuta el workflow, así que si pasa en tu máquina pasará en GitHub. El repositorio trae cuatro jobs —suite en PHP 8.2 a 8.5, estilo y análisis estático, cobertura de producción con umbral del 90 % e instalación limpia sin configurar—, y este último es el que detecta que un asset o una plantilla han dejado de viajar en el archivo distribuido.

Si el cambio toca el comportamiento observable, actualiza también el `CHANGELOG.md` en `[No publicado]`.

Reporta los fallos de seguridad por el canal privado de [Advisories de GitHub](https://github.com/andexer/captcha/security/advisories/new), no con una incidencia pública: la política está en [SECURITY.md](SECURITY.md).
