<?php

declare(strict_types=1);

/**
 * Ejemplo 6: runners persistentes — reiniciar el estado por petición.
 *
 * =====================================================================
 *  RECETA (no ejecutable aquí)
 * =====================================================================
 *
 * En FPM/CLI PHP reinicia los estáticos en cada petición y no hay nada
 * que hacer. En un runner persistente —FrankenPHP en modo worker,
 * RoadRunner, Swoole, Laravel Octane— el proceso vive entre peticiones,
 * así que el singleton de la capa estática, su config y el storage
 * elegido también: lo que decide la petición 1 sigue ahí en la 2.
 *
 * Qué se queda vivo si no limpias (y por qué importa):
 *
 *   1. EL SINGLETON: la Captcha construida en la petición 1 (storage
 *      resuelto, config ya cargada) atiende la 2. Si el framework del
 *      host cargó después, 'auto' ya no lo ve: el storage se decidió
 *      con la información de la primera petición.
 *   2. LA SESIÓN DEL HOST: SessionStorage nace con la sesión de la
 *      petición 1; en la 2 esa sesión ya no es la del usuario.
 *   3. EL CONFIG MANUAL: configure() es por proceso y sobrevive a
 *      reset() a propósito, porque lo fija el bootstrap. Si tu arranque
 *      es por petición, repítelo.
 *
 * EL PATRÓN — una línea por petición:
 *
 *   use Captcha\Captcha;
 *
 *   Captcha::reset();
 *
 * Y solo si fijas opciones a mano, vuélvelas a fijar después:
 *
 *   Captcha::configure([...]);
 *
 * Para devolver el control al descubrimiento (fichero/env):
 *
 *   Captcha::configure([]);
 *
 * DÓNDE PONERLA:
 *
 *   - Laravel Octane, tras cada petición:
 *
 *         use Captcha\Captcha;
 *         use Laravel\Octane\Events\RequestReceived;
 *
 *         app('events')->listen(RequestReceived::class, static function (): void {
 *             Captcha::reset();
 *         });
 *
 *   - FrankenPHP en modo worker (o cualquier app con bootstrap
 *     global): al cierre de cada request, DESPUÉS de que el framework
 *     haya cargado sus kernels — así el storage 'auto' los ve.
 *
 *   - RoadRunner / Swoole, en el finally del bucle:
 *
 *         use Captcha\Captcha;
 *
 *         while ($request = $worker->waitRequest()) {
 *             try {
 *                 $worker->send($kernel->handle($request));
 *             } finally {
 *                 Captcha::reset();
 *             }
 *         }
 *
 * ORDEN DE ARRANQUE: si la primera Captcha::instance() llega antes de
 * que el host cargue su framework, storage 'auto' se ve como PHP plano
 * y elige sesión; un reset() una vez arrancado el framework (o no
 * llamar hasta después de su bootstrap) lo corrige.
 *
 * Ver también: docs/configuracion.md → "Runners persistentes y orden
 * de arranque".
 */
