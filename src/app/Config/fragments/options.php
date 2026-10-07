<?php

declare(strict_types=1);

/*
 * Fragmento compartido por 7 de las 8 plantillas de config de
 * src/app/Config/templates/ (Janssen trae cuerpo propio). tools/render-templates.php
 * lo inyecta en cada plantilla con la indentación que toque; editar aquí y
 * regenerar evita mantener el mismo bloque siete veces.
 * El bloque vive dentro de un comentario PHP: este fichero no es config.
 */

$fragment = <<<'FRAGMENT'
        // ── PRESET ───────────────────────────────────────────────────────────────
        //   Atajo de paquete: 'default' | 'login' | 'strict'. Las claves
        //   explícitas que pongas abajo GANAN siempre sobre las del preset.
        // 'preset' => 'default',

        // ── CÓDIGO E IMAGEN ──────────────────────────────────────────────────────
        // 'length' => 6,     // Dígitos del código (rango 3-10). Más dígitos =
        //                     //   más seguridad pero menos legibilidad.
        // 'width' => 180,    // Ancho del lienzo en píxeles.
        // 'height' => 60,    // Alto del lienzo en píxeles.
        // 'ttl' => 120,      // Segundos que el código permanece válido.
        // 'output' => 'png', // Formato de imagen; por ahora solo se admite 'png'.
        /*
         * Dificultad de la imagen y, en modo aritmético, de los operandos.
         * El techo de estos es 10^length − 1 y cada nivel reparte ese
         * espacio: 'low' hasta su raíz cuadrada, 'medium' una décima parte
         * y 'high' la mitad, siempre con un suelo de 9. Con length 6 eso
         * son ~999, ~99 999 y ~499 999, no tres cifras fijas.
         */
        // 'difficulty' => 'medium',

        // ── TIPOGRAFÍA ───────────────────────────────────────────────────────────
        //   Fuentes bitmap integradas de GD, nunca TTF.
        /*
         * La imagen se dibuja con las fuentes bitmap integradas de GD; el
         * paquete no carga ficheros de fuente propios. Hay 5 tipografías
         * (las métricas exactas las da el build de GD en runtime):
         *
         *   1 = small (la más pequeña)    3 = medium bold  5 = large (default)
         *   2 = normal                    4 = la más alta
         */
        // 'font' => 5,
        /*
         * Alto del glifo en píxeles:
         *   null        = automático: ~78 % del alto del lienzo (default).
         *   un entero >0 = alto objetivo: se redondea a la escala entera y se
         *                 recorta al ancho — el código nunca sale de la imagen.
         */
        // 'fontSize' => null,

        // ── RENDERIZADO ──────────────────────────────────────────────────────────
        //   Medidas anti-OCR.
        // 'noise' => true,      // Puntos de ruido sobre la imagen.
        // 'distortion' => true, // Distorsión de onda sobre la imagen.

        // ── MODO ARITMÉTICO ──────────────────────────────────────────────────────
        /*
         * El reto muestra "a + b" (la operación visible) y se teclea el
         * RESULTADO numérico. Vacío u omitido = dígitos clásicos (opción más
         * simple y legible, recomendada para formularios de acceso).
         */
        // 'operations' => [],  // Símbolos ['+', '-', '*', '/'] (o '×', '÷'),
        //                       //   nombres ['addition'|'subtraction'|...],
        //                       //   casos del enum o mapa booleano.
        // 'between' => null,   // Rango [min, max] inclusivo que acota el
        //                      //   resultado además del techo de length; los
        //                      //   operandos se repliegan al entorno del
        //                      //   rango (techo max + (max-min)/4).

        // ── RATE LIMIT ───────────────────────────────────────────────────────────
        //   Techo anti-flood y anti-spam.
        // 'verifyAttempts' => 5,   // Máx. verificaciones por clave y ventana;
        //                          //   0 = off; superado => "bloqueado" SIN
        //                          //   consumir el reto.
        // 'generateAttempts' => 20, // Máx. captchas generados por clave y
        //                          //   ventana; 0 = off; superado => 429 en la
        //                          //   recarga del widget.
        // 'rateLimitWindow' => 300, // Anchura de la ventana, en segundos.
        // 'rateLimitByIp' => true, // Incluye la IP en la clave (limiter dual
        //                          //   IP + sesión, a prueba de requests sin
        //                          //   cookies); false = solo sesión.
        // 'trustedProxies' => [], // IPs EXACTAS (sin CIDR) de tus proxies, las
        //                         //   únicas que dan paso al X-Forwarded-For;
        //                         //   vacío = se ignora la cabecera.

        // ── FORMULARIO Y ASSETS ──────────────────────────────────────────────────
        // 'idField' => 'captcha_id', // Campo oculto que transporta el id del
        //                            //   reto (fuente única de verdad).
        // 'inputField' => 'captcha', // Campo donde se teclea el código.
        // 'injectAssets' => true,    // Inyecta CSS/JS inline con el primer
        //                            //   widget(); false = enlázalos tú (CSP).

        // ── HONEYPOT ─────────────────────────────────────────────────────────────
        //   Trampa anti-bots.
        // 'honeypot' => false,       // Renderiza un campo trampa invisible que
        //                            //   los humanos no rellenan; si llega con
        //                            //   contenido, la petición se rechaza ANTES
        //                            //   de verificar.
        // 'honeypotField' => 'email', // Nombre del campo trampa; NO debe
        //                            //   coincidir con ningún campo real del
        //                            //   formulario (p. ej. un 'email' de login).

        // ── STORAGE ──────────────────────────────────────────────────────────────
        //   Dónde vive el reto.
        // 'storage' => 'auto', // 'auto' | 'session' | 'file' | 'array'. 'auto'
        //                      //   elige en runtime: fichero bajo un framework
        //                      //   que gestione la sesión (CodeIgniter, Laravel,
        //                      //   Symfony...), sesión en PHP plano/CLI — el
        //                      //   default NUNCA preempta la sesión del host.
    FRAGMENT;

echo $fragment;
