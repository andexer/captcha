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
 * Dos filtros antes de verificar. El primero es el método: en GET el reto aún
 * no existe, porque se genera al renderizar el formulario, así que verificar en
 * la primera visita mataría el formulario y dejaría al usuario sin reto que
 * resolver. El segundo son las subpeticiones: un listener de kernel.request que
 * no distingue la principal de las internas se ejecutaría varias veces por
 * petición y podría lanzar el error sobre un fragmento que no tiene formulario
 * que reponer.
 *
 * requireSubmission: true no es opcional aquí. Con false, un POST que omite el
 * campo oculto captcha_id se considera válido, de modo que borrar ese input
 * bastaría para saltar la comprobación.
 *
 * La denegación se lanza como HttpException con el estado que sugiere el guard,
 * que deja en el mensaje la explicación en español lista para la página de
 * error. Si tu aplicación prefiere reponer el formulario en vez de mostrar un
 * 422, mueve esta lógica al controlador del POST y llama a decide() allí.
 *
 * El atributo #[AsEventListener(event: KernelEvents::REQUEST)] registra el
 * listener solo: con el autoconfigure que la receta trae por defecto no hay que
 * tocar config/services.yaml. Solo si lo has desactivado tendrás que etiquetar
 * el servicio a mano (name: kernel.event_listener, event: kernel.request) y, en
 * ese caso, quitar el atributo de la clase: entrando por las dos vías,
 * decide() se ejecutaría dos veces y la segunda vería un reto ya consumido.
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
