# Política de seguridad

## Qué cubre este paquete

`andexer/captcha` genera y verifica retos de imagen numérica. Su superficie
expuesta son, sobre todo, la verificación del reto, el descubrimiento del config, el
almacenamiento del código y el límite de intentos. Cualquier fallo que permita
aceptar un reto sin resolverlo, reutilizar un reto ya gastado o saltarse el
límite de intentos se considera **vulnerabilidad de seguridad**.

## Qué NO cubre

Una weakness del captcha no es un fallo de este paquete. Que una imagen sea legible
por un humano o por un modelo de visión, o que un servicio externo resuelva el
código, no es una vulnerabilidad: el captcha es una capa, no una garantía. La
documentación de [Limitaciones conocidas](README.md#limitaciones-conocidas)
recoge ese matiz.

## Versiones con soporte

Solo la última versión menor recibe correcciones de seguridad.

## Cómo reportar

**No abras una incidencia pública con un detalle de explotación.** Usa el rastreador
de avisos privados del repositorio:

> <https://github.com/andexer/captcha/security/advisories/new>

Esa vía es privada entre tú y el mantenedor: lo que escribas no aparece en el
rastreador público hasta que se publique un aviso, así que puedes dar los detalles
exactos de reproducción sin exponerlos.

## Qué esperar

| Plazo | Compromiso |
|---|---|
| Acuse de recibo | 48 horas desde el primer informe. |
| Triaje (si es vulnerabilidad) | 7 días: confirmado, descartado o se necesita más información. Si se necesita más información, la respuesta lo dice. |
| Corrección publicada | 30 días desde la confirmación, con el aviso y la etiqueta Published como aviso único. |

Si el fallo está siendo explotado activamente, se publica un aviso antes de la
corrección en lugar de esperar a los 30 días.

Mientras tria, mantente en el canal privado. Quien te reply en ese hilo ya ha
recibido el informe: ahí va también la conversación.

## Divulgación

Las correcciones se publican como un aviso de seguridad de GitHub, en la versión
que las lleva, con el CHANGELOG como registro. Los créditos que quieras dar avisan
por el mismo canal privado y se atribuyen en el aviso.
