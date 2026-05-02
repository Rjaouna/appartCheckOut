<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class AsyncCsrfSubscriber implements EventSubscriberInterface
{
    private const TOKEN_ID = 'async_action';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['validateAsyncCsrf', 10],
        ];
    }

    public function validateAsyncCsrf(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST') || !$request->isXmlHttpRequest()) {
            return;
        }

        $submittedToken = (string) ($request->headers->get('X-App-Csrf') ?? $request->request->get('_token', ''));
        if ($submittedToken === '') {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'message' => 'Jeton de sécurité manquant.',
            ], 403));

            return;
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $submittedToken))) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], 403));
        }
    }
}
