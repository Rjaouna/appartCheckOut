<?php

namespace App\Controller;

use App\Entity\Anomaly;
use App\Entity\Apartment;
use App\Entity\Checkout;
use App\Entity\CheckoutLine;
use App\Entity\Room;
use App\Entity\User;
use App\Enum\AnomalyStatus;
use App\Enum\ApartmentStatus;
use App\Enum\CheckoutStatus;
use App\Enum\EquipmentCheckStatus;
use App\Service\AnomalyWorkflowManager;
use App\Service\CheckoutManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/employee')]
class EmployeeController extends AbstractController
{
    #[Route('', name: 'employee_dashboard', methods: ['GET'])]
    public function dashboard(EntityManagerInterface $entityManager): Response
    {
        return $this->render('employee/dashboard.html.twig', $this->buildDashboardData($entityManager));
    }

    #[Route('/dashboard/content', name: 'employee_dashboard_partial', methods: ['GET'])]
    public function dashboardPartial(EntityManagerInterface $entityManager): Response
    {
        return $this->render('employee/_dashboard_content.html.twig', $this->buildDashboardData($entityManager));
    }

    #[Route('/history', name: 'employee_history', methods: ['GET'])]
    public function history(EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $historyCheckouts = $entityManager->createQueryBuilder()
            ->select('checkout', 'apartment', 'anomalies', 'room', 'roomEquipment')
            ->from(Checkout::class, 'checkout')
            ->join('checkout.apartment', 'apartment')
            ->leftJoin('checkout.anomalies', 'anomalies')
            ->leftJoin('anomalies.room', 'room')
            ->leftJoin('anomalies.roomEquipment', 'roomEquipment')
            ->where('checkout.assignedTo = :user')
            ->andWhere('apartment.status = :apartmentStatus')
            ->andWhere('checkout.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('statuses', [
                CheckoutStatus::Completed,
                CheckoutStatus::Cancelled,
            ])
            ->orderBy('checkout.completedAt', 'DESC')
            ->addOrderBy('checkout.scheduledAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('employee/history.html.twig', [
            'historyCheckouts' => $historyCheckouts,
        ]);
    }

    #[Route('/calendar', name: 'employee_calendar', methods: ['GET'])]
    public function calendar(EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $todayStart = (new \DateTimeImmutable('today'))->setTime(0, 0);

        $scheduledCheckouts = $entityManager->createQueryBuilder()
            ->select('checkout', 'apartment')
            ->from(Checkout::class, 'checkout')
            ->join('checkout.apartment', 'apartment')
            ->where('checkout.assignedTo = :user')
            ->andWhere('apartment.status = :apartmentStatus')
            ->andWhere('checkout.scheduledAt IS NOT NULL')
            ->andWhere('checkout.scheduledAt >= :todayStart')
            ->andWhere('checkout.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('todayStart', $todayStart)
            ->setParameter('statuses', [
                CheckoutStatus::Todo,
                CheckoutStatus::InProgress,
                CheckoutStatus::Paused,
                CheckoutStatus::PendingValidation,
                CheckoutStatus::Blocked,
            ])
            ->orderBy('checkout.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('employee/calendar.html.twig', [
            'scheduledCheckouts' => $scheduledCheckouts,
            'todayStart' => $todayStart,
        ]);
    }

    #[Route('/anomalies', name: 'employee_anomalies', methods: ['GET'])]
    public function anomalies(EntityManagerInterface $entityManager): Response
    {
        return $this->render('employee/anomalies.html.twig', [
            'anomalies' => $this->findEmployeeAnomalies($entityManager),
        ]);
    }

    #[Route('/anomalies/{id}', name: 'employee_anomaly_detail', methods: ['GET'])]
    public function anomalyDetail(Anomaly $anomaly): Response
    {
        $this->denyAccessUnlessGrantedToAnomaly($anomaly);

        return $this->render('employee/anomaly_detail.html.twig', [
            'anomaly' => $anomaly,
        ]);
    }

    #[Route('/anomalies/{id}/workflow', name: 'employee_anomaly_workflow_update', methods: ['POST'])]
    public function updateAnomalyWorkflow(Anomaly $anomaly, Request $request, EntityManagerInterface $entityManager, AnomalyWorkflowManager $workflowManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToAnomalyWorkflow($anomaly);

        try {
            $actor = $this->getUser();
            $workflowManager->advance($anomaly, AnomalyStatus::from((string) $request->request->get('status')), $actor instanceof User ? $actor : null);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return $this->anomalyCardResponse($anomaly, 'Suivi de l anomalie mis a jour.');
    }

    #[Route('/anomalies/{id}/workflow/reset', name: 'employee_anomaly_workflow_reset', methods: ['POST'])]
    public function resetAnomalyWorkflow(Anomaly $anomaly, EntityManagerInterface $entityManager, AnomalyWorkflowManager $workflowManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToAnomalyWorkflow($anomaly);

        $actor = $this->getUser();
        $workflowManager->reset($anomaly, $actor instanceof User ? $actor : null);
        $entityManager->flush();

        return $this->anomalyCardResponse($anomaly, 'Suivi de l’anomalie réinitialisé.');
    }

    #[Route('/apartments', name: 'employee_apartments', methods: ['GET'])]
    public function apartments(EntityManagerInterface $entityManager): Response
    {
        return $this->render('employee/apartments.html.twig', [
            'apartments' => $this->findAssignedApartments($entityManager),
        ]);
    }

    #[Route('/apartments/{id}', name: 'employee_apartment_show', methods: ['GET'])]
    public function apartmentShow(Apartment $apartment): Response
    {
        $this->denyAccessUnlessGrantedToApartment($apartment);

        return $this->render('employee/apartment_show.html.twig', [
            'apartment' => $apartment,
        ]);
    }

    #[Route('/apartments/{id}/content', name: 'employee_apartment_content', methods: ['GET'])]
    public function apartmentContent(Apartment $apartment): Response
    {
        $this->denyAccessUnlessGrantedToApartment($apartment);

        return $this->render('employee/_apartment_detail_content.html.twig', [
            'apartment' => $apartment,
        ]);
    }

    #[Route('/apartments/{id}/field', name: 'employee_apartment_field_update', methods: ['POST'])]
    public function updateApartmentField(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToApartment($apartment);

        $field = (string) $request->request->get('field');
        $value = trim((string) $request->request->get('value'));

        try {
            $this->applyApartmentFieldUpdate($apartment, $field, $value);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('employee/_apartment_detail_content.html.twig', [
                'apartment' => $apartment,
            ]),
            'message' => 'Information appartement mise à jour.',
        ]);
    }

    #[Route('/checkouts/{id}', name: 'employee_checkout_show', methods: ['GET'])]
    public function showCheckout(Checkout $checkout): Response
    {
        $this->denyAccessUnlessGrantedToCheckout($checkout);

        return $this->render('employee/checkout_show.html.twig', [
            'checkout' => $checkout,
            'roomGroups' => $this->buildRoomGroups($checkout),
        ]);
    }

    #[Route('/checkouts/{checkout}/rooms/{room}', name: 'employee_checkout_room_show', methods: ['GET'])]
    public function showRoom(Checkout $checkout, Room $room): Response
    {
        $this->denyAccessUnlessGrantedToCheckout($checkout);

        $group = $this->findRoomGroup($checkout, $room);
        if ($group === null) {
            throw $this->createNotFoundException('Pièce introuvable pour ce check-out.');
        }

        return $this->render('employee/room_show.html.twig', [
            'checkout' => $checkout,
            'roomGroup' => $group,
            'checkStatuses' => EquipmentCheckStatus::cases(),
        ]);
    }

    #[Route('/checkouts/lines/{id}', name: 'employee_checkout_line_update', methods: ['POST'])]
    public function updateLine(CheckoutLine $line, Request $request, CheckoutManager $checkoutManager, EntityManagerInterface $entityManager): JsonResponse
    {
        $checkout = $line->getCheckout();
        if (!$checkout instanceof Checkout) {
            return new JsonResponse(['success' => false, 'message' => 'Check-out introuvable.'], 404);
        }

        $this->denyAccessUnlessGrantedToCheckout($checkout);

        try {
            $checkoutManager->updateLine(
                $line,
                EquipmentCheckStatus::from((string) $request->request->get('status')),
                $request->request->get('comment'),
                $request->files->get('photo'),
                $this->getUser(),
            );
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return $this->roomWorkspaceResponse($checkout, $line->getRoom(), 'Element mis a jour.');
    }

    #[Route('/checkouts/{id}/pause', name: 'employee_checkout_pause', methods: ['POST'])]
    public function pause(Checkout $checkout, Request $request, CheckoutManager $checkoutManager, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToCheckout($checkout);
        $checkoutManager->pause($checkout, (string) $request->request->get('reason'));
        $entityManager->flush();

        return $this->redirectResponse($checkout, 'Check-out mis en pause.');
    }

    #[Route('/checkouts/{id}/resume', name: 'employee_checkout_resume', methods: ['POST'])]
    public function resume(Checkout $checkout, CheckoutManager $checkoutManager, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToCheckout($checkout);
        $checkoutManager->resume($checkout);
        $entityManager->flush();

        return $this->redirectResponse($checkout, 'Check-out repris.');
    }

    #[Route('/checkouts/{id}/complete', name: 'employee_checkout_complete', methods: ['POST'])]
    public function complete(Checkout $checkout, CheckoutManager $checkoutManager, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToCheckout($checkout);

        try {
            $checkoutManager->complete($checkout);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return $this->redirectToDashboardResponse('Check-out terminé.');
    }

    private function roomWorkspaceResponse(Checkout $checkout, ?Room $room, string $message): JsonResponse
    {
        if (!$room instanceof Room) {
            return $this->redirectResponse($checkout, $message);
        }

        $group = $this->findRoomGroup($checkout, $room);
        if ($group === null) {
            return $this->redirectResponse($checkout, $message);
        }

        if ($group['totalCount'] > 0 && $group['checkedCount'] >= $group['totalCount']) {
            return new JsonResponse([
                'success' => true,
                'redirect' => $this->generateUrl('employee_checkout_show', ['id' => $checkout->getId()]),
                'message' => 'Pièce terminée. Retour à la liste des pièces.',
            ]);
        }

        $html = $this->renderView('employee/_room_workspace.html.twig', [
            'checkout' => $checkout,
            'checkStatuses' => EquipmentCheckStatus::cases(),
            'roomGroup' => $group,
        ]);

        return new JsonResponse([
            'success' => true,
            'html' => $html,
            'message' => $message,
        ]);
    }

    private function redirectResponse(Checkout $checkout, string $message): JsonResponse
    {
        $this->addFlash('success', $message);

        return new JsonResponse([
            'success' => true,
            'redirect' => $this->generateUrl('employee_checkout_show', ['id' => $checkout->getId()]),
            'message' => $message,
        ]);
    }

    private function redirectToDashboardResponse(string $message): JsonResponse
    {
        $this->addFlash('success', $message);

        return new JsonResponse([
            'success' => true,
            'redirect' => $this->generateUrl('employee_dashboard'),
            'message' => $message,
        ]);
    }

    private function anomalyCardResponse(Anomaly $anomaly, string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('employee/_anomaly_card.html.twig', [
                'anomaly' => $anomaly,
            ]),
            'message' => $message,
        ]);
    }

    private function denyAccessUnlessGrantedToCheckout(Checkout $checkout): void
    {
        $user = $this->getUser();
        if (
            !$user instanceof User
            || $checkout->getAssignedTo()?->getId() !== $user->getId()
            || $checkout->getApartment()?->getStatus() !== ApartmentStatus::Active
        ) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyAccessUnlessGrantedToApartment(Apartment $apartment): void
    {
        $user = $this->getUser();
        if (
            !$user instanceof User
            || $apartment->getStatus() !== ApartmentStatus::Active
            || !$apartment->getAssignedEmployees()->exists(static fn (int $key, User $employee) => $employee->getId() === $user->getId())
        ) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyAccessUnlessGrantedToAnomalyWorkflow(Anomaly $anomaly): void
    {
        $user = $this->getUser();
        $checkout = $anomaly->getCheckout();
        $apartment = $anomaly->getApartment();

        if (
            !$user instanceof User
            || !$user->canManageAnomalyWorkflow()
            || !$checkout instanceof Checkout
            || !$apartment instanceof Apartment
            || $checkout->getAssignedTo()?->getId() !== $user->getId()
            || $apartment->getStatus() !== ApartmentStatus::Active
        ) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyAccessUnlessGrantedToAnomaly(Anomaly $anomaly): void
    {
        $user = $this->getUser();
        $checkout = $anomaly->getCheckout();
        $apartment = $anomaly->getApartment();

        if (
            !$user instanceof User
            || !$checkout instanceof Checkout
            || !$apartment instanceof Apartment
            || $checkout->getAssignedTo()?->getId() !== $user->getId()
            || $apartment->getStatus() !== ApartmentStatus::Active
        ) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * @return array{
     *     user: User,
     *     checkouts: list<Checkout>,
     *     checkoutCount: int,
     *     todayCheckout: ?Checkout,
     *     nextCheckout: ?Checkout,
     *     historyPreview: list<Checkout>,
     *     scheduledPreview: list<Checkout>,
     *     anomaliesPreview: list<Anomaly>,
     *     anomalyCount: int,
     *     apartments: list<Apartment>
     * }
     */
    private function buildDashboardData(EntityManagerInterface $entityManager): array
    {
        /** @var User $user */
        $user = $this->getUser();
        $openStatuses = [
            CheckoutStatus::Todo,
            CheckoutStatus::InProgress,
            CheckoutStatus::Paused,
            CheckoutStatus::PendingValidation,
            CheckoutStatus::Blocked,
        ];

        $checkouts = $entityManager->createQueryBuilder()
            ->select('checkout', 'apartment')
            ->from(Checkout::class, 'checkout')
            ->join('checkout.apartment', 'apartment')
            ->where('checkout.assignedTo = :user')
            ->andWhere('apartment.status = :apartmentStatus')
            ->andWhere('checkout.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('statuses', $openStatuses)
            ->orderBy('checkout.scheduledAt', 'ASC')
            ->addOrderBy('checkout.id', 'DESC')
            ->getQuery()
            ->getResult();

        $historyPreview = $entityManager->createQueryBuilder()
            ->select('checkout', 'apartment')
            ->from(Checkout::class, 'checkout')
            ->join('checkout.apartment', 'apartment')
            ->where('checkout.assignedTo = :user')
            ->andWhere('apartment.status = :apartmentStatus')
            ->andWhere('checkout.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('statuses', [CheckoutStatus::Completed, CheckoutStatus::Cancelled])
            ->orderBy('checkout.completedAt', 'DESC')
            ->addOrderBy('checkout.scheduledAt', 'DESC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();

        $todayStart = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $todayEnd = $todayStart->modify('+1 day');
        $todayCheckout = null;
        $nextCheckout = null;
        $scheduledPreview = [];

        foreach ($checkouts as $checkout) {
            $scheduledAt = $checkout->getScheduledAt();
            if ($scheduledAt instanceof \DateTimeImmutable) {
                if ($scheduledAt >= $todayStart && $scheduledAt < $todayEnd && $todayCheckout === null) {
                    $todayCheckout = $checkout;
                }

                if ($scheduledAt >= $todayStart) {
                    $scheduledPreview[] = $checkout;
                }

                if ($scheduledAt >= $todayEnd && $nextCheckout === null) {
                    $nextCheckout = $checkout;
                }
            }
        }

        $anomaliesPreview = $entityManager->createQueryBuilder()
            ->select('anomaly', 'checkout', 'apartment', 'room', 'roomEquipment')
            ->from(Anomaly::class, 'anomaly')
            ->join('anomaly.checkout', 'checkout')
            ->join('anomaly.apartment', 'apartment')
            ->join('anomaly.room', 'room')
            ->join('anomaly.roomEquipment', 'roomEquipment')
            ->where('checkout.assignedTo = :user')
            ->andWhere('apartment.status = :apartmentStatus')
            ->andWhere('anomaly.status != :closedStatus')
            ->setParameter('user', $user)
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->orderBy('anomaly.createdAt', 'DESC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();

        $apartments = $this->findAssignedApartments($entityManager);

        return [
            'user' => $user,
            'checkouts' => $checkouts,
            'checkoutCount' => count($checkouts),
            'todayCheckout' => $todayCheckout,
            'todayCheckoutTimeLabel' => $todayCheckout?->getScheduledAt() ? $this->formatFrenchDateTime($todayCheckout->getScheduledAt(), 'HH\'h\'mm') : null,
            'nextCheckout' => $nextCheckout,
            'nextCheckoutDateLabel' => $nextCheckout?->getScheduledAt() ? $this->formatFrenchDateTime($nextCheckout->getScheduledAt(), 'EEEE d MMMM \'à\' HH\'h\'mm') : null,
            'historyPreview' => $historyPreview,
            'scheduledPreview' => array_slice($scheduledPreview, 0, 4),
            'anomaliesPreview' => $anomaliesPreview,
            'anomalyCount' => count($anomaliesPreview),
            'apartments' => $apartments,
        ];
    }

    /**
     * @return list<Apartment>
     */
    private function findAssignedApartments(EntityManagerInterface $entityManager): array
    {
        /** @var User $user */
        $user = $this->getUser();

        return $entityManager->createQueryBuilder()
            ->select('apartment')
            ->from(Apartment::class, 'apartment')
            ->join('apartment.assignedEmployees', 'employee')
            ->where('employee = :user')
            ->andWhere('apartment.status = :apartmentStatus')
            ->setParameter('user', $user)
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->orderBy('apartment.isInventoryPriority', 'DESC')
            ->addOrderBy('apartment.inventoryDueAt', 'ASC')
            ->addOrderBy('apartment.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Anomaly>
     */
    private function findEmployeeAnomalies(EntityManagerInterface $entityManager): array
    {
        /** @var User $user */
        $user = $this->getUser();

        return $entityManager->createQueryBuilder()
            ->select('anomaly', 'checkout', 'apartment', 'room', 'roomEquipment', 'statusHistory')
            ->from(Anomaly::class, 'anomaly')
            ->join('anomaly.checkout', 'checkout')
            ->join('anomaly.apartment', 'apartment')
            ->join('anomaly.room', 'room')
            ->join('anomaly.roomEquipment', 'roomEquipment')
            ->leftJoin('anomaly.statusHistories', 'statusHistory')
            ->where('checkout.assignedTo = :user')
            ->andWhere('apartment.status = :apartmentStatus')
            ->andWhere('anomaly.status != :closedStatus')
            ->setParameter('user', $user)
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->orderBy('anomaly.createdAt', 'DESC')
            ->addOrderBy('statusHistory.changedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function applyApartmentFieldUpdate(Apartment $apartment, string $field, string $value): void
    {
        $normalizedValue = $value === '' ? null : $value;

        match ($field) {
            'floor' => $apartment->setFloor($normalizedValue),
            'doorNumber' => $apartment->setDoorNumber($normalizedValue),
            'mailboxNumber' => $apartment->setMailboxNumber($normalizedValue),
            'buildingAccessCode' => $apartment->setBuildingAccessCode($normalizedValue),
            'keyBoxCode' => $apartment->setKeyBoxCode($normalizedValue),
            'googleMapsLink' => $apartment->setGoogleMapsLink($normalizedValue),
            'ownerPhone' => $apartment->setOwnerPhone($normalizedValue),
            'entryInstructions' => $apartment->setEntryInstructions($value === '' ? 'Aucune consigne pour le moment.' : $value),
            default => throw new \InvalidArgumentException('Champ non modifiable.'),
        };
    }

    private function formatFrenchDateTime(\DateTimeImmutable $dateTime, string $pattern): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                'fr_FR',
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::SHORT,
                $dateTime->getTimezone()->getName(),
                \IntlDateFormatter::GREGORIAN,
                $pattern
            );

            $formatted = $formatter->format($dateTime);
            if (is_string($formatted) && $formatted !== '') {
                return mb_convert_case($formatted, MB_CASE_LOWER, 'UTF-8');
            }
        }

        return $dateTime->format('d/m/Y H:i');
    }

    /**
     * @return array<int, array{room:\App\Entity\Room, lines: array<int, CheckoutLine>, checkedCount:int, anomalyCount:int, totalCount:int, completionPercent:int}>
     */
    private function buildRoomGroups(Checkout $checkout): array
    {
        $groups = [];
        foreach ($checkout->getLines() as $line) {
            $room = $line->getRoom();
            if ($room === null) {
                continue;
            }

            $roomId = $room->getId() ?? spl_object_id($room);
            if (!isset($groups[$roomId])) {
                $groups[$roomId] = [
                    'room' => $room,
                    'lines' => [],
                    'checkedCount' => 0,
                    'anomalyCount' => 0,
                    'totalCount' => 0,
                ];
            }

            $groups[$roomId]['lines'][] = $line;
            ++$groups[$roomId]['totalCount'];
            if ($line->getStatus() !== null) {
                ++$groups[$roomId]['checkedCount'];
                if ($line->getStatus() !== EquipmentCheckStatus::Ok) {
                    ++$groups[$roomId]['anomalyCount'];
                }
            }
        }

        foreach ($groups as &$group) {
            usort($group['lines'], static function (CheckoutLine $left, CheckoutLine $right): int {
                $leftPending = $left->getStatus() === null;
                $rightPending = $right->getStatus() === null;

                if ($leftPending !== $rightPending) {
                    return $leftPending ? -1 : 1;
                }

                return $left->getSequence() <=> $right->getSequence();
            });
        }
        unset($group);

        $groups = array_values($groups);
        foreach ($groups as &$group) {
            $group['completionPercent'] = $group['totalCount'] > 0
                ? (int) floor(($group['checkedCount'] / $group['totalCount']) * 100)
                : 0;
        }
        unset($group);

        usort($groups, static function (array $left, array $right): int {
            $leftCompleted = $left['totalCount'] > 0 && $left['checkedCount'] >= $left['totalCount'];
            $rightCompleted = $right['totalCount'] > 0 && $right['checkedCount'] >= $right['totalCount'];

            if ($leftCompleted !== $rightCompleted) {
                return $leftCompleted ? 1 : -1;
            }

            $leftPending = $left['totalCount'] - $left['checkedCount'];
            $rightPending = $right['totalCount'] - $right['checkedCount'];
            if ($leftPending !== $rightPending) {
                return $rightPending <=> $leftPending;
            }

            return ($left['room']->getDisplayOrder() <=> $right['room']->getDisplayOrder())
                ?: (($left['room']->getId() ?? 0) <=> ($right['room']->getId() ?? 0));
        });

        return $groups;
    }

    /**
     * @return array{room:\App\Entity\Room, lines: array<int, CheckoutLine>, checkedCount:int, anomalyCount:int, totalCount:int, completionPercent:int}|null
     */
    private function findRoomGroup(Checkout $checkout, Room $room): ?array
    {
        foreach ($this->buildRoomGroups($checkout) as $group) {
            if (($group['room']->getId() ?? null) === $room->getId()) {
                return $group;
            }
        }

        return null;
    }
}
