# Changelog

Las novedades del paquete. Formato *Keep a Changelog* y versionado *SemVer*.
Las versiones se cortan con etiquetas de git (`v1.0.0-rc.1`), y
`bin/captcha --version` lee la etiqueta; sin ella imprime la versión que
declare el host que instaló el paquete.

## [No publicado]

Todavía no hay nada en este apartado. La última versión cortada está abajo.

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
