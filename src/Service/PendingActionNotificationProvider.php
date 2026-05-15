<?php

namespace App\Service;

use App\Entity\ApartmentReservation;
use App\Entity\AirbnbCheck;
use App\Entity\Checkout;
use App\Entity\ReservationCheckin;
use App\Entity\ServiceOffer;
use App\Entity\User;
use App\Enum\ApartmentStatus;
use App\Enum\CheckoutStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PendingActionNotificationProvider
{
    private const MAX_ACTIONS = 12;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<array{id:string,type:string,title:string,description:string,url:string,meta:string,priority:string}>
     */
    public function buildForUser(User $user): array
    {
        $actions = [];
        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);

        $this->appendCheckoutActions($actions, $isAdmin ? null : $user, $isAdmin);
        $this->appendCheckinActions($actions, $isAdmin ? null : $user, $isAdmin);
        $this->appendArrivalMessageActions($actions, $isAdmin ? null : $user, $isAdmin);

        if ($isAdmin) {
            $this->appendPendingServiceValidationActions($actions);
            $this->appendUnprocessedCheckinActions($actions);
            $this->appendAirbnbAuditActions($actions);
        } else {
            $this->appendEmployeePendingServiceActions($actions, $user);
        }

        return array_slice($actions, 0, self::MAX_ACTIONS);
    }

    /**
     * @param list<array{id:string,type:string,title:string,description:string,url:string,meta:string,priority:string}> $actions
     */
    private function appendCheckoutActions(array &$actions, ?User $employee, bool $isAdmin): void
    {
        $todayEnd = (new \DateTimeImmutable('today'))->setTime(23, 59, 59);
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('checkout', 'apartment', 'assignedTo')
            ->from(Checkout::class, 'checkout')
            ->join('checkout.apartment', 'apartment')
            ->leftJoin('checkout.assignedTo', 'assignedTo')
            ->where('apartment.status = :apartmentStatus')
            ->andWhere('checkout.status IN (:statuses)')
            ->andWhere('(checkout.scheduledAt IS NULL OR checkout.scheduledAt <= :todayEnd OR checkout.status IN (:activeStatuses))')
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('todayEnd', $todayEnd, Types::DATETIME_IMMUTABLE)
            ->setParameter('statuses', [
                CheckoutStatus::Todo,
                CheckoutStatus::InProgress,
                CheckoutStatus::Paused,
                CheckoutStatus::PendingValidation,
                CheckoutStatus::Blocked,
            ])
            ->setParameter('activeStatuses', [
                CheckoutStatus::InProgress,
                CheckoutStatus::Paused,
                CheckoutStatus::PendingValidation,
                CheckoutStatus::Blocked,
            ])
            ->orderBy('checkout.scheduledAt', 'ASC')
            ->addOrderBy('checkout.id', 'DESC')
            ->setMaxResults(5);

        if ($employee instanceof User) {
            $queryBuilder
                ->andWhere('checkout.assignedTo = :employee')
                ->setParameter('employee', $employee);
        }

        foreach ($queryBuilder->getQuery()->getResult() as $checkout) {
            if (!$checkout instanceof Checkout || !$checkout->getApartment()) {
                continue;
            }

            $scheduledAt = $checkout->getScheduledAt();
            $meta = $scheduledAt instanceof \DateTimeImmutable
                ? $scheduledAt->format('d/m/Y H:i')
                : 'Date non renseignée';

            $actions[] = [
                'id' => sprintf('checkout-%d-%s', $checkout->getId(), $checkout->getStatus()->value),
                'type' => 'checkout',
                'title' => $checkout->getStatus() === CheckoutStatus::Todo ? 'Check-out à lancer' : 'Check-out à reprendre',
                'description' => sprintf('%s · %s', $checkout->getApartment()->getName(), $checkout->getStatus()->label()),
                'url' => $this->urlGenerator->generate($isAdmin ? 'admin_checkout_show' : 'employee_checkout_show', ['id' => $checkout->getId()]),
                'meta' => $meta,
                'priority' => $checkout->getStatus() === CheckoutStatus::Blocked ? 'danger' : 'warning',
            ];
        }
    }

    /**
     * @param list<array{id:string,type:string,title:string,description:string,url:string,meta:string,priority:string}> $actions
     */
    private function appendCheckinActions(array &$actions, ?User $employee, bool $isAdmin): void
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('reservation', 'apartment', 'checkin')
            ->from(ApartmentReservation::class, 'reservation')
            ->join('reservation.apartment', 'apartment')
            ->leftJoin('reservation.checkin', 'checkin')
            ->where('apartment.status = :apartmentStatus')
            ->andWhere('reservation.arrivalDate <= :today')
            ->andWhere('reservation.departureDate >= :today')
            ->andWhere('checkin.id IS NULL')
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->orderBy('reservation.arrivalDate', 'ASC')
            ->addOrderBy('reservation.id', 'DESC')
            ->setMaxResults(5);

        if ($employee instanceof User) {
            $queryBuilder
                ->join('apartment.assignedEmployees', 'employee')
                ->andWhere('employee = :employee')
                ->setParameter('employee', $employee);
        }

        foreach ($queryBuilder->getQuery()->getResult() as $reservation) {
            if (!$reservation instanceof ApartmentReservation || !$reservation->getApartment()) {
                continue;
            }

            $actions[] = [
                'id' => sprintf('checkin-reservation-%d', $reservation->getId()),
                'type' => 'checkin',
                'title' => 'Check-in à traiter',
                'description' => sprintf('%s · %s', $reservation->getGuestName(), $reservation->getApartment()->getName()),
                'url' => $this->urlGenerator->generate($isAdmin ? 'admin_checkin_form' : 'employee_checkin_form', ['id' => $reservation->getId()]),
                'meta' => $reservation->getStayDateLabel(),
                'priority' => 'primary',
            ];
        }
    }

    /**
     * @param list<array{id:string,type:string,title:string,description:string,url:string,meta:string,priority:string}> $actions
     */
    private function appendArrivalMessageActions(array &$actions, ?User $employee, bool $isAdmin): void
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('reservation', 'apartment', 'checkin')
            ->from(ApartmentReservation::class, 'reservation')
            ->join('reservation.apartment', 'apartment')
            ->leftJoin('reservation.checkin', 'checkin')
            ->where('apartment.status = :apartmentStatus')
            ->andWhere('reservation.arrivalDate = :today')
            ->andWhere('reservation.accessMessageSentAt IS NULL')
            ->andWhere('checkin.id IS NULL')
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->orderBy('reservation.id', 'DESC')
            ->setMaxResults(4);

        if ($employee instanceof User) {
            $queryBuilder
                ->join('apartment.assignedEmployees', 'employee')
                ->andWhere('employee = :employee')
                ->setParameter('employee', $employee);
        }

        foreach ($queryBuilder->getQuery()->getResult() as $reservation) {
            if (!$reservation instanceof ApartmentReservation || !$reservation->getApartment()) {
                continue;
            }

            $actions[] = [
                'id' => sprintf('arrival-message-%d', $reservation->getId()),
                'type' => 'arrival',
                'title' => 'Message d’arrivée à envoyer',
                'description' => sprintf('%s · %s', $reservation->getGuestName(), $reservation->getApartment()->getName()),
                'url' => $this->urlGenerator->generate($isAdmin ? 'admin_arrivals' : 'employee_arrivals'),
                'meta' => 'Arrivée aujourd’hui',
                'priority' => 'warning',
            ];
        }
    }

    /**
     * @param list<array{id:string,type:string,title:string,description:string,url:string,meta:string,priority:string}> $actions
     */
    private function appendPendingServiceValidationActions(array &$actions): void
    {
        $serviceOffers = $this->entityManager->getRepository(ServiceOffer::class)->findBy(
            ['status' => ServiceOffer::STATUS_PENDING],
            ['createdAt' => 'DESC'],
            5
        );

        foreach ($serviceOffers as $serviceOffer) {
            if (!$serviceOffer instanceof ServiceOffer) {
                continue;
            }

            $createdBy = $serviceOffer->getCreatedBy();
            $url = $createdBy instanceof User && !in_array('ROLE_ADMIN', $createdBy->getRoles(), true)
                ? $this->urlGenerator->generate('admin_user_show', ['id' => $createdBy->getId()])
                : $this->urlGenerator->generate('admin_users');

            $actions[] = [
                'id' => sprintf('service-offer-%d-pending', $serviceOffer->getId()),
                'type' => 'service',
                'title' => 'Service à valider',
                'description' => $serviceOffer->getLabel(),
                'url' => $url,
                'meta' => $createdBy instanceof User ? $createdBy->getFullName() : 'Proposition employé',
                'priority' => 'primary',
            ];
        }
    }

    /**
     * @param list<array{id:string,type:string,title:string,description:string,url:string,meta:string,priority:string}> $actions
     */
    private function appendEmployeePendingServiceActions(array &$actions, User $employee): void
    {
        $serviceOffers = $this->entityManager->createQueryBuilder()
            ->select('serviceOffer')
            ->from(ServiceOffer::class, 'serviceOffer')
            ->where('serviceOffer.createdBy = :employee')
            ->andWhere('serviceOffer.status = :status')
            ->setParameter('employee', $employee)
            ->setParameter('status', ServiceOffer::STATUS_PENDING)
            ->orderBy('serviceOffer.createdAt', 'DESC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();

        foreach ($serviceOffers as $serviceOffer) {
            if (!$serviceOffer instanceof ServiceOffer) {
                continue;
            }

            $actions[] = [
                'id' => sprintf('employee-service-offer-%d-pending', $serviceOffer->getId()),
                'type' => 'service',
                'title' => 'Service en attente de validation',
                'description' => $serviceOffer->getLabel(),
                'url' => $this->urlGenerator->generate('employee_profile'),
                'meta' => 'Validation administrateur',
                'priority' => 'soft',
            ];
        }
    }

    /**
     * @param list<array{id:string,type:string,title:string,description:string,url:string,meta:string,priority:string}> $actions
     */
    private function appendUnprocessedCheckinActions(array &$actions): void
    {
        $checkins = $this->entityManager->createQueryBuilder()
            ->select('checkin', 'reservation', 'apartment')
            ->from(ReservationCheckin::class, 'checkin')
            ->leftJoin('checkin.reservation', 'reservation')
            ->leftJoin('reservation.apartment', 'apartment')
            ->where('checkin.processedAt IS NULL')
            ->orderBy('checkin.completedAt', 'DESC')
            ->addOrderBy('checkin.id', 'DESC')
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($checkins as $checkin) {
            if (!$checkin instanceof ReservationCheckin) {
                continue;
            }

            $reservation = $checkin->getReservation();
            $apartment = $reservation?->getApartment();

            $actions[] = [
                'id' => sprintf('checkin-police-%d-unprocessed', $checkin->getId()),
                'type' => 'checkin-police',
                'title' => 'Fiche check-in à traiter',
                'description' => sprintf('%s · %s', $checkin->getGuestName(), $apartment?->getName() ?? $checkin->getApartmentName()),
                'url' => $this->urlGenerator->generate('admin_checkin_show', ['id' => $checkin->getId()]),
                'meta' => $checkin->getCompletedAt()->format('d/m/Y H:i'),
                'priority' => 'warning',
            ];
        }
    }

    /**
     * @param list<array{id:string,type:string,title:string,description:string,url:string,meta:string,priority:string}> $actions
     */
    private function appendAirbnbAuditActions(array &$actions): void
    {
        $checks = $this->entityManager->createQueryBuilder()
            ->select('airbnbCheck', 'apartment')
            ->from(AirbnbCheck::class, 'airbnbCheck')
            ->join('airbnbCheck.apartment', 'apartment')
            ->where('airbnbCheck.status = :status')
            ->andWhere('airbnbCheck.score < 100')
            ->setParameter('status', AirbnbCheck::STATUS_COMPLETED)
            ->orderBy('airbnbCheck.completedAt', 'DESC')
            ->addOrderBy('airbnbCheck.id', 'DESC')
            ->setMaxResults(12)
            ->getQuery()
            ->getResult();

        $seenApartmentIds = [];
        foreach ($checks as $check) {
            if (!$check instanceof AirbnbCheck || !$check->getApartment()) {
                continue;
            }

            $apartmentId = $check->getApartment()->getId();
            if ($apartmentId === null || isset($seenApartmentIds[$apartmentId])) {
                continue;
            }
            $seenApartmentIds[$apartmentId] = true;

            $completedAt = $check->getCompletedAt();
            $actions[] = [
                'id' => sprintf('airbnb-check-%d-incomplete', $check->getId()),
                'type' => 'airbnb-check',
                'title' => 'Audit Airbnb à compléter',
                'description' => sprintf(
                    '%s · Score %d%% · %d point%s à corriger',
                    $check->getApartment()->getName(),
                    $check->getScore(),
                    $check->getMissingIssueCount(),
                    $check->getMissingIssueCount() > 1 ? 's' : ''
                ),
                'url' => $this->urlGenerator->generate('admin_airbnb_check_report_show', ['id' => $check->getId()]),
                'meta' => $completedAt instanceof \DateTimeImmutable ? $completedAt->format('d/m/Y H:i') : 'Audit enregistré',
                'priority' => $check->getScore() < 70 ? 'danger' : 'warning',
            ];

            if (count($seenApartmentIds) >= 4) {
                break;
            }
        }
    }
}
