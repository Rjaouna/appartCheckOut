<?php

namespace App\EventSubscriber;

use App\Security\LoginFormAuthenticator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class LoginAttemptSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $session = $request->getSession();
        $exception = $event->getException();

        if ($exception instanceof CustomUserMessageAuthenticationException) {
            $message = $exception->getMessageKey();
            if (str_contains($message, 'Merci de renseigner') || str_contains($message, 'Connexion temporairement bloquée')) {
                return;
            }
        }

        $attempts = (int) $session->get(LoginFormAuthenticator::LOGIN_ATTEMPTS_SESSION_KEY, 0) + 1;
        if ($attempts >= LoginFormAuthenticator::LOGIN_MAX_ATTEMPTS) {
            $session->set(LoginFormAuthenticator::LOGIN_ATTEMPTS_SESSION_KEY, 0);
            $session->set(
                LoginFormAuthenticator::LOGIN_BLOCKED_UNTIL_SESSION_KEY,
                (new \DateTimeImmutable(sprintf('+%d seconds', LoginFormAuthenticator::LOGIN_BLOCK_SECONDS)))->getTimestamp()
            );

            $session->set(
                SecurityRequestAttributes::AUTHENTICATION_ERROR,
                new CustomUserMessageAuthenticationException(
                    sprintf(
                        'Connexion temporairement bloquée. Réessayez dans %d minute%s.',
                        (int) ceil(LoginFormAuthenticator::LOGIN_BLOCK_SECONDS / 60),
                        LoginFormAuthenticator::LOGIN_BLOCK_SECONDS / 60 > 1 ? 's' : ''
                    )
                )
            );

            return;
        }

        $session->set(LoginFormAuthenticator::LOGIN_ATTEMPTS_SESSION_KEY, $attempts);
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $session = $event->getRequest()->getSession();
        $session->remove(LoginFormAuthenticator::LOGIN_ATTEMPTS_SESSION_KEY);
        $session->remove(LoginFormAuthenticator::LOGIN_BLOCKED_UNTIL_SESSION_KEY);
    }
}
