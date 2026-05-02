<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Controller\HomeController;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';
    public const LOGIN_ATTEMPTS_SESSION_KEY = 'login_attempts';
    public const LOGIN_BLOCKED_UNTIL_SESSION_KEY = 'login_blocked_until';
    public const LOGIN_MAX_ATTEMPTS = 5;
    public const LOGIN_BLOCK_SECONDS = 900;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $remainingBlockSeconds = $this->getBlockedSeconds($request);
        if ($remainingBlockSeconds > 0) {
            throw new CustomUserMessageAuthenticationException($this->buildBlockedMessage($remainingBlockSeconds));
        }

        $email = trim((string) $request->request->get('_username', ''));
        $password = (string) $request->request->get('_password', '');

        if ($email === '' || $password === '') {
            throw new CustomUserMessageAuthenticationException('Merci de renseigner votre email et votre mot de passe.');
        }

        $request->getSession()->set('_security.last_username', $email);

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token')),
                new RememberMeBadge(),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?RedirectResponse
    {
        $request->getSession()->remove(self::LOGIN_ATTEMPTS_SESSION_KEY);
        $request->getSession()->remove(self::LOGIN_BLOCKED_UNTIL_SESSION_KEY);
        $request->getSession()->remove(HomeController::EMPLOYEE_ENTRY_GRANTED_SESSION_KEY);

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        $roles = $token->getRoleNames();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return new RedirectResponse($this->urlGenerator->generate('admin_dashboard'));
        }

        return new RedirectResponse($this->urlGenerator->generate('employee_dashboard'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    private function getBlockedSeconds(Request $request): int
    {
        $blockedUntil = (int) $request->getSession()->get(self::LOGIN_BLOCKED_UNTIL_SESSION_KEY, 0);
        if ($blockedUntil <= time()) {
            if ($blockedUntil > 0) {
                $request->getSession()->remove(self::LOGIN_BLOCKED_UNTIL_SESSION_KEY);
            }

            return 0;
        }

        return $blockedUntil - time();
    }

    private function buildBlockedMessage(int $remainingSeconds): string
    {
        $remainingMinutes = max(1, (int) ceil($remainingSeconds / 60));

        return sprintf('Connexion temporairement bloquée. Réessayez dans %d minute%s.', $remainingMinutes, $remainingMinutes > 1 ? 's' : '');
    }
}
