<?php

namespace App\Controller;

use App\Entity\Apartment;
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

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->redirectToRoute('admin_dashboard');
            }

            return $this->redirectToRoute('employee_dashboard');
        }

        return $this->render('home/index.html.twig');
    }

    #[Route('/locataire', name: 'tenant_lookup', methods: ['GET', 'POST'])]
    public function tenantLookup(Request $request, EntityManagerInterface $entityManager): Response
    {
        $session = $request->getSession();
        $session->remove(self::TENANT_ACCESS_SESSION_KEY);

        $submittedCode = '';
        $errorMessage = null;

        if ($request->isMethod('POST')) {
            $submittedCode = trim((string) $request->request->get('accessCode'));
            if ($submittedCode === '') {
                $errorMessage = 'Renseigne le code de la boite a cles ou le code porte.';
            } else {
                $apartment = $this->findApartmentByAccessCode($submittedCode, $entityManager);
                if ($apartment instanceof Apartment) {
                    $session->set(self::TENANT_ACCESS_SESSION_KEY, [
                        $apartment->getId() => true,
                    ]);

                    return $this->redirectToRoute('tenant_apartment_show', ['id' => $apartment->getId()]);
                }

                $errorMessage = 'Aucun appartement actif ne correspond a ce code.';
            }
        }

        return $this->render('public/tenant_lookup.html.twig', [
            'submittedCode' => $submittedCode,
            'errorMessage' => $errorMessage,
        ]);
    }

    #[Route('/locataire/appartement/{id}', name: 'tenant_apartment_show', methods: ['GET'])]
    public function tenantApartment(Apartment $apartment, Request $request): Response
    {
        if (!$this->canAccessTenantApartment($apartment, $request)) {
            return $this->redirectToRoute('tenant_lookup');
        }

        return $this->render('public/tenant_apartment.html.twig', [
            'apartment' => $apartment,
            'assignedEmployees' => array_values(array_filter(
                $apartment->getAssignedEmployees()->toArray(),
                static fn (User $user): bool => $user->isActive()
            )),
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
}
