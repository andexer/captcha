# Integración por framework

> Forma parte de la documentación de [captcha](../README.md). Volver al [inicio](../README.md).

El paquete es agnóstico: cada framework solo aporta la **ruta del config** y su propia forma de exponer las rutas. La plantilla de cada uno está lista en `src/app/Config/templates/` con la **sintaxis *clean code*** completa (secciones `───`, bloques de explicación y cada opción comentada junto a su default); el config resultante es siempre un `return []` activo, válido desde el primer segundo: en las siete primeras, con cada clave comentada junto a su default; en Janssen, con los valores ya activos. Para proteger rutas como capa HTTP, `install` escribe el pegamento de `CaptchaGuard` propio de cada framework y te indica dónde registrarlo ([CaptchaGuard](seguridad.md#captchaguard-capa-de-verificación-para-middlewares)).

| Framework | `install --framework=` | Config generado | Dónde se carga |
|---|---|---|---|
| PHP plano / cualquier app | `plain` | `app/Config/captcha.php` | Descubrimiento automático (raíz de instalación o cwd). |
| CodeIgniter 4 | `codeigniter` | `app/Config/captcha.php` | Descubrimiento automático desde `public/` (docroot). |
| Laravel | `laravel` | `config/captcha.php` | Auto-discovery de Composer (`extra.laravel.providers` → `Captcha\Laravel\CaptchaServiceProvider`), descubrimiento automático (`config/captcha.php` es ancla) o el provider que emite `install`. |
| Symfony | `symfony` | `config/captcha.php` | Descubrimiento automático (`config/captcha.php` es ancla). Va en `config/` y **no** en `config/packages/`: el kernel importa `config/packages/*` como configuración de contenedor y un array plano de opciones escalares lo rompería en cuanto descomentes una. El camino manual (`Captcha::configure(...)`) no va en `config/services.php`: ese fichero solo se ejecuta al compilar el contenedor y no llega a los workers. |
| CakePHP 4/5 | `cakephp` | `config/captcha.php` | Descubrimiento automático (array plano, como el resto); no necesitas tocar `Configure`. El pegamento es PSR-15, así que vale para las dos series. |
| Yii 2 | `yii` | `config/captcha.php` | Descubrimiento automático (`config/captcha.php` es ancla), o el `require` + `Captcha::configure(...)` del bootstrap. |
| Yii 3 | `yii3` | `config/captcha.php` | Descubrimiento automático (`config/captcha.php` es ancla). El fichero queda fuera del merge plan de `yiisoft/config`, que solo lee los ficheros que enumera: quien lo carga es la capa estática, así que no hay que tocar `.merge-plan.php`. |
| Janssen | `janssen` | `app/Config/captcha.php` | Descubrimiento automático (`app/Config/captcha.php` es ancla). Es la única plantilla que llega con los valores **activos** en vez de comentados. No está entre los kernels detectados, así que `storage: 'auto'` elige sesión — que es como vive este CMS. |

Las rutas que emite `install` hoy (`app/Config/captcha.php`, `config/captcha.php`) son anclas del descubrimiento, así que el config que escribe se carga solo. `config/packages/captcha.php` sigue en la lista de candidatas solo por retrocompatibilidad con instalaciones de rc anteriores; nadie escribe ahí desde que Symfony pasó a `config/`. La lista de anclas vive en `Runtime\StaticLayer` y es la fuente de verdad: si un framework nuevo usara otra ruta, habría que añadirla ahí, no en el instalador. Las anclas de raíz exigen la firma `// captcha config v2`; las del cwd, no.

**Auto-discovery en Laravel**: `composer.json` declara
`Captcha\Laravel\CaptchaServiceProvider` en `extra.laravel.providers`, así que
Laravel lo registra solo sin tocar `bootstrap/providers.php`. En `boot()` fija
en la capa estática lo que el propio framework ya cargó de `config/captcha.php`
(Laravel lee `config/` por su cuenta, sin que participe nuestro
descubrimiento). Si no hay config, o el paquete está en `dont-discover`, no se
fija nada y sigue mandando el descubrimiento de la capa estática, que
encontraría el mismo fichero. El pegamento que emite `install laravel` queda
como vía para las apps que desactivan el auto-discovery a mano.

## Series revisadas

El pegamento **no se prueba arrancando el framework**: se compila y se revisa contra su API pública, y así lo dice [desarrollo](desarrollo.md). Esta es la revisión de octubre de 2026, con la última serie estable de cada uno en esa fecha:

| Pieza | Última serie estable | Qué mira el pegamento |
|---|---|---|
| PHP | 8.2 – 8.5 (8.2 cierra el 31-12-2026) | CI corre las cuatro; el gate de estilo, solo en 8.2. |
| CodeIgniter 4 | 4.7 | `FilterInterface` en `app/Filters` y registro en `app/Config/Filters.php` (`$aliases` **más** `$methods`, que es lo que lo prende: el alias solo no basta). |
| Laravel | 13 | provider en `bootstrap/providers.php` (11 en adelante; el fichero de config emitido lo recuerda) y middleware con `handle($request, $next)`. |
| Symfony | 8 (exige PHP ≥ 8.4) | listener de `kernel.request` con `#[AsEventListener]` (atributo desde 5.3) y ruta YAML aparte. |
| CakePHP | 5 | middleware PSR-15 sobre `Cake\Http\Response`, igual que en 4. |
| Yii 2 | 2.0.x | action filter como `as captcha` a nivel superior de `config/web.php` y acción inline en `controllers/`. |
| Yii 3 | `yiisoft/app` 1.4 | `withMiddlewares()` en `config/web/di/application.php` y rutas en `config/common/routes.php`. |
| Janssen | sin versión fijada | preprocesador declarado en `app/Config/engine.php` y controlador. |

Cuando una serie mayor salte, lo que hay que revalidar es la fila entera: la firma del pegamento, la ruta donde se registra y la nota que imprime `install`. Sin ese paso, las puertas del paquete siguen en verde mientras el cableado queda viejo.

El patrón de integración es el mismo para todos (`install` lo materializa por framework y la receta `05-janssen-login.php` lo muestra para un CMS):

1. **Config descubierto o `Captcha::configure(...)`**: el config (array o `Config`) alimenta la capa estática; si el host es un framework que gestiona la sesión PHP (CodeIgniter, Laravel, Symfony, **CakePHP, Yii, Yii 3** — clases kernel ya cargadas), el storage por defecto ya es `FileStorage` y el limiter solo-IP, sin tocar la sesión del host.
2. **Backend**: `Captcha::check()` en tu controlador de POST.
3. **Frontend**: `Captcha::widget()` dentro del `<form>` (idempotente, inyecta CSS/JS la primera vez). El widget recarga contra `/captcha/endpoint` por defecto y casi ningún framework deja esa ruta libre: si la tuya es otra (el pegamento de `install` imprime la de cada uno), declárala con `Captcha::widget(['endpoint' => '/captcha/generate'])` o el botón de recarga devolverá 404.
4. **Endpoint**: un controlador propio que llame a `Endpoint::dispatch($request->getQuery('action'))` (y devuelva el JSON). Trunca la petición si `Check` no pasó (`!$check->passed`).

**Overrides de entorno**: `Captcha::configure(Config::forLogin()->merge(['verifyAttempts' => 10]))` combina el preset con los ajustes del entorno (p. ej. `getenv(...)`). Ojo con el alcance: `configure()` **sustituye** al descubrimiento, así que desde ese momento el fichero deja de leerse — sigue siendo la fuente declarativa de la que partiste, pero no se releen sus cambios. Si quieres que mande el fichero, edita ahí.

---

Siguiente: [Configuración](configuracion.md) · [Consola](cli.md) · [Seguridad](seguridad.md)
