<?php

namespace App\Controller;

use App\Entity\Anomaly;
use App\Entity\Apartment;
use App\Entity\Checkout;
use App\Entity\CheckoutLine;
use App\Entity\EquipmentCatalog;
use App\Entity\Room;
use App\Entity\RoomEquipment;
use App\Entity\User;
use App\Enum\ApartmentStatus;
use App\Enum\CheckoutStatus;
use App\Enum\RoomType;
use App\Enum\AnomalyStatus;
use App\Service\AnomalyWorkflowManager;
use App\Service\CheckoutManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(EntityManagerInterface $entityManager): Response
    {
        return $this->render('admin/dashboard.html.twig', $this->buildDashboardData($entityManager));
    }

    #[Route('/anomalies', name: 'admin_anomalies', methods: ['GET'])]
    public function anomalies(EntityManagerInterface $entityManager): Response
    {
        $apartments = $entityManager->createQueryBuilder()
            ->select('DISTINCT apartment')
            ->from(Apartment::class, 'apartment')
            ->join(Anomaly::class, 'anomaly', 'WITH', 'anomaly.apartment = apartment AND anomaly.status != :closedStatus')
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->orderBy('apartment.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/anomalies.html.twig', [
            'apartments' => $apartments,
            'historyAnomalies' => $entityManager->getRepository(Anomaly::class)->findBy(['status' => AnomalyStatus::Closed], ['closedAt' => 'DESC'], 12),
        ]);
    }

    #[Route('/apartments/{id}/anomalies', name: 'admin_apartment_anomalies', methods: ['GET'])]
    public function apartmentAnomalies(Apartment $apartment, EntityManagerInterface $entityManager): Response
    {
        $anomalies = $entityManager->getRepository(Anomaly::class)->findBy(['apartment' => $apartment], ['createdAt' => 'DESC']);
        $repeatCounts = $this->buildApartmentRepeatCounts($apartment, $entityManager);

        return $this->render('admin/apartment_anomalies.html.twig', [
            'apartment' => $apartment,
            'anomalyGroups' => $this->buildAnomalyGroups($anomalies, $repeatCounts),
            'anomalyCount' => count($anomalies),
        ]);
    }

    #[Route('/anomalies/{id}', name: 'admin_anomaly_detail', methods: ['GET'])]
    public function anomalyDetail(Anomaly $anomaly, EntityManagerInterface $entityManager): Response
    {
        return $this->render('admin/anomaly_detail.html.twig', [
            'anomaly' => $anomaly,
            'repeatCount' => $this->countAnomalyOccurrences($anomaly, $entityManager),
        ]);
    }

    #[Route('/anomalies/{id}/delete', name: 'admin_anomaly_delete', methods: ['POST'])]
    public function deleteAnomaly(Anomaly $anomaly, Request $request, EntityManagerInterface $entityManager): JsonResponse|Response
    {
        if (!$this->isCsrfTokenValid('delete_anomaly_' . $anomaly->getId(), (string) $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Jeton de securite invalide.'], 422);
            }

            throw $this->createAccessDeniedException('Jeton de securite invalide.');
        }

        $redirectUrl = $this->generateUrl('admin_apartment_anomalies', ['id' => $anomaly->getApartment()?->getId()]);
        $photoPath = $anomaly->getPhotoPath();

        $entityManager->remove($anomaly);
        $entityManager->flush();

        $this->deleteAnomalyPhoto($photoPath);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'redirect' => $redirectUrl,
                'message' => 'Anomalie supprimee de la liste.',
            ]);
        }

        $this->addFlash('success', 'Anomalie supprimee de la liste.');

        return $this->redirect($redirectUrl);
    }

    #[Route('/anomalies/{id}/workflow', name: 'admin_anomaly_workflow_update', methods: ['POST'])]
    public function updateAnomalyWorkflow(Anomaly $anomaly, Request $request, EntityManagerInterface $entityManager, AnomalyWorkflowManager $workflowManager): JsonResponse
    {
        try {
            $actor = $this->getUser();
            $workflowManager->advance($anomaly, AnomalyStatus::from((string) $request->request->get('status')), $actor instanceof User ? $actor : null);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('admin/_anomaly_workflow_card.html.twig', ['anomaly' => $anomaly]),
            'message' => 'Workflow anomalie mis à jour.',
        ]);
    }

    #[Route('/anomalies/{id}/workflow/reset', name: 'admin_anomaly_workflow_reset', methods: ['POST'])]
    public function resetAnomalyWorkflow(Anomaly $anomaly, EntityManagerInterface $entityManager, AnomalyWorkflowManager $workflowManager): JsonResponse
    {
        $actor = $this->getUser();
        $workflowManager->reset($anomaly, $actor instanceof User ? $actor : null);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('admin/_anomaly_workflow_card.html.twig', ['anomaly' => $anomaly]),
            'message' => 'Workflow anomalie réinitialisé.',
        ]);
    }

    #[Route('/apartments', name: 'admin_apartments', methods: ['GET'])]
    public function apartments(EntityManagerInterface $entityManager): Response
    {
        return $this->render('admin/apartments.html.twig', $this->buildApartmentsPageData($entityManager));
    }

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function users(EntityManagerInterface $entityManager): Response
    {
        return $this->render('admin/users.html.twig', $this->buildUsersPageData($entityManager));
    }

    #[Route('/users', name: 'admin_user_create', methods: ['POST'])]
    public function createUser(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->request->get('email')));
        $password = (string) $request->request->get('password');

        if ($email === '' || $password === '' || trim((string) $request->request->get('fullName')) === '') {
            return new JsonResponse(['success' => false, 'message' => 'Nom, email et mot de passe sont obligatoires.'], 422);
        }

        if ($entityManager->getRepository(User::class)->findOneBy(['email' => $email]) instanceof User) {
            return new JsonResponse(['success' => false, 'message' => 'Cet email existe déjà.'], 422);
        }

        $user = (new User())
            ->setFullName(trim((string) $request->request->get('fullName')))
            ->setEmail($email)
            ->setRoles(['ROLE_EMPLOYEE'])
            ->setIsActive($request->request->getBoolean('isActive', true))
            ->setCanManageAnomalyWorkflow($request->request->getBoolean('canManageAnomalyWorkflow'));
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $entityManager->persist($user);
        $entityManager->flush();

        return $this->usersResponse($entityManager, 'Employé créé.');
    }

    #[Route('/users/{id}', name: 'admin_user_update', methods: ['POST'])]
    public function updateUser(User $user, Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse(['success' => false, 'message' => 'Les comptes administrateur ne se modifient pas depuis cet espace.'], 422);
        }

        $email = mb_strtolower(trim((string) $request->request->get('email')));
        if ($email === '' || trim((string) $request->request->get('fullName')) === '') {
            return new JsonResponse(['success' => false, 'message' => 'Nom et email sont obligatoires.'], 422);
        }

        $existing = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User && $existing->getId() !== $user->getId()) {
            return new JsonResponse(['success' => false, 'message' => 'Cet email existe déjà.'], 422);
        }

        $user
            ->setFullName(trim((string) $request->request->get('fullName')))
            ->setEmail($email)
            ->setRoles(['ROLE_EMPLOYEE'])
            ->setIsActive($request->request->getBoolean('isActive'))
            ->setCanManageAnomalyWorkflow($request->request->getBoolean('canManageAnomalyWorkflow'));

        $password = trim((string) $request->request->get('password'));
        if ($password !== '') {
            $user->setPassword($passwordHasher->hashPassword($user, $password));
        }

        $entityManager->flush();

        return $this->usersResponse($entityManager, 'Employé mis à jour.');
    }

    #[Route('/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $user, EntityManagerInterface $entityManager): JsonResponse
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse(['success' => false, 'message' => 'Les comptes administrateur ne se suppriment pas depuis cet espace.'], 422);
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            return new JsonResponse(['success' => false, 'message' => 'Tu ne peux pas supprimer le compte actuellement connecté.'], 422);
        }

        foreach ($user->getAssignedApartments()->toArray() as $apartment) {
            $user->removeAssignedApartment($apartment);
        }

        $entityManager->remove($user);
        $entityManager->flush();

        return $this->usersResponse($entityManager, 'Employé supprimé.');
    }

    #[Route('/apartments', name: 'admin_apartment_create', methods: ['POST'])]
    public function createApartment(Request $request, EntityManagerInterface $entityManager): Response
    {
        $addressLine1 = (string) $request->request->get('addressLine1');
        $city = (string) $request->request->get('city');
        $postalCode = trim((string) $request->request->get('postalCode'));

        $apartment = (new Apartment())
            ->setName((string) $request->request->get('name'))
            ->setReferenceCode($this->generateApartmentReference($entityManager))
            ->setAddressLine1($addressLine1)
            ->setAddressLine2($this->nullable($request->request->get('addressLine2')))
            ->setCity($city)
            ->setPostalCode($postalCode)
            ->setFloor($this->nullable($request->request->get('floor')))
            ->setDoorNumber($this->nullable($request->request->get('doorNumber')))
            ->setMailboxNumber($this->nullable($request->request->get('mailboxNumber')))
            ->setWazeLink($this->buildWazeLink($addressLine1, $city, $postalCode))
            ->setGoogleMapsLink($this->nullable($request->request->get('googleMapsLink')))
            ->setBuildingAccessCode($this->nullable($request->request->get('buildingAccessCode')))
            ->setKeyBoxCode($this->nullable($request->request->get('keyBoxCode')))
            ->setEntryInstructions((string) $request->request->get('entryInstructions', ''))
            ->setConditionStatus((string) $request->request->get('conditionStatus', 'Bon etat'))
            ->setBedroomCount((int) $request->request->get('bedroomCount', 0))
            ->setSleepsCount(0)
            ->setOwnerName($this->nullable($request->request->get('ownerName')))
            ->setOwnerPhone($this->nullable($request->request->get('ownerPhone')))
            ->setInternalNotes($this->nullable($request->request->get('internalNotes')))
            ->setStatus(ApartmentStatus::from((string) $request->request->get('status', ApartmentStatus::Active->value)))
            ->setIsInventoryPriority($request->request->getBoolean('isInventoryPriority'));

        $inventoryDueAt = $request->request->get('inventoryDueAt');
        if (is_string($inventoryDueAt) && $inventoryDueAt !== '') {
            $apartment->setInventoryDueAt(new \DateTimeImmutable($inventoryDueAt));
        }

        foreach ($entityManager->getRepository(User::class)->findBy(['id' => (array) $request->request->all('assignedEmployees')]) as $employee) {
            $apartment->addAssignedEmployee($employee);
        }

        $entityManager->persist($apartment);
        $entityManager->flush();

        return $this->redirectToRoute('admin_apartment_show', ['id' => $apartment->getId()]);
    }

    #[Route('/apartments/{id}', name: 'admin_apartment_show', methods: ['GET'])]
    public function showApartment(Apartment $apartment, EntityManagerInterface $entityManager): Response
    {
        return $this->render('admin/apartment_show.html.twig', $this->buildApartmentDetailData($apartment, $entityManager));
    }

    #[Route('/apartments/{id}/status', name: 'admin_apartment_status', methods: ['POST'])]
    public function updateApartmentStatus(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $apartment->setStatus(ApartmentStatus::from((string) $request->request->get('status', ApartmentStatus::Active->value)));
        $entityManager->flush();

        if ((string) $request->request->get('context') === 'apartments-list') {
            return new JsonResponse([
                'success' => true,
                'html' => $this->renderView('admin/_apartments_list.html.twig', $this->buildApartmentsPageData($entityManager)),
                'message' => 'Statut de l’appartement mis à jour.',
            ]);
        }

        return $this->structureResponse($apartment, $entityManager, 'Statut de l appartement mis a jour.');
    }

    #[Route('/apartments/{id}/delete', name: 'admin_apartment_delete', methods: ['POST'])]
    public function deleteApartment(Apartment $apartment, EntityManagerInterface $entityManager): JsonResponse
    {
        $hasCheckouts = $this->hasOpenCheckout($apartment, $entityManager);
        $hasAnomalies = $this->hasOpenAnomalies($apartment, $entityManager);

        if ($hasCheckouts || $hasAnomalies) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Suppression impossible : cet appartement possède déjà un historique de check-outs ou d’anomalies.',
            ], 422);
        }

        $entityManager->remove($apartment);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'redirect' => $this->generateUrl('admin_apartments'),
            'message' => 'Appartement supprime.',
        ]);
    }

    #[Route('/apartments/{id}/assign', name: 'admin_apartment_assign', methods: ['POST'])]
    public function assignApartment(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $employeeIds = array_map('intval', (array) $request->request->all('assignedEmployees'));
        foreach ($apartment->getAssignedEmployees()->toArray() as $employee) {
            $apartment->removeAssignedEmployee($employee);
        }
        foreach ($entityManager->getRepository(User::class)->findBy(['id' => $employeeIds]) as $employee) {
            $apartment->addAssignedEmployee($employee);
        }

        $entityManager->flush();

        return $this->structureResponse($apartment, $entityManager, 'Affectations mises à jour.');
    }

    #[Route('/apartments/{id}/priority', name: 'admin_apartment_priority', methods: ['POST'])]
    public function togglePriority(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $apartment->setIsInventoryPriority($request->request->getBoolean('isInventoryPriority'));
        $due = $request->request->get('inventoryDueAt');
        $apartment->setInventoryDueAt(is_string($due) && $due !== '' ? new \DateTimeImmutable($due) : null);
        $entityManager->flush();

        return $this->structureResponse($apartment, $entityManager, 'Priorité inventaire mise à jour.');
    }

    #[Route('/apartments/{id}/rooms', name: 'admin_room_create', methods: ['POST'])]
    public function createRoom(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $type = RoomType::from((string) $request->request->get('type'));
        $nextOrder = $apartment->getRooms()->count() + 1;

        $room = (new Room())
            ->setType($type)
            ->setName((string) $request->request->get('name', $type->label()))
            ->setDisplayOrder($nextOrder)
            ->setNotes($this->nullable($request->request->get('notes')));

        $apartment->addRoom($room);
        $entityManager->persist($room);
        $entityManager->flush();

        return $this->structureResponse($apartment, $entityManager, 'Piece ajoutee.');
    }

    #[Route('/catalog', name: 'admin_catalog_create', methods: ['POST'])]
    public function createCatalogEquipment(Request $request, EntityManagerInterface $entityManager): Response
    {
        $equipment = (new EquipmentCatalog())
            ->setName((string) $request->request->get('name'))
            ->setRoomType(RoomType::from((string) $request->request->get('roomType')))
            ->setDescription($this->nullable($request->request->get('description')))
            ->setIsRequired($request->request->getBoolean('isRequired', true))
            ->setIsActive(true);

        $entityManager->persist($equipment);
        $entityManager->flush();

        $apartment = $entityManager->getRepository(Apartment::class)->find((int) $request->request->get('apartmentId'));
        if ($request->isXmlHttpRequest() && $apartment instanceof Apartment) {
            return $this->structureResponse($apartment, $entityManager, 'Catalogue mis a jour.');
        }

        return $this->redirectToRoute('admin_apartment_show', ['id' => (int) $request->request->get('apartmentId')]);
    }

    #[Route('/rooms/{id}/equipments', name: 'admin_room_equipment_add', methods: ['POST'])]
    public function addRoomEquipment(Room $room, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $equipment = new RoomEquipment();
        $equipment
            ->setDisplayOrder($room->getRoomEquipments()->count() + 1)
            ->setNotes($this->nullable($request->request->get('notes')));

        $catalogId = (int) $request->request->get('catalogEquipmentId', 0);
        if ($catalogId > 0) {
            $catalogEquipment = $entityManager->getRepository(EquipmentCatalog::class)->find($catalogId);
            if ($catalogEquipment !== null) {
                $equipment
                    ->setCatalogEquipment($catalogEquipment)
                    ->setLabel($catalogEquipment->getName());
            }
        }

        if ($equipment->getLabel() === '') {
            $equipment->setLabel((string) $request->request->get('manualLabel'));
        }

        $room->addRoomEquipment($equipment);
        $entityManager->persist($equipment);
        $entityManager->flush();

        return $this->structureResponse($room->getApartment(), $entityManager, 'Equipement ajoute.');
    }

    #[Route('/room-equipments/{id}/delete', name: 'admin_room_equipment_delete', methods: ['POST'])]
    public function deleteRoomEquipment(RoomEquipment $equipment, EntityManagerInterface $entityManager): JsonResponse
    {
        $hasCheckoutHistory = $entityManager->getRepository(CheckoutLine::class)->count(['roomEquipment' => $equipment]) > 0;
        $hasAnomalyHistory = $entityManager->getRepository(Anomaly::class)->count(['roomEquipment' => $equipment]) > 0;

        $room = $equipment->getRoom();
        if (!$room instanceof Room || !$room->getApartment() instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Appartement introuvable.'], 404);
        }

        $apartment = $room->getApartment();
        if ($hasCheckoutHistory || $hasAnomalyHistory) {
            $equipment->setIsActive(false);
        } else {
            $entityManager->remove($equipment);
        }
        $entityManager->flush();

        return $this->structureResponse($apartment, $entityManager, 'Equipement supprime de la piece.');
    }

    #[Route('/apartments/{id}/checkouts', name: 'admin_checkout_create', methods: ['POST'])]
    public function createCheckout(Apartment $apartment, Request $request, EntityManagerInterface $entityManager, CheckoutManager $checkoutManager): JsonResponse
    {
        if ($this->hasOpenCheckout($apartment, $entityManager)) {
            return new JsonResponse(['success' => false, 'message' => 'Un check-out non terminé existe déjà pour cet appartement.'], 422);
        }

        $employee = $entityManager->getRepository(User::class)->find((int) $request->request->get('assignedTo'));
        if (!$employee instanceof User) {
            return new JsonResponse(['success' => false, 'message' => 'Employé introuvable.'], 422);
        }

        $scheduledAtRaw = (string) $request->request->get('scheduledAt');
        $scheduledAt = $scheduledAtRaw !== '' ? new \DateTimeImmutable($scheduledAtRaw) : new \DateTimeImmutable();
        $checkoutManager->createCheckout($apartment, $employee, (string) $request->request->get('priority', 'normal'), $scheduledAt);
        $entityManager->flush();

        if ((string) $request->request->get('context') === 'dashboard') {
            return new JsonResponse([
                'success' => true,
                'html' => $this->renderView('admin/_dashboard_content.html.twig', $this->buildDashboardData($entityManager)),
                'message' => 'Check-out créé et assigné.',
            ]);
        }

        return $this->structureResponse($apartment, $entityManager, 'Check-out créé et assigné.');
    }

    #[Route('/checkouts/{id}/schedule', name: 'admin_checkout_schedule_update', methods: ['POST'])]
    public function updateCheckoutSchedule(Checkout $checkout, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $scheduledAtRaw = trim((string) $request->request->get('scheduledAt'));
        if ($scheduledAtRaw === '') {
            return new JsonResponse(['success' => false, 'message' => 'La date prevue est obligatoire.'], 422);
        }

        try {
            $checkout->setScheduledAt(new \DateTimeImmutable($scheduledAtRaw));
        } catch (\Exception) {
            return new JsonResponse(['success' => false, 'message' => 'La date prevue est invalide.'], 422);
        }

        $entityManager->flush();

        $apartment = $checkout->getApartment();
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Appartement introuvable.'], 404);
        }

        return $this->structureResponse($apartment, $entityManager, 'Date du check-out mise a jour.');
    }

    #[Route('/checkouts/{id}/cancel', name: 'admin_checkout_cancel', methods: ['POST'])]
    public function cancelCheckout(Checkout $checkout, EntityManagerInterface $entityManager): JsonResponse
    {
        if (in_array($checkout->getStatus(), [CheckoutStatus::Completed, CheckoutStatus::Cancelled], true)) {
            return new JsonResponse(['success' => false, 'message' => 'Ce check-out ne peut plus etre annule.'], 422);
        }

        $checkout
            ->setStatus(CheckoutStatus::Cancelled)
            ->setPausedAt(null)
            ->setPauseReason(null)
            ->setBlockReason(null);

        $entityManager->flush();

        $apartment = $checkout->getApartment();
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Appartement introuvable.'], 404);
        }

        return $this->structureResponse($apartment, $entityManager, 'Check-out annule.');
    }

    private function structureResponse(Apartment $apartment, EntityManagerInterface $entityManager, string $message): JsonResponse
    {
        $html = $this->renderView('admin/_apartment_structure.html.twig', $this->buildApartmentDetailData($apartment, $entityManager));

        return new JsonResponse([
            'success' => true,
            'html' => $html,
            'message' => $message,
        ]);
    }

    private function usersResponse(EntityManagerInterface $entityManager, string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('admin/_users_content.html.twig', $this->buildUsersPageData($entityManager)),
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApartmentDetailData(Apartment $apartment, EntityManagerInterface $entityManager): array
    {
        $anomalies = $entityManager->getRepository(Anomaly::class)->findBy(['apartment' => $apartment], ['createdAt' => 'DESC']);

        return [
            'apartment' => $apartment,
            'employees' => $entityManager->getRepository(User::class)->findBy([], ['fullName' => 'ASC']),
            'catalog' => $entityManager->getRepository(EquipmentCatalog::class)->findBy(['isActive' => true], ['roomType' => 'ASC', 'name' => 'ASC']),
            'checkouts' => $entityManager->createQueryBuilder()
                ->select('checkout')
                ->from(Checkout::class, 'checkout')
                ->where('checkout.apartment = :apartment')
                ->andWhere('checkout.status != :cancelledStatus')
                ->setParameter('apartment', $apartment)
                ->setParameter('cancelledStatus', CheckoutStatus::Cancelled)
                ->orderBy('checkout.id', 'DESC')
                ->setMaxResults(10)
                ->getQuery()
                ->getResult(),
            'roomTypes' => RoomType::ordered(),
            'hasOpenCheckout' => $this->hasOpenCheckout($apartment, $entityManager),
            'anomalyCount' => count($anomalies),
            'anomalyGroups' => $this->buildAnomalyGroups($anomalies, $this->buildApartmentRepeatCounts($apartment, $entityManager)),
            'canDeleteApartment' => !$this->hasOpenCheckout($apartment, $entityManager)
                && !$this->hasOpenAnomalies($apartment, $entityManager),
        ];
    }

    private function hasOpenCheckout(Apartment $apartment, EntityManagerInterface $entityManager): bool
    {
        $openStatuses = [
            CheckoutStatus::Todo,
            CheckoutStatus::InProgress,
            CheckoutStatus::Paused,
            CheckoutStatus::PendingValidation,
            CheckoutStatus::Blocked,
        ];

        return $entityManager->createQueryBuilder()
            ->select('COUNT(checkout.id)')
            ->from(Checkout::class, 'checkout')
            ->where('checkout.apartment = :apartment')
            ->andWhere('checkout.status IN (:statuses)')
            ->setParameter('apartment', $apartment)
            ->setParameter('statuses', $openStatuses)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    private function hasOpenAnomalies(Apartment $apartment, EntityManagerInterface $entityManager): bool
    {
        return $entityManager->createQueryBuilder()
            ->select('COUNT(anomaly.id)')
            ->from(Anomaly::class, 'anomaly')
            ->where('anomaly.apartment = :apartment')
            ->andWhere('anomaly.status != :closedStatus')
            ->setParameter('apartment', $apartment)
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardData(EntityManagerInterface $entityManager): array
    {
        $apartmentRepository = $entityManager->getRepository(Apartment::class);
        $checkoutRepository = $entityManager->getRepository(Checkout::class);

        $apartmentsWithAnomalies = $entityManager->createQueryBuilder()
    ->select('DISTINCT apartment')
    ->from(Apartment::class, 'apartment')
    ->join(
        Anomaly::class,
        'anomaly',
        'WITH',
        'anomaly.apartment = apartment AND anomaly.status != :closedStatus'
    )
    ->setParameter('closedStatus', AnomalyStatus::Closed)
    ->orderBy('apartment.name', 'ASC')
    ->setMaxResults(8)
    ->getQuery()
    ->getResult();

        return [
            'apartmentCards' => $this->buildApartmentCards($apartmentRepository->findBy(['status' => ApartmentStatus::Active], ['isInventoryPriority' => 'DESC', 'inventoryDueAt' => 'ASC', 'name' => 'ASC']), $entityManager),
            'anomalyApartmentCards' => $this->buildApartmentCards($apartmentsWithAnomalies, $entityManager),
            'scheduledCheckouts' => $checkoutRepository->findBy(['status' => CheckoutStatus::Todo], ['scheduledAt' => 'ASC'], 8),
            'activeCheckouts' => $checkoutRepository->findBy(['status' => CheckoutStatus::InProgress], ['scheduledAt' => 'ASC'], 8),
            'finishedCheckouts' => $checkoutRepository->findBy(['status' => CheckoutStatus::Completed], ['completedAt' => 'DESC'], 8),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApartmentsPageData(EntityManagerInterface $entityManager): array
    {
        $apartments = $entityManager->getRepository(Apartment::class)->findBy([], ['isInventoryPriority' => 'DESC', 'inventoryDueAt' => 'ASC', 'id' => 'DESC']);

        $rows = $entityManager->createQueryBuilder()
            ->select('IDENTITY(anomaly.apartment) AS apartmentId, COUNT(anomaly.id) AS openAnomalyCount')
            ->from(Anomaly::class, 'anomaly')
            ->where('anomaly.status != :closedStatus')
            ->groupBy('anomaly.apartment')
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->getQuery()
            ->getArrayResult();

        $openAnomalyCounts = [];
        foreach ($rows as $row) {
            $apartmentId = (int) ($row['apartmentId'] ?? 0);
            if ($apartmentId > 0) {
                $openAnomalyCounts[$apartmentId] = (int) ($row['openAnomalyCount'] ?? 0);
            }
        }

        return [
            'apartments' => $apartments,
            'employees' => $entityManager->getRepository(User::class)->findBy([], ['fullName' => 'ASC']),
            'apartmentStatuses' => ApartmentStatus::cases(),
            'openAnomalyCounts' => $openAnomalyCounts,
        ];
    }

    /**
     * @return array{users:list<User>}
     */
    private function buildUsersPageData(EntityManagerInterface $entityManager): array
    {
        $users = array_values(array_filter(
            $entityManager->getRepository(User::class)->findBy([], ['fullName' => 'ASC']),
            static fn (User $user): bool => !in_array('ROLE_ADMIN', $user->getRoles(), true)
        ));

        return [
            'users' => $users,
        ];
    }

    private function getOpenCheckout(Apartment $apartment, EntityManagerInterface $entityManager): ?Checkout
    {
        $openStatuses = [
            CheckoutStatus::Todo,
            CheckoutStatus::InProgress,
            CheckoutStatus::Paused,
            CheckoutStatus::PendingValidation,
            CheckoutStatus::Blocked,
        ];

        return $entityManager->createQueryBuilder()
            ->select('checkout')
            ->from(Checkout::class, 'checkout')
            ->where('checkout.apartment = :apartment')
            ->andWhere('checkout.status IN (:statuses)')
            ->setParameter('apartment', $apartment)
            ->setParameter('statuses', $openStatuses)
            ->orderBy('checkout.scheduledAt', 'ASC')
            ->addOrderBy('checkout.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<Apartment> $apartments
     * @return list<array{apartment: Apartment, openCheckout: ?Checkout, anomalyCount: int, openAnomalyCount: int, assignedEmployeeNames: string, firstAssignedEmployeeId: ?int, canQuickLaunch: bool}>
     */
    private function buildApartmentCards(array $apartments, EntityManagerInterface $entityManager): array
    {
        $rows = $apartments === [] ? [] : $entityManager->createQueryBuilder()
            ->select('IDENTITY(anomaly.apartment) AS apartmentId, COUNT(anomaly.id) AS openAnomalyCount')
            ->from(Anomaly::class, 'anomaly')
            ->where('anomaly.status != :closedStatus')
            ->andWhere('anomaly.apartment IN (:apartments)')
            ->groupBy('anomaly.apartment')
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->setParameter('apartments', $apartments)
            ->getQuery()
            ->getArrayResult();

        $openAnomalyCounts = [];
        foreach ($rows as $row) {
            $apartmentId = (int) ($row['apartmentId'] ?? 0);
            if ($apartmentId > 0) {
                $openAnomalyCounts[$apartmentId] = (int) ($row['openAnomalyCount'] ?? 0);
            }
        }

        $cards = [];
        foreach ($apartments as $apartment) {
            $openCheckout = $this->getOpenCheckout($apartment, $entityManager);
            $assignedEmployees = array_values($apartment->getAssignedEmployees()->toArray());
            $employeeNames = array_map(static fn (User $user) => $user->getFullName(), $assignedEmployees);
            $openAnomalyCount = $openAnomalyCounts[$apartment->getId() ?? 0] ?? 0;

            $cards[] = [
                'apartment' => $apartment,
                'openCheckout' => $openCheckout,
                'anomalyCount' => $entityManager->getRepository(Anomaly::class)->count(['apartment' => $apartment]),
                'openAnomalyCount' => $openAnomalyCount,
                'assignedEmployeeNames' => $employeeNames !== [] ? implode(', ', $employeeNames) : 'Aucun employé assigné',
                'firstAssignedEmployeeId' => count($assignedEmployees) === 1 ? $assignedEmployees[0]->getId() : null,
                'canQuickLaunch' => $openCheckout === null && count($assignedEmployees) === 1,
            ];
        }

        return $cards;
    }

    /**
     * @param list<Anomaly> $anomalies
     * @param array<int, int> $repeatCounts
     * @return array<int, array{room: Room, anomalies: list<array{anomaly: Anomaly, repeatCount: int}>, count: int}>
     */
    private function buildAnomalyGroups(array $anomalies, array $repeatCounts = []): array
    {
        $groups = [];
        foreach ($anomalies as $anomaly) {
            $room = $anomaly->getRoom();
            if (!$room instanceof Room) {
                continue;
            }

            $roomId = $room->getId() ?? spl_object_id($room);
            if (!isset($groups[$roomId])) {
                $groups[$roomId] = [
                    'room' => $room,
                    'anomalies' => [],
                    'count' => 0,
                ];
            }

            $groups[$roomId]['anomalies'][] = [
                'anomaly' => $anomaly,
                'repeatCount' => $repeatCounts[$anomaly->getRoomEquipment()?->getId() ?? 0] ?? 1,
            ];
            ++$groups[$roomId]['count'];
        }

        return array_values($groups);
    }

    /**
     * @return array<int, int>
     */
    private function buildApartmentRepeatCounts(Apartment $apartment, EntityManagerInterface $entityManager): array
    {
        $rows = $entityManager->createQueryBuilder()
            ->select('IDENTITY(anomaly.roomEquipment) AS roomEquipmentId, COUNT(anomaly.id) AS occurrenceCount')
            ->from(Anomaly::class, 'anomaly')
            ->where('anomaly.apartment = :apartment')
            ->groupBy('anomaly.roomEquipment')
            ->setParameter('apartment', $apartment)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $roomEquipmentId = (int) ($row['roomEquipmentId'] ?? 0);
            if ($roomEquipmentId > 0) {
                $counts[$roomEquipmentId] = (int) ($row['occurrenceCount'] ?? 1);
            }
        }

        return $counts;
    }

    private function countAnomalyOccurrences(Anomaly $anomaly, EntityManagerInterface $entityManager): int
    {
        $roomEquipment = $anomaly->getRoomEquipment();
        $apartment = $anomaly->getApartment();
        if (!$roomEquipment instanceof RoomEquipment || !$apartment instanceof Apartment) {
            return 1;
        }

        return (int) $entityManager->createQueryBuilder()
            ->select('COUNT(item.id)')
            ->from(Anomaly::class, 'item')
            ->where('item.apartment = :apartment')
            ->andWhere('item.roomEquipment = :roomEquipment')
            ->setParameter('apartment', $apartment)
            ->setParameter('roomEquipment', $roomEquipment)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function nullable(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function buildWazeLink(string $addressLine1, string $city, string $postalCode = ''): string
    {
        $parts = array_filter([$addressLine1, $postalCode, $city], static fn (string $part): bool => trim($part) !== '');
        $query = rawurlencode(implode(' ', $parts));

        return 'https://www.waze.com/ul?q=' . $query;
    }

    private function generateApartmentReference(EntityManagerInterface $entityManager): string
    {
        do {
            $reference = 'APT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while ($entityManager->getRepository(Apartment::class)->findOneBy(['referenceCode' => $reference]) instanceof Apartment);

        return $reference;
    }

    private function deleteAnomalyPhoto(?string $photoPath): void
    {
        if (!is_string($photoPath) || $photoPath === '' || !str_starts_with($photoPath, '/uploads/anomalies/')) {
            return;
        }

        $fullPath = $this->getParameter('kernel.project_dir') . '/public' . $photoPath;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
