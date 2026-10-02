<?php

declare(strict_types=1);

/**
 * Ejemplo 5: integración out-of-the-box en un formulario de login.
 *
 * =====================================================================
 *  RECETA PARA UN CMS CON FRONT CONTROLLER (p. ej. Janssen)
 * =====================================================================
 *
 * Este no es un ejemplo ejecutable aquí: es la receta de cómo se integra
 * el paquete en una aplicación existente que ya tiene su propio login.
 *
 * Para Janssen, el atajo es `vendor/bin/captcha install janssen`: escribe
 * el config (app/Config/captcha.php) con los valores ya activos, el
 * CaptchaGuard que corta el POST, el controlador de la ruta AJAX y los
 * pasos de registro. Lo que queda por debajo es lo mismo que escribe el
 * instalador, para cuando el host no es Janssen o el pegamento ya existe.
 *
 * La idea es que el captcha quede listo para usar INMEDIATAMENTE después
 * de aplicar "una configuración + cuatro trozos de código":
 *
 *   1. EL CONFIG ARRAY  -> app/Config/captcha.php (return de opciones).
 *   2. CARGA DE INSTANCIA EN EL BACKEND -> Captcha::check() en el
 *      controlador y Endpoint::dispatch() para la recarga por AJAX.
 *   3. IMPLEMENTACIÓN EN EL FRONTEND  -> Captcha::widget() dentro del
 *      formulario; el widget inyecta su propio CSS/JS inline.
 *   4. TRUNCAR LA PETICIÓN SI EL CAPTCHA NO ES true -> antes de validar
 *      credenciales: si check()->passed no es true, se corta el login.
 *
 * En Janssen los puntos 2 y 4 los cubre el CaptchaGuard que emite install
 * (app/Preprocessor/CaptchaGuard.php), que delega en CaptchaGuard del
 * paquete con requireSubmission: true.
 *
 * Config del packages: composer require andexer/captcha + el archivo de
 * configuración descubierto (env CAPTCHA_CONFIG, o
 * <cwd>/app/Config/captcha.php). Todo lo demás usa la API pública.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  1) CONFIG ARRAY  — src/app/Config/captcha.php del host (return array)
 * ─────────────────────────────────────────────────────────────────────
 *
 *   <?php
 *
 *   declare(strict_types=1);
 *
 *   return [
 *       'length'           => 5,   // 5 dígitos.
 *       'ttl'              => 300, // El código caduca a los 5 minutos.
 *       'generateAttempts' => 10,  // 429 tras 10 generaciones en la ventana.
 *       'verifyAttempts'   => 5,   // Bloqueo tras 5 verificaciones erradas.
 *       // Ojo: el honeypot quedó desactivado — su campo por defecto es
 *       // 'email', y un login real ya tiene un campo 'email'. Si lo activas,
 *       // cambia honeypotField a un nombre que no exista en tu formulario.
 *   ];
 *
 * El descubrimiento es por env CAPTCHA_CONFIG (recomendado, evita
 * depender del cwd del proceso) o por <cwd>/app/Config/captcha.php.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  2) BACKEND  — la fachada estática comparte la MISMA config del widget
 * ─────────────────────────────────────────────────────────────────────
 *
 *   // En el controlador del login (método que recibe el POST):
 *   use Captcha\Captcha;
 *
 *   $check = Captcha::check();
 *   if (!$check->passed) {
 *       // fallback: mantén la sesión del host, mostrando $check->error
 *       // (mensaje fijo en español, ya listo para la vista).
 *   }
 *
 *   // Y para la recarga del widget (GET <endpoint>?action=generate):
 *   use Captcha\Http\Endpoint;
 *
 *   $action = $_GET['action'] ?? 'generate';
 *   $action = is_string($action) ? $action : 'generate';  // ?action[]= un array → 400, nunca 500
 *   $response = (new Endpoint(Captcha::instance()))->dispatch($action);
 *   http_response_code($response['status']);
 *   header('Content-Type: application/json; charset=utf-8');
 *   echo json_encode($response['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
 *   exit;
 *
 *   NOTA para front controllers cuyo router anexe el query string a la ruta
 *   (p. ej. Janssen: Request::getFullPath() = path + "friendly path" del query):
 *   una URL GET con query NO matchea su ruta limpia — /captcha?action=generate
 *   se resuelve a /captcha/action/generate. Registra también esa
 *   variante friendly-path en tu tabla de rutas para que el widget funcione.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  3) FRONTEND  — una sola llamada dentro del <form> del login
 * ─────────────────────────────────────────────────────────────────────
 *
 *   <?= \Captcha\Captcha::widget(['endpoint' => '/captcha']) ?>
 *
 *   El widget emite la imagen (data-URI), el campo oculto (idField), el
 *   input (inputField), el botón de recarga y, por defecto, el CSS/JS
 *   inline (injectAssets => true, sin servir ningún asset).
 *
 *   NOTA: si tu login usa AJAX y construye el FormData a mano, debes
 *   enviar las campos reales del formulario (new FormData(form)) para
 *   que viajen también el id y el código del captcha.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  4) TRUNCAR LA PETICIÓN  — captcha != true ⇒ cortar el login
 * ─────────────────────────────────────────────────────────────────────
 *
 *   Regla aplicada en el punto 2: la verificación ocurre ANTES de tocar
 *   las credenciales. Captcha::check() responde idle() en GET (el
 *   formulario se muestra sin bloquear), y en POST consume el reto una
 *   sola vez; si no pasó, la petición se corta con el feedback español.
 *
 * Recomendado: después de un fallo de captcha por AJAX, dispara la
 * recarga del widget (click en .ct__reload) para que el
 * usuario reciba un reto nuevo: check() ya consumió el anterior.
 *
 * Verificación manual rápida:
 *
 *   curl '<endpoint>?action=generate'            # {ok, id, image, ...}
 *   curl -X POST ... -d 'captcha_id=...' -d 'captcha=99999'  # rechazado
 */
