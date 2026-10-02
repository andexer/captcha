# Integración por framework

> Forma parte de la documentación de [captcha](../README.md). Volver al [inicio](../README.md).

El paquete es agnóstico: cada framework solo aporta la **ruta del config** y su propia forma de exponer las rutas. La plantilla de cada uno está lista en `src/app/Config/templates/` con la **sintaxis *clean code*** completa (secciones `───`, bloques de explicación y cada opción comentada junto a su default); el config resultante es siempre un `return []` activo, válido desde el primer segundo: en las seis primeras, con cada clave comentada junto a su default; en Janssen, con los valores ya activos. Para proteger rutas como capa HTTP, `install` escribe el pegamento de `CaptchaGuard` propio de cada framework y te indica dónde registrarlo ([CaptchaGuard](seguridad.md#captchaguard-capa-de-verificación-para-middlewares)).

| Framework | `install --framework=` | Config generado | Dónde se carga |
|---|---|---|---|
| PHP plano / cualquier app | `plain` | `app/Config/captcha.php` | Descubrimiento automático (raíz de instalación o cwd). |
| CodeIgniter 4 | `codeigniter` | `app/Config/captcha.php` | Descubrimiento automático desde `public/` (docroot). |
| Laravel | `laravel` | `config/captcha.php` | Descubrimiento automático (`config/captcha.php` es ancla), o el provider que lo carga. |
| Symfony | `symfony` | `config/packages/captcha.php` | Descubrimiento automático (`config/packages/captcha.php` es ancla); si tu bundle lo carga por su cuenta, enlázalo con `Captcha::configure(...)` y ambos caminos sirven. |
| CakePHP 4 | `cakephp` | `config/captcha.php` | Descubrimiento automático (array plano, como el resto); no necesitas tocar `Configure`. |
| Yii 2 | `yii` | `config/captcha.php` | Descubrimiento automático (`config/captcha.php` es ancla), o el `require` + `Captcha::configure(...)` del bootstrap. |
| Janssen | `janssen` | `app/Config/captcha.php` | Descubrimiento automático (`app/Config/captcha.php` es ancla). Es la única plantilla que llega con los valores **activos** en vez de comentados. |

Las siete rutas que emite `install` son anclas del descubrimiento, así que el config que escribe se carga solo en los siete casos. La lista de anclas vive en `Runtime\StaticLayer` y es la fuente de verdad: si un framework nuevo usara otra ruta, habría que añadirla ahí, no en el instalador. Las anclas de raíz exigen la firma `// captcha config v2`; las del cwd, no.

El patrón de integración es el mismo para todos (`install` lo materializa por framework y la receta `05-janssen-login.php` lo muestra para un CMS):

1. **Config descubierto o `Captcha::configure(...)`**: el config (array o `Config`) alimenta la capa estática; si el host es un framework que gestiona la sesión PHP (CodeIgniter, Laravel, Symfony, **CakePHP, Yii, Janssen**), el storage por defecto ya es `FileStorage` y el limiter solo-IP, sin tocar la sesión del host.
2. **Backend**: `Captcha::check()` en tu controlador de POST.
3. **Frontend**: `Captcha::widget()` dentro del `<form>` (idempotente, inyecta CSS/JS la primera vez).
4. **Endpoint**: un controlador propio que llame a `Endpoint::dispatch($request->getQuery('action'))` (y devuelva el JSON). Trunca la petición si `Check` no pasó (`!$check->passed`).

**Overrides de entorno sin tocar el fichero**: `Captcha::configure(Config::forLogin()->merge(['verifyAttempts' => 10]))` en el bootstrap combina el preset con los ajustes del entorno (p. ej. `getenv(...)`), manteniendo el fichero como fuente declarativa.

---

Siguiente: [Configuración](configuracion.md) · [Consola](cli.md) · [Seguridad](seguridad.md)
