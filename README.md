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
| **Cero dependencias** | Solo PHP >= 8.2 y `ext-gd`. Sin servicios externos ni sesiones ajenas. |
| **Widget listo para usar** | Imagen + campo + botón de recarga con CSS/JS empaquetados, inyectados *inline* (CSP estricta). |
| **Dos llamadas y listo** | `Captcha::widget()` en el formulario y `Captcha::check()` en el POST. Config opcional. |
| **Captcha matemático** | El reto puede ser `a + b`, `a − b`, `a × b` o `a ÷ b`; el usuario teclea el resultado. |
| **Anti-spam de verdad** | Rate limit dual por IP + sesión activo por defecto, fail-closed, honeypot opcional. |
| **Uso único garantizado** | Verificación atómica y comparación *timing-safe* con `hash_equals()`. |

Detalle completo en [Características](docs/caracteristicas.md).

---

## Requisitos

- **PHP >= 8.2**, **`ext-gd`** con soporte PNG y **Composer 2**.

```bash
php -m | grep -i gd
```

---

## Instalación

```bash
composer require andexer/captcha
vendor/bin/captcha install   # crea app/Config/captcha.php (plantilla comentada; opcional)
vendor/bin/captcha doctor    # comprueba PHP, GD, config descubierto y endpoint
```

`install` nunca sobrescribe un archivo existente. Eso es todo: instalación completa = `composer require` + (opcional) un archivo `.php` de configuración.

> **Versión actual: candidata de publicación.** Lo publicado ahora es `1.0.0-rc.6` (pre-release): Composer no la ofrece sin pedir la estabilidad, así que hay que nombrarla: `composer require andexer/captcha:1.0.0-rc.6`. En producción, espera a `v1.0.0` y usa `^1.0`.

---

## Inicio rápido

Un formulario con captcha en dos llamadas y un archivo de configuración opcional:

```php
<?php // controlador.php
require 'vendor/autoload.php';

use Captcha\Captcha;

// 1) Backend: ¿llegó un POST? ¿pasó? Feedback en español.
$check = Captcha::check();

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

## Documentación

| Documento | Contenido |
|---|---|
| [Características](docs/caracteristicas.md) | Tabla detallada, flujo completo del reto y reglas de seguridad. |
| [Tipos de captcha](docs/tipos-de-captcha.md) | Dígitos, modo aritmético, `between`, tipografía GD. |
| [Configuración](docs/configuracion.md) | Descubrimiento, presets, tabla completa de opciones, `Config`, validaciones. |
| [API](docs/api.md) | Métodos públicos, widget, endpoint AJAX, almacenamiento, excepciones, contratos. |
| [Seguridad](docs/seguridad.md) | Rate limiting, proxies de confianza, honeypot, endurecimiento, `CaptchaGuard`. |
| [Integración](docs/integracion.md) | PHP plano, CodeIgniter, Laravel, Symfony, CakePHP, Yii, Yii 3 y Janssen. |
| [Consola](docs/cli.md) | `install`, `doctor`, alias, dry-run, códigos de salida. |
| [Desarrollo](docs/desarrollo.md) | Puesta en marcha, publicar versiones, ejemplos, limitaciones, arquitectura. |

---

## Licencia

MIT. Úsalo, estúdialo y modifícalo libremente. El texto completo está en [LICENSE](LICENSE).
