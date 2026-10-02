<?php

declare(strict_types=1);

namespace Captcha\Security;

use Captcha\Captcha;
use Captcha\Verification\Status;

/**
 * Capa de seguridad agnóstica del framework: verifica una petición enviada
 * y mapea el resultado sobre un GuardDecision sobre el que un
 * middleware/filtro/controlador puede cortocircuitar. Puramente funcional —
 * sin I/O propia, la verificación se delega en la instancia Captcha
 * inyectada.
 *
 * El paquete se distribuye con cero dependencias y por tanto jamás
 * implementa una interfaz de framework. `captcha install` emite el pegamento
 * por host (Filter de CodeIgniter, middleware de Laravel, listener de kernel
 * de Symfony, middleware de CakePHP, action filter de Yii y un drop-in de PHP
 * plano) sin editar nunca un fichero de la aplicación anfitriona.
 */
final readonly class CaptchaGuard
{
    public function __construct(private Captcha $captcha) {}

    /**
     * Estado HTTP sugerido para cada resultado de verificación (200 / 422 / 429).
     * Delega en el mapa de estados que posee GuardDecision.
     */
    public static function httpStatus(Status $status): int
    {
        return GuardDecision::httpStatus($status);
    }

    /**
     * Decide sobre los campos enviados de una petición (normalmente todo el
     * cuerpo POST).
     *
     * Un id de reto vacío o no cadena es el caso "sin envío": con
     * $requireSubmission=false devuelve la decisión idle (el formulario se
     * está renderizando y la petición debe seguir fluyendo); con true rechaza
     * al momento — un POST que omite el campo oculto es un intento como
     * cualquier otro y no debe saltarse el captcha omitiendo el id.
     *
     * ADVERTENCIA: el default ($requireSubmission=false) responde SIEMPRE
     * idle (permitido) cuando no hay id. Este guard es puro y no puede ver
     * el método HTTP, así que un POST entregado a decide() con el default
     * pasaría sin captcha. Sobre cualquier cosa que sea o pueda ser un POST
     * hay que pasar requireSubmission: true. (Captcha::check() no comparte
     * esta trampa: filtra por el propio método de la petición y solo verifica
     * POST reales.)
     *
     * @param array<string, mixed> $data Campos enviados de la petición.
     * @param bool $requireSubmission True cuando quien llama sabe que se
     *                                esperaba un captcha (p. ej. la petición
     *                                es POST).
     */
    public function decide(array $data, bool $requireSubmission = false): GuardDecision
    {
        $id = $data[$this->captcha->config()->idField] ?? '';

        /*
        *  Sin reto no hay verificación que hacer: el mismo contrato de
        *  verify() con id vacío — esta rama no toca limiter ni storage.
        */
        if (!is_string($id) || $id === '') {
            return $requireSubmission ? GuardDecision::missing() : GuardDecision::idle();
        }

        return GuardDecision::fromResult($this->captcha->verifyRequest($data));
    }
}
