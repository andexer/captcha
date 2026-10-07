<?php

declare(strict_types=1);

// Configuración global de captcha — la ÚNICA pieza de configuración. La capa estática descubre este archivo automáticamente (orden: env CAPTCHA_CONFIG → raíz del proyecto app/Config/captcha.php → config/captcha.php → config/packages/captcha.php (retrocompatibilidad) → etc/captcha.php → los mismos en cwd) y sus valores alimentan tanto Captcha::widget() como Captcha::check(); instalación completa = composer require + este return.
// captcha config v2
//
// Todas las claves son OPCIONALES: si una se omite se aplica su valor por defecto. El fichero trae dos bloques: (1) PLANTILLA COMPLETA — todas las opciones documentadas en español, una por línea, comentadas; (2) ARRAY FUNCIONAL — las opciones realmente activas en este proyecto.

// ─────────────────────────────────────────────────────────────────────────────
// 1) PLANTILLA COMPLETA — todas las opciones del paquete, una por línea, con su
//    default y su explicación. USO: quita el "// " y borra la parte "→ ..."
//    y tendrás una línea de array válida. Recuerda que cada clave es OPTIONAL.
// ─────────────────────────────────────────────────────────────────────────────

// ── PRESETS ──────────────────────────────────────────────────────────────────
//   Atajo: un paquete de opciones listo para cada caso.
// 'preset' => 'default',  → 'default' (defaults del constructor) | 'login' (formularios de acceso: 5 dígitos, imagen grande y limpia 200x60, sin ruido/distorsión, rate limit 5 verify / 30 generate) | 'strict' (máxima dureza anti-spam: 6 dígitos, dificultad high, ruido y distorsión al máximo, honeypot 'website'; el TTL lo deja en 120). Cualquier clave explícita GANA sobre la del preset, así que 'preset' + retoques conviven bien.

// ── CÓDIGO E IMAGEN ──────────────────────────────────────────────────────────
// 'length' => 6,       → Número de dígitos del código (rango 3-10); más dígitos = más seguridad pero menos legible; también acepta texto numérico ('6').
// 'width' => 180,      → Ancho de la imagen en píxeles; también acepta texto numérico.
// 'height' => 60,      → Alto de la imagen en píxeles; también acepta texto numérico.
// 'ttl' => 120,        → Segundos que un código permanece válido; al expirar el reto pierde vigencia.
// 'output' => 'png',   → Formato de la imagen; por ahora solo se admite 'png'.
// 'difficulty' => 'medium', → Dificultad visual 'low' | 'medium' | 'high'; en modo aritmético gradúa además los operandos contra el techo 10^length − 1 (low hasta su raíz, medium una décima parte, high la mitad, con suelo 9); también acepta el enum Difficulty.
// 'font' => 5,     → Tipografía: fuente bitmap INTEGRADA de GD por número (1 small · 3 medium bold · 4 la más alta · 5 large, el default); sin TTF. Las métricas se leen de GD en runtime.
// 'fontSize' => null, → Altura de glifo objetivo en píxeles; null la deriva del lienzo (~78 % del alto, tope 4x). Nunca deja que el código desborde el ancho.

// ── RENDERIZADO ──────────────────────────────────────────────────────────────
//   Ruido y distorsión.
// 'noise' => true,       → Dibuja ruido aleatorio (puntos) sobre la imagen para dificultar el OCR.
// 'distortion' => true,  → Aplica una distorsión de onda a la imagen.

// ── FORMULARIO ───────────────────────────────────────────────────────────────
//   Campos del HTML y assets.
// 'idField' => 'captcha_id', → Nombre del campo oculto que transporta el id del reto; fuente única de verdad para el widget y para verifyRequest().
// 'inputField' => 'captcha', → Nombre del campo donde el usuario teclea el código que ve en la imagen.
// 'injectAssets' => true,    → Inyecta el CSS/JS empaquetados inline con el primer widget(); con false enlázalos tú mismo (CSP estricta).

// ── MODO ARITMÉTICO ──────────────────────────────────────────────────────────
// 'operations' => [],  → Operaciones matemáticas habilitadas: el reto muestra "a op b" y el código es el resultado numérico; acepta símbolos ['+', '-', '*', '/'] (y '×', '÷'), nombres ['addition', 'subtraction', 'multiplication', 'division'], casos del enum Operation o el mapa booleano ['addition' => true, ...]; vacío u omitido = dígitos clásicos.
// 'between' => null,     → Rango de resultados aritméticos inclusivo [min, max], p. ej. [2, 20]: toda respuesta cae dentro (además del techo de length); requiere 'operations'; 0 <= min <= max; valores del archivo aceptan strings numéricos.

// ── RATE LIMIT ───────────────────────────────────────────────────────────────
//   Techo anti-flood y anti-spam.
// 'verifyAttempts' => 5,     → Máx. intentos de verificación por clave y ventana (5 por defecto); 0 = desactivado; superado, verify() devuelve "bloqueado" SIN consumir el reto.
// 'generateAttempts' => 20,  → Máx. captchas generados por clave y ventana (20 por defecto; frena el DoS de CPU con GD); 0 = desactivado; superado, el endpoint responde HTTP 429.
// 'rateLimitWindow' => 300,  → Anchura en segundos de la ventana del rate limit (común a verify y generate).
// 'rateLimitByIp' => true,   → Incluye la IP del cliente en la clave del límite: limiter dual IP + sesión, a prueba de requests sin cookies; false = solo sesión.
// 'trustedProxies' => [],    → IPs EXACTAS (sin rangos/CIDR) de tus proxies; solo ellas dan paso al X-Forwarded-For; vacío = se ignora la cabecera y se usa REMOTE_ADDR.

// ── HONEYPOT ─────────────────────────────────────────────────────────────────
//   Trampa anti-bots.
// 'honeypot' => false,      → Renderiza un campo trampa invisible que los humanos no rellenan; si llega con contenido, la petición se rechaza antes de verificar.
// 'honeypotField' => 'email', → Nombre del campo trampa; debe ser distinto de los campos reales del captcha.

// ── STORAGE ──────────────────────────────────────────────────────────────────
//   Dónde se guarda el reto.
// 'storage' => 'auto', → 'auto' | 'session' | 'file' | 'array'; 'auto' elige en runtime: FileStorage bajo un framework que gestione la sesión PHP (CodeIgniter, Laravel, Symfony...) y SessionStorage en PHP plano/CLI — así el default NUNCA preempta la sesión del host.

/*
 * ═════════════════════════════════════════════════════════════════════════════
 * 1b) CÓDIGO COMPLETO DEL ARRAY — las 24 claves del paquete (23 opciones más
 *     el atajo preset) en un solo array, listas para copiar y pegar. Copia este
 *     bloque al `return` de tu proyecto, quita los delimitadores del bloque (los
 *     dos asteriscos de la primera línea y los dos del final) y ajusta solo lo
 *     que necesites: cada clave es OPCIONAL y todo lo que no esté presente usará
 *     su default.
 * ═════════════════════════════════════════════════════════════════════════════
 *
 * return [
 *     // ── Atajo de paquete
 *     'preset'      => 'login',    // 'default' | 'login' | 'strict'; las claves
 *                                  //   explícitas ganan sobre la del preset.
 *
 *     // ── Código e imagen
 *     'length'      => 6,          // Dígitos del código (rango 3-10).
 *     'width'       => 180,        // Ancho de la imagen en píxeles.
 *     'height'      => 60,         // Alto de la imagen en píxeles.
 *     'ttl'         => 120,        // Segundos que un código permanece válido.
 *     'output'      => 'png',      // Formato de imagen; solo se admite 'png'.
 *     'difficulty'  => 'medium',   // 'low' | 'medium' | 'high'.
 *
 *     // ── Renderizado (ruido y distorsión)
 *     'noise'       => true,       // Dibuja ruido aleatorio en la imagen.
 *     'distortion'  => true,       // Aplica distorsión de onda a la imagen.
 *     'font'        => 5,          // Fuente bitmap de GD (1-5, sin TTF).
 *     'fontSize'    => null,       // Alto del glifo en px; null = automático.
 *
 *     // ── Formulario (campos del HTML y assets)
 *     'idField'     => 'captcha_id', // Campo oculto que transporta el id.
 *     'inputField'  => 'captcha',    // Campo donde se teclea el código.
 *     'injectAssets' => true,        // Inyecta CSS/JS inline con el widget.
 *
 *     // ── Modo aritmético
 *     'operations'  => [],          // ['+', '-'] o nombres o mapa booleano;
 *                                   //   vacío = dígitos clásicos.
 *     'between'     => null,        // [min, max] de resultados aritméticos,
 *                                   //   p. ej. [2, 20]; requiere operations.
 *
 *     // ── Rate limit (techo anti-flood / anti-spam)
 *     'verifyAttempts'   => 5,     // Máx. verify() por clave y ventana; 0 = off.
 *     'generateAttempts' => 20,    // Máx. generate() por clave y ventana; 0 = off.
 *     'rateLimitWindow'  => 300,   // Anchura de la ventana, en segundos.
 *     'rateLimitByIp'    => true,  // Clave del límite incluye la IP del cliente.
 *     'trustedProxies'   => [],    // IPs exactas (sin CIDR) autorizadas a pasar X-Forwarded-For.
 *
 *     // ── Honeypot (trampa anti-bots)
 *     'honeypot'      => false,    // Renderiza un campo trampa invisible.
 *     'honeypotField' => 'email',  // Nombre del campo trampa.
 *
 *     // ── Storage (dónde se guarda el reto)
 *     'storage'       => 'auto',   // 'auto' | 'session' | 'file' | 'array';
 *                                  //   'auto' evita la sesión del framework.
 * ];
 */

return [
    // Código de 5 dígitos.
    'length' => 5,
    // Imagen de 200x60 píxeles.
    'width' => 200,
    'height' => 60,
    // El código es válido durante 2 minutos.
    'ttl' => 120,
    // Dificultad visual media; en aritmético, los operandos se reparten dentro
    // del techo por longitud (una décima parte con el medium, suelo 9).
    /**
     * 'low' = hasta la raíz cuadrada del techo (10^length − 1), mínimo 9
     * 'medium' = hasta una décima parte del techo, mínimo 9
     * 'high' = hasta la mitad del techo
     */
    // 'difficulty' => 'medium',

    // ── TIPOGRAFÍA Y TAMAÑO ──────────────────────────────────────────────────
    /*
     * Tipografía: fuentes bitmap integradas de GD. El paquete nunca usa TTF,
     * así que NO se puede cargar un archivo de fuente propio; solo hay 5
     * tipografías y se eligen por número (la 5 es el default):
     *
     *  1 (small, la más pequeña)
     *  2 (normal)
     *  3 (medium bold, negrita)
     *  4 (grande)
     *  5 (large, la mayor — default)
     */
    'font' => 5,

    // Tamaño del glifo (alto en píxeles):
    'fontSize' => 35,
    /* 'null'
     *  null      = automático: los glifos ocupan ~78 %
     *  un entero >= 1 = alto objetivo del glifo: se
     *  redondea a la escala entera más
     *  cercana (mínimo ×1) y se recorta al
     *  ancho del lienzo — el código nunca
     *  sale de la imagen.
     */

    // Imagen con ruido y distorsión para dificultar el OCR.
    'noise' => true, // ruido
    'distortion' => true, // distorsión

    // Rate limit por clave y ventana (300 segundos).
    'verifyAttempts' => 10,    // máx. 10 verificaciones por ventana
    'generateAttempts' => 30, // máx. 30 generaciones por ventana

    // Modo aritmético: suma y resta.
    // El reto muestra "a + b" o "a - b" y se teclea el RESULTADO.
    'operations' => ['+', '-'],
    // Resultados dentro de este rango: el código siempre estará entre 0 y 20.
    'between' => [0, 20],
];
