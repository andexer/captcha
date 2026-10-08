# Consola

> Forma parte de la documentación de [captcha](../README.md). Volver al [inicio](../README.md).

```
captcha — consola del paquete

Uso:
  vendor/bin/captcha <comando> [opciones]

Comandos:
  install  Crea el config y el pegamento de integración del framework.  [i, init]
  doctor   Comprueba el entorno y la configuración efectiva.  [d]
  list     Lista los comandos disponibles.  [ls]
  help     Muestra la ayuda de un comando.  [?]

Opciones globales:
  -h, --help      Muestra la ayuda del comando.
  -V, --version   Muestra la versión del paquete.
  -q, --quiet     Reduce la salida a los errores.
       --no-ansi  Desactiva el color en la salida.

Ejemplos:
  vendor/bin/captcha install laravel
  vendor/bin/captcha doctor --strict
  vendor/bin/captcha help install

Más ayuda: vendor/bin/captcha help <comando>
```

Alias: `i`/`init` = `install`, `d` = `doctor`, `ls` = `list`, `?` = `help`. Opciones globales en todos los comandos: `-h, --help`, `-V, --version`, `-q, --quiet`, `--no-ansi`. El color se decide sobre el stream donde se escribe, así que `captcha doctor > informe.txt` guarda texto limpio sin códigos de escape.

`vendor/bin/captcha help install` (o `install --help`) imprime la ayuda completa de cualquier comando, incluidos sus ejemplos.

**Dónde escribe `install`**: en la raíz del proyecto —el directorio que contiene `composer.json`, buscado hacia arriba desde donde lances el comando—, no en el directorio de trabajo tal cual. Lanzado desde un subdirectorio (`cd src && vendor/bin/captcha install`) los ficheros caen igualmente en la raíz, que es donde la aplicación los va a buscar. La línea de salida dice siempre qué raíz ha resuelto.

**Qué pasa con lo que ya existe**: nada. Un fichero que ya está ahí no se toca nunca, aunque sea una versión antigua de la misma plantilla; para regenerarlo, bórralo antes. Un destino que sea un **enlace simbólico** se rechaza con error en vez de seguirlo, para que la escritura no pueda salirse de la raíz del proyecto.

**Permisos**: los ficheros que escribe quedan en `0644` y los directorios en `0755`, fijados antes de volcar el contenido. Sin ese cuidado, `fopen()` crea con `0666` menos el `umask`, y con un `umask` permisivo (habitual en contenedores) el config generado —un PHP que tu aplicación incluye— quedaría escribible por cualquier otro usuario del sistema.

**Antes de escribir, mira**: `--dry-run` (o `-n`) enseña el plan completo —cada ruta y los pasos de registro pendientes— y no deja ni un fichero. Es la forma de revisar la instalación en una app real antes de tocarla.

**Códigos de salida de `doctor`**: `0` si no hay errores; `1` si falta `ext-gd`, el config o una `CAPTCHA_*` es inválido, o la fachada no arranca; con `--strict`, también `1` si hay avisos. Sin `--strict`, un aviso no tumba el proceso: casi siempre es una decisión deliberada (honeypot apagado, ejecución en CLI, rate limit a cero) y convertirlo en puerta cerrada lo haría inútil en un despliegue. El resumen final cuenta los hallazgos por gravedad.

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

Siguiente: [Integración](integracion.md) · [Configuración](configuracion.md) · [Desarrollo](desarrollo.md)
