<?php

namespace App\Controller;

use App\Entity\Apartment;
use App\Entity\ServiceOffer;
use App\Entity\User;
use App\Enum\ApartmentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    private const TENANT_ACCESS_SESSION_KEY = 'tenant_access_apartments';
    private const TENANT_LOOKUP_ATTEMPTS_SESSION_KEY = 'tenant_lookup_attempts';
    private const TENANT_LOOKUP_BLOCKED_UNTIL_SESSION_KEY = 'tenant_lookup_blocked_until';
    private const TENANT_LOOKUP_MAX_ATTEMPTS = 3;
    private const TENANT_LOOKUP_BLOCK_SECONDS = 3600;

    private const DEFAULT_EMPLOYEE_ENTRY_CODE = '2580';
    public const EMPLOYEE_ENTRY_GRANTED_SESSION_KEY = 'employee_entry_granted';
    private const EMPLOYEE_ENTRY_ATTEMPTS_SESSION_KEY = 'employee_entry_attempts';
    private const EMPLOYEE_ENTRY_BLOCKED_UNTIL_SESSION_KEY = 'employee_entry_blocked_until';
    private const EMPLOYEE_ENTRY_MAX_ATTEMPTS = 5;
    private const EMPLOYEE_ENTRY_BLOCK_SECONDS = 900;

    #[Route('/', name: 'app_home', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->redirectToRoute('admin_dashboard');
            }

            return $this->redirectToRoute('employee_dashboard');
        }

        $viewData = $this->buildTenantLookupViewData($request, $entityManager);
        if (($viewData['apartment'] ?? null) instanceof Apartment) {
            return $this->redirectToRoute('tenant_apartment_show', ['id' => $viewData['apartment']->getId()]);
        }

        unset($viewData['apartment']);

        return $this->render('home/index.html.twig', $viewData);
    }

    #[Route('/locataire', name: 'tenant_lookup', methods: ['GET', 'POST'])]
    public function tenantLookup(Request $request, EntityManagerInterface $entityManager): Response
    {
        $viewData = $this->buildTenantLookupViewData($request, $entityManager);
        if (($viewData['apartment'] ?? null) instanceof Apartment) {
            return $this->redirectToRoute('tenant_apartment_show', ['id' => $viewData['apartment']->getId()]);
        }

        unset($viewData['apartment']);

        return $this->render('home/index.html.twig', $viewData);
    }

    #[Route('/acces-equipe', name: 'employee_entry_verify', methods: ['POST'])]
    public function verifyEmployeeEntry(Request $request): Response
    {
        $session = $request->getSession();
        $remainingBlockSeconds = $this->getBlockedSeconds($session, self::EMPLOYEE_ENTRY_BLOCKED_UNTIL_SESSION_KEY);
        if ($remainingBlockSeconds > 0) {
            return $this->json([
                'success' => false,
                'message' => $this->buildBlockedMessage($remainingBlockSeconds, 'Accès équipe temporairement bloqué'),
            ], 429);
        }

        $configuredCode = $this->getEmployeeEntryCode();
        if ($configuredCode === '') {
            return $this->json([
                'success' => false,
                'message' => 'Code équipe non configuré sur cet environnement.',
            ], 503);
        }

        $submittedCode = preg_replace('/\D+/', '', (string) $request->request->get('entryCode')) ?? '';
        if ($submittedCode === '') {
            return $this->json([
                'success' => false,
                'message' => 'Saisissez votre code d’accès équipe.',
            ], 422);
        }

        if (!hash_equals($configuredCode, $submittedCode)) {
            if ($this->registerFailedAttempt($session, self::EMPLOYEE_ENTRY_ATTEMPTS_SESSION_KEY, self::EMPLOYEE_ENTRY_BLOCKED_UNTIL_SESSION_KEY, self::EMPLOYEE_ENTRY_MAX_ATTEMPTS, self::EMPLOYEE_ENTRY_BLOCK_SECONDS)) {
                return $this->json([
                    'success' => false,
                    'message' => $this->buildBlockedMessage(self::EMPLOYEE_ENTRY_BLOCK_SECONDS, 'Accès équipe bloqué après plusieurs essais'),
                ], 429);
            }

            return $this->json([
                'success' => false,
                'message' => 'Code équipe invalide.',
            ], 422);
        }

        $this->clearAttemptState($session, self::EMPLOYEE_ENTRY_ATTEMPTS_SESSION_KEY, self::EMPLOYEE_ENTRY_BLOCKED_UNTIL_SESSION_KEY);
        $session->set(self::EMPLOYEE_ENTRY_GRANTED_SESSION_KEY, true);

        return $this->json([
            'success' => true,
            'redirect' => $this->generateUrl('app_login'),
            'message' => 'Accès équipe validé.',
        ]);
    }

    #[Route('/locataire/appartement/{id}', name: 'tenant_apartment_show', methods: ['GET'])]
    public function tenantApartment(Apartment $apartment, Request $request): Response
    {
        if (!$this->canAccessTenantApartment($apartment, $request)) {
            return $this->redirectToRoute('tenant_lookup');
        }

        $assignedEmployees = array_values(array_filter(
            $apartment->getAssignedEmployees()->toArray(),
            static fn (User $user): bool => $user->isActive()
        ));
        $serviceContactEmployee = $this->findServiceContactEmployee($assignedEmployees);

        return $this->render('public/tenant_apartment.html.twig', [
            'apartment' => $apartment,
            'assignedEmployees' => $assignedEmployees,
            'serviceContactEmployee' => $serviceContactEmployee,
            'serviceExtras' => $serviceContactEmployee instanceof User ? $this->buildTenantServiceExtras($apartment, $serviceContactEmployee) : [],
            'whatsAppShareUrl' => $this->buildApartmentWhatsAppShareUrl($apartment),
        ]);
    }

    private function canAccessTenantApartment(Apartment $apartment, Request $request): bool
    {
        if ($apartment->getStatus() !== ApartmentStatus::Active || !$apartment->isTenantAccessEnabled()) {
            return false;
        }

        $grantedApartments = $request->getSession()->get(self::TENANT_ACCESS_SESSION_KEY, []);
        if (!is_array($grantedApartments)) {
            return false;
        }

        $apartmentId = $apartment->getId();

        return $apartmentId !== null && ($grantedApartments[$apartmentId] ?? false) === true;
    }

    private function findApartmentByAccessCode(string $submittedCode, EntityManagerInterface $entityManager): ?Apartment
    {
        $normalizedCode = $this->normalizeAccessCode($submittedCode);
        if ($normalizedCode === '') {
            return null;
        }

        $apartments = $entityManager->getRepository(Apartment::class)->findBy(
            ['status' => ApartmentStatus::Active],
            ['id' => 'DESC']
        );

        foreach ($apartments as $apartment) {
            $buildingCode = $this->normalizeAccessCode($apartment->getBuildingAccessCode());
            $keyBoxCode = $this->normalizeAccessCode($apartment->getKeyBoxCode());

            if ($normalizedCode !== '' && ($normalizedCode === $buildingCode || $normalizedCode === $keyBoxCode)) {
                return $apartment;
            }
        }

        return null;
    }

    private function normalizeAccessCode(?string $code): string
    {
        if (!is_string($code)) {
            return '';
        }

        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($code)) ?? '');
    }

    private function buildApartmentWhatsAppShareUrl(Apartment $apartment): string
    {
        $message = sprintf(
            "Voici l'adresse de mon appartement : %s.\nMerci de garder les codes d'acces confidentiels.",
            $apartment->getFullAddress()
        );

        return 'https://wa.me/?text=' . rawurlencode($message);
    }

    /**
     * @param list<User> $assignedEmployees
     */
    private function findServiceContactEmployee(array $assignedEmployees): ?User
    {
        foreach ($assignedEmployees as $employee) {
            if ($employee->getPhoneNumber() && $employee->getServiceOffers()->exists(
                static fn (int $key, ServiceOffer $serviceOffer): bool => $serviceOffer->isApproved()
            )) {
                return $employee;
            }
        }

        foreach ($assignedEmployees as $employee) {
            if ($employee->getPhoneNumber()) {
                return $employee;
            }
        }

        return $assignedEmployees[0] ?? null;
    }

    /**
     * @return list<array{label:string, whatsAppUrl:string, title:string}>
     */
    private function buildTenantServiceExtras(Apartment $apartment, User $employee): array
    {
        $phoneNumber = preg_replace('/\D+/', '', $employee->getPhoneNumber() ?? '') ?? '';
        if ($phoneNumber === '') {
            return [];
        }

        $extras = [];
        foreach ($employee->getServiceOffers() as $serviceOffer) {
            if (!$serviceOffer instanceof ServiceOffer || !$serviceOffer->isApproved()) {
                continue;
            }

            $message = sprintf(
                "Bonjour %s, je suis le locataire de l'appartement %s au %s. Je souhaiterais faire une demande pour le service : %s.",
                $employee->getFullName(),
                $apartment->getName(),
                $apartment->getFullAddress(),
                $serviceOffer->getLabel()
            );

            $extras[] = [
                'label' => $serviceOffer->getLabel(),
                'whatsAppUrl' => 'https://wa.me/' . $phoneNumber . '?text=' . rawurlencode($message),
                'title' => sprintf('Faire une demande par WhatsApp a %s', $employee->getFullName()),
            ];
        }

        usort($extras, static fn (array $left, array $right): int => strcmp($left['label'], $right['label']));

        return $extras;
    }

    private function getEmployeeEntryCode(): string
    {
        $configuredCode = $_ENV['EMPLOYEE_ENTRY_CODE'] ?? $_SERVER['EMPLOYEE_ENTRY_CODE'] ?? '';
        $normalizedCode = preg_replace('/\D+/', '', (string) $configuredCode) ?? '';

        if ($normalizedCode !== '') {
            return $normalizedCode;
        }

        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';

        return $appEnv === 'dev' ? self::DEFAULT_EMPLOYEE_ENTRY_CODE : '';
    }

    /**
     * @return array{submittedCode:string,errorMessage:?string,apartment:?Apartment}
     */
    private function buildTenantLookupViewData(Request $request, EntityManagerInterface $entityManager): array
    {
        $session = $request->getSession();
        $session->remove(self::TENANT_ACCESS_SESSION_KEY);

        $submittedCode = '';
        $errorMessage = null;
        $apartment = null;
        $isTenantLookupBlocked = false;
        $remainingTenantLookupBlockSeconds = $this->getBlockedSeconds($session, self::TENANT_LOOKUP_BLOCKED_UNTIL_SESSION_KEY);

        if ($remainingTenantLookupBlockSeconds > 0) {
            $errorMessage = $this->buildBlockedMessage($remainingTenantLookupBlockSeconds, 'Trop de tentatives sur cet appareil');
            $isTenantLookupBlocked = true;
        } elseif ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_lookup', (string) $request->request->get('_token'))) {
                $errorMessage = 'Jeton de sécurité invalide. Rechargez la page puis recommencez.';
            }

            $submittedCode = trim((string) $request->request->get('accessCode'));
            if ($errorMessage !== null) {
                $isTenantLookupBlocked = false;
            } elseif ($submittedCode === '') {
                $errorMessage = 'Renseignez le code de la boîte à clés ou le code porte.';
            } else {
                $apartment = $this->findApartmentByAccessCode($submittedCode, $entityManager);
                if ($apartment instanceof Apartment) {
                    if (!$apartment->isTenantAccessEnabled()) {
                        $errorMessage = 'L’accès avec le code est actuellement bloqué pour ce logement.';
                        $apartment = null;
                    } else {
                        $session->set(self::TENANT_ACCESS_SESSION_KEY, [
                            $apartment->getId() => true,
                        ]);
                        $this->clearAttemptState($session, self::TENANT_LOOKUP_ATTEMPTS_SESSION_KEY, self::TENANT_LOOKUP_BLOCKED_UNTIL_SESSION_KEY);
                    }
                }

                if (!$apartment instanceof Apartment) {
                    if ($this->registerFailedAttempt($session, self::TENANT_LOOKUP_ATTEMPTS_SESSION_KEY, self::TENANT_LOOKUP_BLOCKED_UNTIL_SESSION_KEY, self::TENANT_LOOKUP_MAX_ATTEMPTS, self::TENANT_LOOKUP_BLOCK_SECONDS)) {
                        $errorMessage = $this->buildBlockedMessage(self::TENANT_LOOKUP_BLOCK_SECONDS, 'Trop de codes invalides saisis sur cet appareil');
                        $isTenantLookupBlocked = true;
                    } else {
                        $errorMessage = 'Code inexistant. Contactez votre agence.';
                    }
                }
            }
        }

        return [
            'submittedCode' => $submittedCode,
            'errorMessage' => $errorMessage,
            'apartment' => $apartment,
            'isTenantLookupBlocked' => $isTenantLookupBlocked,
        ];
    }

    private function registerFailedAttempt(SessionInterface $session, string $attemptKey, string $blockedUntilKey, int $maxAttempts, int $blockSeconds): bool
    {
        $attempts = (int) $session->get($attemptKey, 0) + 1;
        if ($attempts >= $maxAttempts) {
            $session->set($attemptKey, 0);
            $session->set($blockedUntilKey, (new \DateTimeImmutable(sprintf('+%d seconds', $blockSeconds)))->getTimestamp());

            return true;
        }

        $session->set($attemptKey, $attempts);

        return false;
    }

    private function clearAttemptState(SessionInterface $session, string $attemptKey, string $blockedUntilKey): void
    {
        $session->remove($attemptKey);
        $session->remove($blockedUntilKey);
    }

    private function getBlockedSeconds(SessionInterface $session, string $blockedUntilKey): int
    {
        $blockedUntil = (int) $session->get($blockedUntilKey, 0);
        if ($blockedUntil <= time()) {
            if ($blockedUntil > 0) {
                $session->remove($blockedUntilKey);
            }

            return 0;
        }

        return $blockedUntil - time();
    }

    private function buildBlockedMessage(int $remainingSeconds, string $prefix): string
    {
        $remainingMinutes = max(1, (int) ceil($remainingSeconds / 60));

        return sprintf('%s. Réessayez dans %d minute%s.', $prefix, $remainingMinutes, $remainingMinutes > 1 ? 's' : '');
    }
}
