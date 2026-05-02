<?php

namespace App\Controller;

use App\Entity\Apartment;
use App\Entity\ServiceOffer;
use App\Entity\User;
use App\Enum\ApartmentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    private const TENANT_ACCESS_SESSION_KEY = 'tenant_access_apartments';
    private const DEFAULT_EMPLOYEE_ENTRY_CODE = '2580';

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
        return $this->redirectToRoute('app_home');
    }

    #[Route('/acces-equipe', name: 'employee_entry_verify', methods: ['POST'])]
    public function verifyEmployeeEntry(Request $request): Response
    {
        $submittedCode = preg_replace('/\D+/', '', (string) $request->request->get('entryCode')) ?? '';
        if ($submittedCode === '') {
            return $this->json([
                'success' => false,
                'message' => 'Saisissez votre code d’accès équipe.',
            ], 422);
        }

        if (!hash_equals($this->getEmployeeEntryCode(), $submittedCode)) {
            return $this->json([
                'success' => false,
                'message' => 'Code équipe invalide.',
            ], 422);
        }

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
        if ($apartment->getStatus() !== ApartmentStatus::Active) {
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
        $configuredCode = $_ENV['EMPLOYEE_ENTRY_CODE'] ?? $_SERVER['EMPLOYEE_ENTRY_CODE'] ?? self::DEFAULT_EMPLOYEE_ENTRY_CODE;
        $normalizedCode = preg_replace('/\D+/', '', (string) $configuredCode) ?? '';

        return $normalizedCode !== '' ? $normalizedCode : self::DEFAULT_EMPLOYEE_ENTRY_CODE;
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

        if ($request->isMethod('POST')) {
            $submittedCode = trim((string) $request->request->get('accessCode'));
            if ($submittedCode === '') {
                $errorMessage = 'Renseignez le code de la boîte à clés ou le code porte.';
            } else {
                $apartment = $this->findApartmentByAccessCode($submittedCode, $entityManager);
                if ($apartment instanceof Apartment) {
                    $session->set(self::TENANT_ACCESS_SESSION_KEY, [
                        $apartment->getId() => true,
                    ]);
                }

                if (!$apartment instanceof Apartment) {
                    $errorMessage = 'Aucun appartement actif ne correspond à ce code.';
                }
            }
        }

        return [
            'submittedCode' => $submittedCode,
            'errorMessage' => $errorMessage,
            'apartment' => $apartment,
        ];
    }
}
