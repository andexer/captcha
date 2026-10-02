# Características

> Forma parte de la documentación de [captcha](../README.md). Volver al [inicio](../README.md).

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

Siguiente: [Tipos de captcha](tipos-de-captcha.md) · [Configuración](configuracion.md) · [API](api.md) · [Seguridad](seguridad.md) · [Integración](integracion.md) · [Consola](cli.md) · [Desarrollo](desarrollo.md)
