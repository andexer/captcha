# Desarrollo del paquete

> Forma parte de la documentación de [captcha](../README.md). Volver al [inicio](../README.md).

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

## Arquitectura y diseño

- `src/Captcha.php` es la **fachada** (no implementa generación ni render); el estado global vive solo en la capa estática (`Runtime\StaticLayer`, `@internal`), que PHP resetea en cada petición. El flujo de verificación por petición se unifica en `Request\RequestFlow` (`@internal`) y el widget se renderiza sobre un view model (`Widget\WidgetModel`, `@internal`) en lugar de la fachada.
- Sin superglobales esparcidas: el acceso a `$_SERVER`/`$_POST` vive únicamente en `Http\Globals` (aislado y testeado); `SessionStorage`/`SessionRateLimiter` tocan `$_SESSION`.
- `Config` es `final readonly`: inmutable y validado al construir. Value objects (resultados) también `readonly`.
- El renderer (`GdRenderer`) es el único que toca GD, y sus métricas de fuente se leen de GD en tiempo real (no hardcodeadas: varían por build).
- Widget en Grid CSS (filas imagen+campo jamás se rompen), `color-scheme: light dark` con override `data-theme` (default `light`), anillo de foco suave, botón de recarga sobre el borde derecho de la imagen (nunca en esquinas: los dígitos a escala alta lo taparían).

## Publicar una versión

`composer.lock`, `vendor/`, `AGENTS.md`, `.agents/` y las cachas están gitignorados a propósito: el archivo que Composer distribuye se arma con `git archive` y solo lleva lo versionado. El orden importa, porque `bin/captcha --version` lee la etiqueta de git:

```bash
composer check                  # la puerta local: nada se etiqueta sin esto en verde
# actualiza CHANGELOG.md con lo que entra en la versión y ciérrala
git tag -a v1.0.0 -m "v1.0.0"
git push --follow-tags
gh release create v1.0.0 --title "v1.0.0" --generate-notes
```

Sin etiqueta, `--version` no puede saber nada: imprime `desconocida (no instalada con Composer)` cuando el paquete no está instalado con Composer, y la versión que declaraste en el host cuando sí lo está. La etiqueta es también lo que fija Packagist, que sincroniza solo con cada push.

**Pre-releases.** Una candidata se etiqueta igual, con el sufijo SemVer completo:

```bash
git tag -a v1.0.0-rc.1 -m "v1.0.0-rc.1"
git push --follow-tags
gh release create v1.0.0-rc.1 --prerelease --title "v1.0.0-rc.1" --generate-notes
```

Dos consecuencias que conviene no olvidar: `gh release create` **sin** `--prerelease` publica una rc como si fuera estable y la marca en Packagist como `stable`, así que el flag es lo que separa las dos cosas; y quien instala la rc tiene que pedir la estabilidad a mano (`composer require andexer/captcha:1.0.0-rc.1`), porque Composer no ofrece pre-releases por defecto. Cuando llegue la `v1.0.0` estable, `composer require andexer/captcha` empezará a resolverla sin tocar nada.

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

## Limitaciones conocidas

- Solo **PNG** como formato de salida (por ahora).
- Render con **fuentes bitmap de GD** (1-5): no hay TTF, no hay tipografías personalizadas ni texto no-ASCII en la imagen (los símbolos aritméticos se dibujan en ASCII).
- El rate limit por IP usa **un archivo por clave** en `sys_get_temp_dir()`: suficiente en un solo proceso; para granjas con muchos nodos inyecta tu propio `RateLimiterInterface` (Redis, etc.).
- El widget requiere (para el botón de recarga y el `theme` dinámico) algo de JS: es **vanilla/umd**, auto-inicializado, sin dependencias, y degrada bien si el endpoint falla (recarga de página).
- El pegamento de integración está verificado por **sintaxis y contrato**, no arrancando un proyecto real de cada framework: `install` emite ficheros válidos, CI los compila y comprueba que el config emitido lo encuentra el descubrimiento, pero nadie ha ejecutado Laravel, Symfony, CodeIgniter, CakePHP, Yii, Yii 3 o Janssen de verdad contra ellos. Los pasos que requieren registrar un provider, meter un middleware en la pila o publicar una ruta se imprimen como texto, así que revisa el primer arranque.

---

Siguiente: [Características](caracteristicas.md) · [API](api.md) · [Consola](cli.md)
