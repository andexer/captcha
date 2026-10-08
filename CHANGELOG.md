# Changelog

Las novedades del paquete. Formato *Keep a Changelog* y versionado *SemVer*.
Las versiones se cortan con etiquetas de git (`v1.0.0-rc.1`), y
`bin/captcha --version` lee la etiqueta; sin ella imprime la versión que
declare el host que instaló el paquete.

## [1.0.0] - 2026-10-08

Primera versión estable. Sin cambios de API respecto a `1.0.0-rc.6`.

### Añadido

- **Segunda grafía para las variables por opción.** Las claves multipalabra
  aceptan también la forma guionada habitual en un `.env` 12-factor:
  `CAPTCHA_RATE_LIMIT_BY_IP` equivale a `CAPTCHA_RATELIMITBYIP` (igual
  `CAPTCHA_TRUSTED_PROXIES`, `CAPTCHA_FONT_SIZE`...). Si existen las dos,
  manda la pegada; un nombre que no sea ninguno de los dos sigue ignorándose
  en silencio. Así escribir el nombre natural no apaga en silencio un dial de
  seguridad.

### Cambiado

- **La salida de `captcha install` va al grano.** Cada bloque de pasos a mano
  empieza por la ruta del fichero que hay que editar —resaltada en la
  terminal— seguida de un ejemplo corto; el porqué vive en la documentación.
  Las 19 plantillas de integración y la ayuda de `install` siguen la misma
  receta: lo crítico (POST, `requireSubmission`, dónde registrarse) y nada
  más.

### Corregido

- **`captcha doctor` no se muere con una env inválida.** Una `CAPTCHA_*` con
  un valor que el lector no admite (p. ej. `CAPTCHA_NOISE=maybe`) imprimía
  solo el mensaje de la excepción y sin informe; ahora el reporte se
  renderiza entero y el hallazgo baja a `Error` nombrando la variable, con
  salida `1` como cualquier otro error de arranque.

## [1.0.0-rc.6] - 2026-10-08

Sexta candidata de publicación. Configuración por variables de entorno
(12-factor), auto-discovery de Composer para Laravel y una receta para
runners persistentes; sin cambios de API.

```bash
composer require andexer/captcha:1.0.0-rc.6
```

### Añadido

- **Una variable de entorno por opción (`CAPTCHA_*`).** Cualquier clave del
  config puede fijarse con `CAPTCHA_<CLAVE>` en mayúsculas —p. ej.
  `CAPTCHA_LENGTH=9`, `CAPTCHA_NOISE=true`,
  `CAPTCHA_OPERATIONS='["add","subtract"]'`— encima del fichero descubierto
  y por debajo de un `configure()` manual. Booleanos aceptan `1/true/yes/on`
  y `0/false/no/off`, arrays se leen como JSON, un valor vacío o con sufijo
  desconocido se ignora y uno inválido detiene el arranque con
  `InvalidConfigException`. `doctor` nombra las variables presentes como
  aviso (nunca tumba por ellas), y el orden sigue el de `Config::KEYS`.
- **Auto-discovery de Composer para Laravel.** `extra.laravel.providers`
  publica `Captcha\Laravel\CaptchaServiceProvider`, que configura el SDK con
  `config('captcha')` cuando es un array: basta `composer require` y
  `vendor/bin/captcha install laravel` para el config. El stub de prueba de
  `Illuminate\Support\ServiceProvider` vive en `tests/stubs/` con extensión
  `.tpl` (no lo resuelven ni las puertas por token ni PHPStan en modo
  estricto) y se inyecta vía `scanFiles`.
- **Receta de runners persistentes.**
  `src/examples/06-runners-persistentes.php` explica por qué el singleton,
  la sesión del host y el config manual sobreviven en FrankenPHP worker,
  RoadRunner, Swoole u Octane, con el patrón `Captcha::reset()` por
  petición. Los ejemplos de pegamento (Octane, RoadRunner) también están en
  `docs/configuracion.md` (§ Runners persistentes y orden de arranque).
- **Alias de rama** `dev-main` → `1.0.x-dev` en `composer.json`, para que
  Composer resuelva la rama principal como prerelease coherente con las
  etiquetas `v1.0.0-rc.*`.

## [1.0.0-rc.5] - 2026-10-08

Quinta candidata de publicación. Silencia de `doctor` tapando una
`CAPTCHA_CONFIG` rota corregido, y la asimetría de tipos del SDK documentada
con test aferrador.

```bash
composer require andexer/captcha:1.0.0-rc.5
```

### Corregido

- **`doctor` ahora nombra una `CAPTCHA_CONFIG` rota.** Cuando la variable de
  entorno está vacía o apunta a un fichero inexistente, el descubrimiento la
  salta en silencio (comportamiento de runtime a propósito) y el reporte lo
  leía como «sin configuración». Ahora emite un **aviso** que nombra la
  variable y la ruta; en `doctor --strict` la rotura tumba el comando. La
  capa estática expone el origen de cada candidata (`env`) para que el
  diagnóstico no lo recalcule aparte.

### Documentado

- **Asimetría de la ruta `new Config(...)` frente a la array.** El
  constructor exige los tipos PHP nativos, así que un valor con el tipo
  equivocado (`noise: 'yes'`) es un `TypeError` y no un
  `InvalidConfigException`; la misma entrada por array sí lo es. Explicado en
  `docs/configuracion.md` (#validaciones), `docs/api.md` (§ Excepciones) y el
  PHPDoc de `Config::__construct`, con un test que fija el comportamiento.

## [1.0.0-rc.4] - 2026-10-07

Cuarta candidata de publicación. Corrige el pegamento que emitía `install`
para varios frameworks (instrucciones que no encendían el filtro, rutas que
nadie resolvía y un widget apuntando a una ruta inexistente) y pone a la par
config, docs y `doctor` de acuerdo con lo que el código hace de verdad, sin
cambios de API.

```bash
composer require andexer/captcha:1.0.0-rc.4
```

### Corregido

- **La plantilla de Symfony escribía donde el propio Symfony la lee como
  configuración de contenedor.** `install --framework=symfony` dejaba el config
  en `config/packages/captcha.php`, y el kernel importa `config/packages/*`
  (`Kernel/KernelTrait`): al descomentar una opción, el `PhpFileLoader` recibe
  un array plano de escalares y lanza *The "length" key should contain an
  array…*, rompiendo `cache:clear` en cuanto tocas el fichero. Ahora escribe en
  `config/captcha.php`, que además es ancla firmada del descubrimiento.
  `config/packages/captcha.php` queda en la lista de anclas **solo por
  retrocompatibilidad** con instalaciones previas.

  > **Migración (Symfony)**: mueve tu fichero
  > `mv config/packages/captcha.php config/captcha.php`, o deja el array que ya
  > cargas a mano en `config/services.php` con `Captcha::configure(...)`.
  > El pegamento de rutas (`config/routes/captcha.yaml`) ya lo importa el glob
  > `config/routes/*` del microkernel; solo hace falta registrarlo a mano si has
  > desactivado ese glob.

- **`Captcha::configure()` validaba tarde.** Las claves desconocidas y los
  valores fuera de rango de un array se comprobaban en el primer `instance()`,
  no en la propia llamada: el typo aparecía lejos de quien lo escribía. La
  validación es ahora inmediata y **no** pisa las opciones fijadas
  anteriormente si el array nuevo no llega a validarse.

- **`new Captcha(...)` ignoraba la opción `storage`.** La construcción manual
  usaba `SessionStorage` por mucho que el config dijera `'file'`; la elección
  vivía solo en la capa estática. Hoy ambos caminos pasan por
  `Runtime\StorageResolver` (`Config::storage`, `'auto'` incluido), y un
  storage explícito sigue mandando por encima de la opción.

- **Docs que prometían cosas que el código no hacía**: la detección de host no
  incluye Janssen (bajo ese CMS `'auto'` elige sesión, que es como vive), el
  cruce `between` ↔ `length` se revisa en `generate()` y no al construir (un
  `max` que desborda se recorta en silencio), y un preset ya materializado
  (`Config::forLogin()`) no se reanuda al hacerle `merge(['preset' => ...])`.

- **El pegamento de install que no encendía nada.** Las notas de CodeIgniter
  solo nombraban el alias del filtro (el alias por sí solo no filtra: hace
  falta `$methods['POST'] = ['captcha']`), las de CakePHP escribían
  `$middleware->add(...)` cuando el parámetro de `Application::middleware()`
  es `$middlewareQueue`, y las de Yii2 apuntaban a `'behaviors'` dentro del
  array, clave que `Component::__set` rechaza: el formato correcto es
  `'as captcha'` a nivel superior, con los ficheros en `filters/` y
  `controllers/` de la raíz (el esqueleto no declara `app\` en Composer).

- **El widget recargaba contra una ruta inexistente.** Su endpoint por defecto
  es `/captcha/endpoint` y ninguna ruta que emite `install` la ocupa, así que
  el botón de recarga devolvía 404 en los frameworks de glue. Cada nota y cada
  plantilla de controlador ya declara ahora `Captcha::widget(['endpoint' =>
  '/ruta/propia'])`, y una puerta (`IntegrationNotesTest`) impide que esas
  instrucciones se pierdan o vuelvan a sugerir formas que no funcionan.

- **Cifras y recuentos falsos en el propio config y en los docs.** El preset
  `login` es de 200×60 y no toca el TTL (no 200×56 con TTL 180 de `strict`),
  la dificultad aritmética no fija 9/99/999 sino un reparto dentro de
  `10^length − 1` (raíz / décima / mitad, con suelo 9), la fuente 4 es la más
  alta y no «tiny», y las plantillas de install son 8 (7 comparten el
  fragmento; Janssen lleva el suyo). Las cabeceras del config, del endpoint y
  del generador nombran ya `config/packages/` como lo que es: retrocompat.

- **`doctor` mezclaba dos vías distintas de configuración.** Con
  `Captcha::configure([...])` puesto, el reporte decía «ninguno descubierto»
  y marcaba como *cargado* el fichero que el descubrimiento encontraría.
  Distingue ahora las tres situaciones (fichero, opciones manuales, nada) y
  etiqueta la candidatura como *no usada* cuando la gana `configure()`.

- **`configure()` convertía el array dos veces.** El array se validaba al
  fijarlo y se volvía a construir en el primer `instance()`. Se guarda ya
  como `Config`: una sola conversión y el mismo error inmediato en la línea
  que lo escribió.

- **Los docs no hablaban de caché.** `configuracion.md` explica ahora qué
  pasa con opcache `validate_timestamps=0`, `config:cache` de Laravel,
  `cache:warmup` de Symfony y por qué `reset()` no revierte
  `configure()` (el config es declarativo, no se muta a mitad de petición).

## [1.0.0-rc.3] - 2026-10-02

Tercera candidata de publicación. Corrige la integración de Janssen, que escribía
el pegamento correcto pero dejaba en suspenso —a veces con formas que no
funcionaban— los pasos de registro que lo encienden:

```bash
composer require andexer/captcha:1.0.0-rc.3
```

### Corregido

- **El registro del CaptchaGuard en `app/Config/engine.php` no funcionaba.**
  Las notas de `install` proponían anidar los preprocesadores bajo una clave
  `'POST'`, pero Janssen recorre `preprocessors` como una lista plana de `clase`
  o `[clase, verbo]` (`Janssen\Engine\Preprocessor::processHandlers()`): con la
  forma anterior el guard no se instanciaba nunca. Ahora las notas emiten la
  lista real, con `['\App\Preprocessor\CaptchaGuard', 'POST']` detrás de
  `DecryptRoute` —que es quien fija la acción del usuario—, y explican por qué
  importa la tupla con el verbo: sin ella el guard también se ejecuta en GET y
  `decide()` vería un envío vacío.
- **Las notas no recordaban el resto del contrato de `engine.php`.**
  `json_encode_options`, que el stock de Janssen ya trae, es de donde
  `Janssen\Helpers\Response\JsonResponse::render()` saca los flags de
  `json_encode()`, es decir del endpoint que emite el reto. Sin la clave
  `Config::get()` devuelve `null` y `json_encode()` recibe `null` en `$flags`:
  cada recarga del widget responde con un `Deprecated` que, en una aplicación
  que convierte los avisos en excepciones, se come el JSON entero. Ahora se pide
  comprobar que sigue ahí.
- **El paso de registro del router no era copiable.** Las notas mostraban una
  sola entrada de `routes.php` y contaban en prosa la variante que hace
  funcionar el botón de recarga. Janssen convierte el query que manda el widget
  (`?action=generate`) en un *friendly path*, así que `/captcha/action/generate`
  necesita su propia entrada: sin ella el `404` caía en el botón de recarga, no
  en el render inicial, que sí se veía. Ahora se emiten las tres entradas
  listas para copiar —el endpoint, su variante y la página de prueba—.
- **La página de prueba del widget de Janssen salía sin estilos.**
  `install --framework=janssen` escribe `templates/captcha-test.php`, y su
  versión anterior era un esqueleto `<main>` sin CSS: se veía el reto, pero pelado
  y sin el marco del que habla la documentación. Ahora es una vista completa
  (Bulma desde CDN) que llama al widget con `endpoint => '/captcha'`, la misma
  ruta que mapea el paso de registro del router.

## [1.0.0-rc.2] - 2026-10-02

Segunda candidata de publicación. Misma naturaleza que la rc.1: API cerrada,
pre-release que hay que pedir explícitamente:

```bash
composer require andexer/captcha:1.0.0-rc.2
```

### Cambiado

- **La TTL por defecto baja de 300 a 120 segundos** (`Config::DEFAULT_TTL`), y
  los presets `login` y `strict` heredan ese valor en lugar de fijar los suyos
  (300 y 180): el código caduca a los 2 minutos, una ventana suficiente para un
  humano y mucho más incómoda para un atacante que reutiliza retos. Las
  plantillas de `install` y la documentación reflejan el nuevo valor.
- **La documentación se segmenta**: el README queda como portada —qué es,
  instalación básica, inicio rápido e índice— y el contenido extendido vive en
  `docs/` (`caracteristicas.md`, `tipos-de-captcha.md`, `configuracion.md`,
  `api.md`, `seguridad.md`, `integracion.md`, `cli.md` y `desarrollo.md`), con
  enlaces cruzados y sin emojis. `docs/` se marca `export-ignore`, así que no
  viaja en el archivo distribuido.

### Corregido

- **Uso único reforzado en `FileStorage` bajo concurrencia**: `consume()` abre
  el fichero en modo `r+` y lo **trunca a 0 bytes bajo el mismo `flock` antes
  de borrarlo**, así que un segundo proceso que ya había abierto el fichero lee
  contenido vacío y `parse()` lo descarta como consumido; antes leía el código
  igualmente si entraba entre la lectura y el `unlink()`. `SWEEP_THRESHOLD`
  baja de 200 a 50 y una lectura fallida dispara el barrido, para que el
  directorio de retos se recoja más pronto y no crezca con residuos.
- Un test de idempotencia del widget podía quedar contaminado por una sesión
  previa del propio proceso; ahora la deja limpia antes de contar.

## [1.0.0-rc.1] - 2026-10-01

**Candidata de publicación, no estable.** Semánticamente es una pre-release de la
1.0.0: la API pública se considera cerrada a partir de aquí, pero puede cambiar
en la 1.0.0 si algo sale mal. Para instalarla hay que pedir la estabilidad
explícitamente, porque Composer no ofrece las pre-releases por defecto:

```bash
composer require andexer/captcha:1.0.0-rc.1
```

Quien prefiera la última estable cuando la haya usará `^1.0` sin más.

### Añadido

- `vendor/bin/captcha install` emite, además del config de arranque, el pegamento
  de integración de cada framework: filter + controlador en CodeIgniter 4, service
  provider + middleware + controlador en Laravel, listener de `kernel.request` +
  controlador + ruta YAML en Symfony, middleware PSR-15 + controlador en CakePHP,
  action filter + controlador en Yii 2, y guard + endpoint en PHP plano. Escribe
  solo ficheros propios y **nunca** edita un fichero del host: los pasos de
  registro que no puede automatizar se imprimen al terminar.
- `Console\Artifact` (`path`, `contents`, `notes`) e `Installer::artifacts()`: el
  plan de instalación es una lista de datos y el binario se limita a la E/S.
- `install --dry-run` (`-n`): enseña cada ruta que crearía y los pasos de
  registro pendientes, y no deja ningún fichero. Es la forma de revisar la
  instalación en una aplicación real antes de tocarla.
- `doctor` informa de la postura efectiva —storage, límites de verificación y de
  generación, proxies de confianza, host detectado— y devuelve código 1 si hay
  errores.
- Los ejecutables de `src/examples` resuelven el autoload en cascada de cuatro
  candidatos, así que arrancan también desde el dist instalado en `vendor/`.

### Cambiado

- El nombre del paquete pasa de `captcha/captcha` a `andexer/captcha`, para que el
  vendor coincida con la cuenta que lo mantiene. Como el vendor forma parte de la
  ruta de instalación, la cascada de autoload sigue resolviendo en el mismo número
  de niveles; solo cambian la constante del nombre, las menciones en documentación
  y la instrucción de `require`.
- `install` escribe en la raíz del proyecto —el directorio con `composer.json`,
  buscado hacia arriba desde donde se lanza el comando— en lugar del directorio de
  trabajo. Lanzado desde un subdirectorio, los ficheros ya no quedan descuadrados
  respecto a donde la aplicación los busca, y la salida dice qué raíz ha resuelto.
- `composer assets:templates` pasa a llamarse `composer config:templates`: el
  prefijo `assets:` es el de los recursos que se sirven al navegador, y una
  plantilla de configuración PHP no es un asset. El nombre antiguo sigue existiendo
  como alias. `composer setup`, que emitía ficheros de la aplicación con un verbo
  que no dice que escriba, pasa a llamarse `composer captcha:install`.
- El config de CakePHP (`config/captcha.php`) devuelve un **array plano**, igual
  que el resto de frameworks, para que el descubrimiento automático lo cargue sin
  tener que pasar por `Configure`.
- `Support\Host` y `Bootstrap\StaticLayer` pasan a `Runtime\Host` y
  `Runtime\StaticLayer`; el minificador de assets, a `Build\AssetMinifier`. Los
  tres son internos: no hay cambios de API pública.
- `install` nunca sobrescribe un fichero existente.

### Corregido

- **El config que `install --framework=symfony` emitía no lo encontraba nadie.**
  Se escribía en `config/packages/captcha.php`, ruta que no estaba entre las
  anclas del descubrimiento, así que el fichero quedaba ahí, la aplicación
  arrancaba con los valores por defecto y quien integraba creía haber
  configurado algo. `config/packages/captcha.php` es ahora ancla, y CI instala
  los seis frameworks en una app limpia y exige que su propio `doctor` encuentre
  el config emitido.
- `install` seguía un enlace simbólico en el destino: `is_file()` lo atraviesa,
  así que un enlace colgado pasaba por "no existe" y la escritura terminaba
  creando el fichero fuera de la raíz del proyecto. Ahora un destino que sea un
  enlace se rechaza con error.
- `install` creaba los ficheros con el modo que deja el `umask` del proceso, que
  con un `umask` permisivo —habitual en contenedores— dejaba el config
  escribible por el grupo y por otros usuarios; es un PHP que la aplicación
  incluye. Ahora se fija `0644` (y `0755` en los directorios) antes de volcar el
  contenido.
- La garantía de "nunca sobrescribe" dependía de un `is_file()` seguido de otro
  `file_put_contents()`, con una ventana entre ambos; ahora la crea el propio
  `fopen()` en modo `x`, que falla si el fichero existe. Además, una escritura a
  medias ya no deja un config de PHP truncado —un error de sintaxis en la
  aplicación—: se borra y se avisa.
- Byte de salto de formulario incrustado en la nota de integración de Yii, que
  imprimía `\appifiers\CaptchaFilter::class` en vez de `\app\filters\...`.
- El job `instalacion` de CI installaba el paquete como enlace simbólico, con lo
  que no podía detectar que un asset o una plantilla dejasen de viajar en el
  archivo distribuido; ahora instala una copia real y vigila explícitamente las
  rutas de runtime. El mismo job valida el manifiesto y audita las dependencias
  antes de dar la suite por buena.
- El color de la salida de error se decidía mirando `STDOUT`, así que un error
  dirigido a un fichero o a una tubería podía acabar con códigos de escape dentro; se
  decide sobre el stream donde se escribe.
- `GlyphPainterTest` afirmaba tres veces por cada píxel pintado sobre ruido
  aleatorio, lo que hacía que el número de aserciones de la suite variase entre
  ejecuciones; ahora el recuento es estable y sirve para comparar.

### Eliminado

- Las recetas de integración por framework que vivían en `src/examples/framework/`
  y `src/examples/middleware/`: su contenido son ahora las plantillas que emite
  `install`. El pegamento que generan es material de la aplicación anfitriona, así
  que queda fuera del control de versiones del paquete.

Las versiones anteriores a `1.0.0-rc.1` no tienen historial publicado: este fichero
arranca con la primera versión que se etiquete.
