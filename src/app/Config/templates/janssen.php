<?php

declare(strict_types=1);

// Configuración de captcha — Janssen (ruta app/Config/captcha.php).
// captcha config v2
// Instalación: vendor/bin/captcha install --framework=janssen
// ─────────────────────────────────────────────────────────────────────────────
// La capa estática descubre este fichero automáticamente (orden: env
// CAPTCHA_CONFIG → raíz del proyecto app/Config/captcha.php → config/ →
// etc/ → los mismos en cwd) y sus valores alimentan tanto widget() como
// el veredicto del guard. Los valores de abajo están activos: ajústalos a
// tu gusto y borra las líneas que no uses, porque toda clave es opcional.
//
// El widget se dibuja en la plantilla del formulario y el POST lo decide
// el CaptchaGuard que escribe install. Modo estricto: una clave no
// reconocida lanza InvalidConfigException, así que un typo no desactiva
// la protección en silencio.
//
//     Captcha\Captcha::widget(['endpoint' => $href('/captcha')]);
//
// install escribe además el preprocesador (guard) y el controlador de la
// ruta AJAX; el instalador imprime dónde registrar cada uno.
//
// Borra la línea de cualquier clave que no uses: todas son opcionales y
// lo que falta se queda en su default. Para volver a reto numérico, quita
// 'operations' y 'between'.

return [
    // Código de 5 dígitos en una imagen de 200x60, válido 2 minutos.
    'length' => 5,
    'width' => 200,
    'height' => 60,
    'ttl' => 120,

    // Dificultad visual: operandos de hasta 9 (low), 99 (medium) o
    // 999 (high).
    'difficulty' => 'medium',

    // Tipografía bitmap integrada de GD (1-5; la 5 es la mayor) y alto
    // del glifo en píxeles; null lo deja automático.
    'font' => 5,
    'fontSize' => 35,

    // Ruido y distorsión para dificultar el OCR.
    'noise' => true,
    'distortion' => true,

    // Techo anti-flood por IP y ventana de 300 segundos.
    'verifyAttempts' => 10,
    'generateAttempts' => 30,

    // Modo aritmético: el reto muestra "a + b" o "a - b" y se teclea el
    // resultado, que siempre cae en este rango. Los operandos también
    // quedan confinados al entorno del rango (techo max + (max-min)/4).
    'operations' => ['+', '-'],
    'between' => [0, 20],
];
