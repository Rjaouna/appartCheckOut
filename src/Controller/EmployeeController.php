<?php

namespace App\Controller;

use App\Entity\Checkout;
use App\Entity\CheckoutLine;
use App\Entity\Room;
use App\Entity\User;
use App\Enum\ApartmentStatus;
use App\Enum\CheckoutStatus;
use App\Enum\EquipmentCheckStatus;
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
            throw $this->createNotFoundException('Piece introuvable pour ce check-out.');
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

        return $this->redirectToDashboardResponse('Check-out termine.');
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

    /**
     * @return array{checkouts:list<Checkout>, apartments:iterable<mixed>, statusOverview:list<array{label:string, count:int, tone:string}>}
     */
    private function buildDashboardData(EntityManagerInterface $entityManager): array
    {
        /** @var User $user */
        $user = $this->getUser();

        $checkouts = $entityManager->createQueryBuilder()
            ->select('checkout')
            ->from(Checkout::class, 'checkout')
            ->join('checkout.apartment', 'apartment')
            ->where('checkout.assignedTo = :user')
            ->andWhere('apartment.status = :apartmentStatus')
            ->andWhere('checkout.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('statuses', [
                CheckoutStatus::Todo,
                CheckoutStatus::InProgress,
                CheckoutStatus::Paused,
                CheckoutStatus::PendingValidation,
                CheckoutStatus::Blocked,
            ])
            ->orderBy('checkout.scheduledAt', 'ASC')
            ->addOrderBy('checkout.id', 'DESC')
            ->getQuery()
            ->getResult();

        return [
            'checkouts' => $checkouts,
            'apartments' => $user->getAssignedApartments(),
            'statusOverview' => $this->buildStatusOverview($checkouts),
        ];
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

        $groups = array_values($groups);
        foreach ($groups as &$group) {
            $group['completionPercent'] = $group['totalCount'] > 0
                ? (int) floor(($group['checkedCount'] / $group['totalCount']) * 100)
                : 0;
        }

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

    /**
     * @param list<Checkout> $checkouts
     * @return list<array{label:string, count:int, tone:string}>
     */
    private function buildStatusOverview(array $checkouts): array
    {
        $counts = [
            CheckoutStatus::Todo->value => 0,
            CheckoutStatus::InProgress->value => 0,
            CheckoutStatus::Paused->value => 0,
            CheckoutStatus::PendingValidation->value => 0,
            CheckoutStatus::Blocked->value => 0,
        ];

        foreach ($checkouts as $checkout) {
            $counts[$checkout->getStatus()->value] = ($counts[$checkout->getStatus()->value] ?? 0) + 1;
        }

        return [
            [
                'label' => 'Total',
                'count' => count($checkouts),
                'tone' => 'dark',
            ],
            [
                'label' => CheckoutStatus::Todo->label(),
                'count' => $counts[CheckoutStatus::Todo->value],
                'tone' => 'accent',
            ],
            [
                'label' => CheckoutStatus::InProgress->label(),
                'count' => $counts[CheckoutStatus::InProgress->value],
                'tone' => 'blue',
            ],
            [
                'label' => CheckoutStatus::Paused->label(),
                'count' => $counts[CheckoutStatus::Paused->value],
                'tone' => 'warning',
            ],
            [
                'label' => CheckoutStatus::PendingValidation->label(),
                'count' => $counts[CheckoutStatus::PendingValidation->value],
                'tone' => 'success',
            ],
            [
                'label' => CheckoutStatus::Blocked->label(),
                'count' => $counts[CheckoutStatus::Blocked->value],
                'tone' => 'danger',
            ],
        ];
    }
}
