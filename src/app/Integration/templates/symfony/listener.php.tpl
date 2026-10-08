<?php

declare(strict_types=1);

namespace App\EventListener;

use Captcha\Captcha;
use Captcha\Security\CaptchaGuard;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Corta la petición cuando el captcha no pasa, como listener del kernel.
 *
 * Solo la petición principal y solo POST: en GET el reto aún no existe, y una
 * subpetición ejecutaría el listener varias veces por petición.
 *
 * requireSubmission: true no es opcional aquí: con false, un POST que omite el
 * campo oculto captcha_id se considera válido.
 *
 * La denegación se lanza como HttpException con el estado que sugiere el guard;
 * si tu aplicación prefiere reponer el formulario, mueve la lógica al
 * controlador del POST y llama a decide() allí.
 *
 * El atributo #[AsEventListener(event: KernelEvents::REQUEST)] registra el
 * listener solo con el autoconfigure de la receta. Solo si lo has desactivado,
 * etiqueta el servicio a mano (name: kernel.event_listener, event:
 * kernel.request) y quita el atributo: entrando por las dos vías, decide()
 * se ejecutaría dos veces y la segunda vería un reto ya consumido.
 */
#[AsEventListener(event: KernelEvents::REQUEST)]
final class CaptchaGuardListener
{
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->isMethod('POST')) {
            return;
        }

        $decision = (new CaptchaGuard(Captcha::instance()))
            ->decide($event->getRequest()->request->all(), requireSubmission: true);

        if ($decision->allowed) {
            return;
        }

        throw new HttpException($decision->httpStatus, $decision->message);
    }
}
