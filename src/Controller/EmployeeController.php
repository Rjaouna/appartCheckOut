<?php

namespace App\Controller;

use App\Entity\Anomaly;
use App\Entity\Apartment;
use App\Entity\Checkout;
use App\Entity\CheckoutLine;
use App\Entity\Room;
use App\Entity\ServiceOffer;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    #[Route('/profile', name: 'employee_profile', methods: ['GET'])]
    public function profile(EntityManagerInterface $entityManager): Response
    {
        return $this->render('employee/profile.html.twig', $this->buildProfileData($entityManager));
    }

    #[Route('/profile/photo', name: 'employee_profile_photo_update', methods: ['POST'])]
    public function updateProfilePhoto(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $photo = $request->files->get('photo');

        if (!$photo instanceof UploadedFile) {
            return new JsonResponse(['success' => false, 'message' => 'Ajoute une photo avant de valider.'], 422);
        }

        $previousPhotoPath = $user->getPhotoPath();
        $user->setPhotoPath($this->storeUserPhoto($photo));
        $entityManager->flush();
        $this->deleteUserPhoto($previousPhotoPath);

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('employee/_profile_content.html.twig', $this->buildProfileData($entityManager)),
            'message' => 'Photo de profil mise à jour.',
        ]);
    }

    #[Route('/profile/services/{id}/toggle', name: 'employee_profile_service_toggle', methods: ['POST'])]
    public function toggleProfileService(ServiceOffer $serviceOffer, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$serviceOffer->isApproved()) {
            return new JsonResponse(['success' => false, 'message' => 'Ce service n est pas encore validé.'], 422);
        }

        if (!$serviceOffer->isStandard() && $serviceOffer->getCreatedBy()?->getId() !== $user->getId()) {
            return new JsonResponse(['success' => false, 'message' => 'Service indisponible.'], 422);
        }

        if ($request->request->getBoolean('enabled')) {
            $user->addServiceOffer($serviceOffer);
        } else {
            $user->removeServiceOffer($serviceOffer);
        }

        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('employee/_profile_content.html.twig', $this->buildProfileData($entityManager)),
            'message' => 'Services mis a jour.',
        ]);
    }

    #[Route('/profile/services/suggest', name: 'employee_profile_service_suggest', methods: ['POST'])]
    public function suggestProfileService(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $label = $this->normalizeServiceOfferLabel((string) $request->request->get('label'));
        if ($label === '') {
            return new JsonResponse(['success' => false, 'message' => 'Renseigne le nom du service.'], 422);
        }

        $existingPending = $entityManager->createQueryBuilder()
            ->select('serviceOffer')
            ->from(ServiceOffer::class, 'serviceOffer')
            ->where('serviceOffer.createdBy = :user')
            ->andWhere('LOWER(serviceOffer.label) = :label')
            ->setParameter('user', $user)
            ->setParameter('label', mb_strtolower($label))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingPending instanceof ServiceOffer) {
            return new JsonResponse(['success' => false, 'message' => 'Ce service est deja propose sur ton profil.'], 422);
        }

        $serviceOffer = (new ServiceOffer())
            ->setLabel($label)
            ->setStatus(ServiceOffer::STATUS_PENDING)
            ->setIsStandard(false)
            ->setCreatedBy($user);

        $user->addServiceOffer($serviceOffer);
        $entityManager->persist($serviceOffer);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('employee/_profile_content.html.twig', $this->buildProfileData($entityManager)),
            'message' => 'Service propose pour validation.',
        ]);
    }

    #[Route('/profile/services/{id}/delete', name: 'employee_profile_service_delete', methods: ['POST'])]
    public function deleteProfileService(ServiceOffer $serviceOffer, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($serviceOffer->isStandard() || $serviceOffer->getCreatedBy()?->getId() !== $user->getId()) {
            return new JsonResponse(['success' => false, 'message' => 'Service indisponible.'], 422);
        }

        $user->removeServiceOffer($serviceOffer);
        $entityManager->remove($serviceOffer);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('employee/_profile_content.html.twig', $this->buildProfileData($entityManager)),
            'message' => 'Service supprime.',
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
            'activeServiceCount' => count($this->findEnabledEmployeeServices($user, $entityManager)),
        ];
    }

    /**
     * @return array{
     *     user: User,
     *     approvedStandardServices: list<ServiceOffer>,
     *     approvedCustomServices: list<ServiceOffer>,
     *     pendingCustomServices: list<ServiceOffer>
     * }
     */
    private function buildProfileData(EntityManagerInterface $entityManager): array
    {
        /** @var User $user */
        $user = $this->getUser();

        return [
            'user' => $user,
            'approvedStandardServices' => $entityManager->getRepository(ServiceOffer::class)->findBy(
                ['isStandard' => true, 'status' => ServiceOffer::STATUS_APPROVED],
                ['label' => 'ASC']
            ),
            'approvedCustomServices' => $entityManager->createQueryBuilder()
                ->select('serviceOffer')
                ->from(ServiceOffer::class, 'serviceOffer')
                ->where('serviceOffer.createdBy = :user')
                ->andWhere('serviceOffer.status = :status')
                ->andWhere('serviceOffer.isStandard = :isStandard')
                ->setParameter('user', $user)
                ->setParameter('status', ServiceOffer::STATUS_APPROVED)
                ->setParameter('isStandard', false)
                ->orderBy('serviceOffer.label', 'ASC')
                ->getQuery()
                ->getResult(),
            'pendingCustomServices' => $entityManager->createQueryBuilder()
                ->select('serviceOffer')
                ->from(ServiceOffer::class, 'serviceOffer')
                ->where('serviceOffer.createdBy = :user')
                ->andWhere('serviceOffer.status = :status')
                ->andWhere('serviceOffer.isStandard = :isStandard')
                ->setParameter('user', $user)
                ->setParameter('status', ServiceOffer::STATUS_PENDING)
                ->setParameter('isStandard', false)
                ->orderBy('serviceOffer.createdAt', 'DESC')
                ->getQuery()
                ->getResult(),
        ];
    }

    /**
     * @return list<ServiceOffer>
     */
    private function findEnabledEmployeeServices(User $user, EntityManagerInterface $entityManager): array
    {
        return $entityManager->createQueryBuilder()
            ->select('serviceOffer')
            ->from(ServiceOffer::class, 'serviceOffer')
            ->join('serviceOffer.users', 'user')
            ->where('user = :user')
            ->andWhere('serviceOffer.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', ServiceOffer::STATUS_APPROVED)
            ->orderBy('serviceOffer.label', 'ASC')
            ->getQuery()
            ->getResult();
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

    private function normalizeServiceOfferLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');

        return mb_substr($label, 0, 160);
    }

    private function storeUserPhoto(UploadedFile $photo): string
    {
        $targetDir = $this->getParameter('kernel.project_dir') . '/public/uploads/users';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $safeName = pathinfo($photo->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $safeName) ?: 'employe';
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(4)), $photo->guessExtension() ?: 'jpg');

        $photo->move($targetDir, $filename);

        return '/uploads/users/' . $filename;
    }

    private function deleteUserPhoto(?string $photoPath): void
    {
        if (!is_string($photoPath) || $photoPath === '' || !str_starts_with($photoPath, '/uploads/users/')) {
            return;
        }

        $fullPath = $this->getParameter('kernel.project_dir') . '/public' . $photoPath;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
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
