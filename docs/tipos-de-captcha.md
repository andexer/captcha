# Tipos de captcha

> Forma parte de la documentación de [captcha](../README.md). Volver al [inicio](../README.md).

## Dígitos clásicos

Por defecto (`operations` vacío u omitido), `NumericGenerator` produce un código de `length` dígitos con CSPRNG (`random_int`). La imagen muestra los dígitos, cada uno con *jitter* vertical y una inclinación aleatoria según la dificultad.

## Modo aritmético (captcha matemático)

Con `operations` no vacío, la imagen muestra `a + b` (o `−`, `×`, `÷`) y el código a teclear es el **resultado numérico**. El generador garantiza:

- **Resta no negativa** (`a >= b` siempre).
- **División exacta** (divisor ≥ 2).
- **Techo por longitud**: el resultado nunca excede `10^length − 1`, así que el usuario nunca teclea más dígitos que el `maxlength` del campo.
- **Dificultad** gradua los operandos contra ese techo: `low` hasta su raíz cuadrada, `medium` una décima parte, `high` la mitad (suelo 9). Con `length: 6` son ~999, ~99 999 y ~499 999 — no tres cifras fijas; la tabla completa está más abajo.
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

## Tipografía y tamaño (fuentes bitmap de GD)

El renderer usa `imagechar()` con las **fuentes bitmap integradas de GD** (1 a 5). No hay TTF: no puedes cargar un archivo de fuente propio, y no hace falta.

| `font` | Descripción |
|---|---|
| 1 | `small` — la más pequeña |
| 2 | `normal` |
| 3 | `medium bold` — negrita |
| 4 | `grande` — la más alta (8×16) |
| 5 | `large` — la más ancha (9×15), **default** |

Las medidas exactas las da el build de GD con el que corre tu PHP: se leen en runtime (`imagefontwidth()`/`imagefontheight()`), nunca están hardcodeadas.

El tamaño del glifo:

- `fontSize => null` (default): la escala entera se deriva del lienzo — el glifo apunta a **~78 % del alto** de la imagen, con techo **×4** y **recorte al presupuesto horizontal** (el código jamás se sale de la imagen).
- `fontSize => 35` (px): `35 / altoDeLaFuente` redondeado a la escala entera (mínimo ×1), también recortada al ancho del lienzo.

Las métricas exactas de cada fuente se leen de GD **en tiempo real** (`imagefontwidth()`/`imagefontheight()`), porque varían según el build de GD — no las hardcodees. Los glifos se escalan por factor entero con vecino más cercano (bordes nítidos, sin grises borrosos) y, si hay ruido activo y el glifo no está rotado, se pinta una sombra de 1 px que le separa del fondo oscuro.

---

Siguiente: [Configuración](configuracion.md) · [API](api.md) · [Seguridad](seguridad.md)
