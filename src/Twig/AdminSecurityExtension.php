<?php

namespace App\Twig;

use App\Entity\Apartment;
use App\Enum\ApartmentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AdminSecurityExtension extends AbstractExtension
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_blocked_tenant_access_apartments', [$this, 'getBlockedTenantAccessApartments']),
        ];
    }

    /**
     * @return list<Apartment>
     */
    public function getBlockedTenantAccessApartments(): array
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return [];
        }

        return $this->entityManager->getRepository(Apartment::class)->findBy(
            [
                'status' => ApartmentStatus::Active,
                'isTenantAccessEnabled' => false,
            ],
            [
                'tenantAccessLockedAt' => 'DESC',
                'name' => 'ASC',
            ]
        );
    }
}
