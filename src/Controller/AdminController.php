<?php

namespace App\Controller;

use App\Entity\Anomaly;
use App\Entity\Apartment;
use App\Entity\ApartmentAccessStep;
use App\Entity\Checkout;
use App\Entity\CheckoutLine;
use App\Entity\EquipmentCatalog;
use App\Entity\Room;
use App\Entity\RoomEquipment;
use App\Entity\ServiceOffer;
use App\Entity\User;
use App\Enum\EquipmentCheckStatus;
use App\Enum\ApartmentStatus;
use App\Enum\CheckoutStatus;
use App\Enum\RoomType;
use App\Enum\AnomalyStatus;
use App\Service\AnomalyWorkflowManager;
use App\Service\CheckoutManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    private const APARTMENT_DETAIL_SECTIONS = ['checkout', 'access', 'apartment-access', 'assignment', 'rooms', 'anomalies', 'settings'];

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

    #[Route('/users/{id}', name: 'admin_user_show', methods: ['GET'])]
    public function showUser(User $user, EntityManagerInterface $entityManager): Response
    {
        $this->assertManageableEmployee($user);

        return $this->render('admin/user_show.html.twig', $this->buildUserDetailData($user, $entityManager));
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
            ->setPhoneNumber($this->nullable($request->request->get('phoneNumber')))
            ->setRoles(['ROLE_EMPLOYEE'])
            ->setIsActive($request->request->getBoolean('isActive', true))
            ->setCanManageAnomalyWorkflow($request->request->getBoolean('canManageAnomalyWorkflow'));
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $photo = $request->files->get('photo');
        if ($photo instanceof UploadedFile) {
            $user->setPhotoPath($this->storeUserPhoto($photo));
        }

        $entityManager->persist($user);
        $entityManager->flush();

        return $this->usersResponse($entityManager, 'Employé créé.');
    }

    #[Route('/users/{id}', name: 'admin_user_update', methods: ['POST'])]
    public function updateUser(User $user, Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        $this->assertManageableEmployee($user);

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
            ->setPhoneNumber($this->nullable($request->request->get('phoneNumber')))
            ->setRoles(['ROLE_EMPLOYEE'])
            ->setIsActive($request->request->getBoolean('isActive'))
            ->setCanManageAnomalyWorkflow($request->request->getBoolean('canManageAnomalyWorkflow'));

        $password = trim((string) $request->request->get('password'));
        if ($password !== '') {
            $user->setPassword($passwordHasher->hashPassword($user, $password));
        }

        $photo = $request->files->get('photo');
        if ($photo instanceof UploadedFile) {
            $previousPhotoPath = $user->getPhotoPath();
            $user->setPhotoPath($this->storeUserPhoto($photo));
            $this->deleteUserPhoto($previousPhotoPath);
        }

        $entityManager->flush();

        return $this->usersResponse($entityManager, 'Employé mis à jour.');
    }

    #[Route('/users/{id}/field', name: 'admin_user_field_update', methods: ['POST'])]
    public function updateUserField(User $user, Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        $this->assertManageableEmployee($user);

        $field = (string) $request->request->get('field');
        $value = trim((string) $request->request->get('value'));

        try {
            $this->applyAdminUserFieldUpdate($user, $field, $value, $entityManager, $passwordHasher);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return $this->userDetailResponse($user, $entityManager, 'Information employe mise a jour.');
    }

    #[Route('/users/{id}/photo', name: 'admin_user_photo_update', methods: ['POST'])]
    public function updateUserPhoto(User $user, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->assertManageableEmployee($user);

        $photo = $request->files->get('photo');
        if (!$photo instanceof UploadedFile) {
            return new JsonResponse(['success' => false, 'message' => 'Ajoute une photo avant de valider.'], 422);
        }

        $previousPhotoPath = $user->getPhotoPath();
        $user->setPhotoPath($this->storeUserPhoto($photo));
        $entityManager->flush();
        $this->deleteUserPhoto($previousPhotoPath);

        return $this->userDetailResponse($user, $entityManager, 'Photo employe mise a jour.');
    }

    #[Route('/services/{id}/label', name: 'admin_service_offer_label_update', methods: ['POST'])]
    public function updateServiceOfferLabel(ServiceOffer $serviceOffer, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $label = $this->normalizeServiceOfferLabel((string) $request->request->get('label'));
        if ($label === '') {
            return new JsonResponse(['success' => false, 'message' => 'Le nom du service est obligatoire.'], 422);
        }

        $serviceOffer->setLabel($label);
        $entityManager->flush();

        return $this->serviceOfferResponse($serviceOffer, $entityManager, (string) $request->request->get('context', 'dashboard'), 'Libelle du service mis a jour.');
    }

    #[Route('/services/{id}/approve', name: 'admin_service_offer_approve', methods: ['POST'])]
    public function approveServiceOffer(ServiceOffer $serviceOffer, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $actor = $this->getUser();
        $serviceOffer
            ->setStatus(ServiceOffer::STATUS_APPROVED)
            ->setApprovedAt(new \DateTimeImmutable())
            ->setApprovedBy($actor instanceof User ? $actor : null);

        $entityManager->flush();

        return $this->serviceOfferResponse($serviceOffer, $entityManager, (string) $request->request->get('context', 'dashboard'), 'Service valide.');
    }

    #[Route('/services/{id}/reject', name: 'admin_service_offer_reject', methods: ['POST'])]
    public function rejectServiceOffer(ServiceOffer $serviceOffer, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $serviceOffer->getCreatedBy();
        if ($user instanceof User) {
            $user->removeServiceOffer($serviceOffer);
        }

        $serviceOffer
            ->setStatus(ServiceOffer::STATUS_REJECTED)
            ->setApprovedAt(null)
            ->setApprovedBy(null);

        $entityManager->flush();

        return $this->serviceOfferResponse($serviceOffer, $entityManager, (string) $request->request->get('context', 'dashboard'), 'Service refuse.');
    }

    #[Route('/services/{id}/delete', name: 'admin_service_offer_delete', methods: ['POST'])]
    public function deleteServiceOffer(ServiceOffer $serviceOffer, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        if ($serviceOffer->isStandard()) {
            return new JsonResponse(['success' => false, 'message' => 'Un service standard ne peut pas etre supprime ici.'], 422);
        }

        $owner = $serviceOffer->getCreatedBy();

        foreach ($serviceOffer->getUsers()->toArray() as $user) {
            if ($user instanceof User) {
                $user->removeServiceOffer($serviceOffer);
            }
        }

        $entityManager->remove($serviceOffer);
        $entityManager->flush();

        $context = (string) $request->request->get('context', 'dashboard');
        $message = 'Service supprime.';

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView(
                $context === 'user-detail' && $owner instanceof User
                    ? 'admin/_user_detail_content.html.twig'
                    : 'admin/_dashboard_content.html.twig',
                $context === 'user-detail' && $owner instanceof User
                    ? $this->buildUserDetailData($owner, $entityManager)
                    : $this->buildDashboardData($entityManager)
            ),
            'message' => $message,
        ]);
    }

    #[Route('/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $user, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->assertManageableEmployee($user);

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            return new JsonResponse(['success' => false, 'message' => 'Tu ne peux pas supprimer le compte actuellement connecté.'], 422);
        }

        foreach ($user->getAssignedApartments()->toArray() as $apartment) {
            $user->removeAssignedApartment($apartment);
        }

        $photoPath = $user->getPhotoPath();
        $entityManager->remove($user);
        $entityManager->flush();
        $this->deleteUserPhoto($photoPath);

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

    #[Route('/apartments/{id}/details/{section}', name: 'admin_apartment_section', methods: ['GET'])]
    public function showApartmentSection(Apartment $apartment, string $section, EntityManagerInterface $entityManager): Response
    {
        $currentSection = $this->normalizeApartmentDetailSection($section);
        if ($currentSection === null) {
            throw $this->createNotFoundException('Section appartement introuvable.');
        }

        return $this->render('admin/apartment_section_show.html.twig', $this->buildApartmentDetailData($apartment, $entityManager, $currentSection));
    }

    #[Route('/apartments/{id}/name', name: 'admin_apartment_name_update', methods: ['POST'])]
    public function updateApartmentName(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            return new JsonResponse(['success' => false, 'message' => 'Le nom de l appartement est obligatoire.'], 422);
        }

        $apartment->setName($name);
        $entityManager->flush();

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Nom de l appartement mis a jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    #[Route('/apartments/{id}/field', name: 'admin_apartment_field_update', methods: ['POST'])]
    public function updateApartmentField(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $field = (string) $request->request->get('field');
        $value = trim((string) $request->request->get('value'));

        try {
            $this->applyAdminApartmentFieldUpdate($apartment, $field, $value);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Information appartement mise a jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
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

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Statut de l appartement mis a jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
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

        $accessStepImagePaths = array_map(
            static fn (ApartmentAccessStep $step): ?string => $step->getImagePath(),
            $apartment->getOrderedAccessSteps()
        );

        $entityManager->remove($apartment);
        $entityManager->flush();

        foreach ($accessStepImagePaths as $imagePath) {
            $this->deleteApartmentAccessStepImage($imagePath);
        }

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

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Affectations mises à jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    #[Route('/apartments/{id}/priority', name: 'admin_apartment_priority', methods: ['POST'])]
    public function togglePriority(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $apartment->setIsInventoryPriority($request->request->getBoolean('isInventoryPriority'));
        if (!$apartment->isInventoryPriority()) {
            $apartment->setInventoryDueAt(null);
        }
        $entityManager->flush();

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Priorité inventaire mise à jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    #[Route('/apartments/{id}/access-steps', name: 'admin_apartment_access_step_create', methods: ['POST'])]
    public function createApartmentAccessStep(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $instruction = trim((string) $request->request->get('instruction'));
        if ($instruction === '') {
            return new JsonResponse(['success' => false, 'message' => 'Le texte de l’étape est obligatoire.'], 422);
        }

        $step = (new ApartmentAccessStep())
            ->setInstruction($instruction)
            ->setDisplayOrder($apartment->getAccessSteps()->count() + 1);

        $image = $request->files->get('image');
        if ($image instanceof UploadedFile) {
            $step->setImagePath($this->storeApartmentAccessStepImage($image));
        }

        $apartment->addAccessStep($step);
        $entityManager->persist($step);
        $entityManager->flush();

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Étape d’accès ajoutée.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    #[Route('/apartment-access-steps/{id}', name: 'admin_apartment_access_step_update', methods: ['POST'])]
    public function updateApartmentAccessStep(ApartmentAccessStep $accessStep, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $apartment = $accessStep->getApartment();
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Étape introuvable.'], 404);
        }

        $instruction = trim((string) $request->request->get('instruction'));
        if ($instruction === '') {
            return new JsonResponse(['success' => false, 'message' => 'Le texte de l’étape est obligatoire.'], 422);
        }

        $accessStep->setInstruction($instruction);

        $removeImage = $request->request->getBoolean('removeImage');
        $newImage = $request->files->get('image');
        $previousImagePath = $accessStep->getImagePath();

        if ($removeImage) {
            $accessStep->setImagePath(null);
        }

        if ($newImage instanceof UploadedFile) {
            $accessStep->setImagePath($this->storeApartmentAccessStepImage($newImage));
        }

        $entityManager->flush();

        if (($removeImage || $newImage instanceof UploadedFile) && $previousImagePath !== $accessStep->getImagePath()) {
            $this->deleteApartmentAccessStepImage($previousImagePath);
        }

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Étape d’accès mise à jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    #[Route('/apartment-access-steps/{id}/delete', name: 'admin_apartment_access_step_delete', methods: ['POST'])]
    public function deleteApartmentAccessStep(ApartmentAccessStep $accessStep, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $apartment = $accessStep->getApartment();
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Étape introuvable.'], 404);
        }

        $photoPath = $accessStep->getImagePath();
        $apartment->removeAccessStep($accessStep);
        $entityManager->remove($accessStep);
        $entityManager->flush();

        $this->deleteApartmentAccessStepImage($photoPath);
        $this->reorderApartmentAccessSteps($apartment, $entityManager);

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Étape d’accès supprimée.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
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

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Piece ajoutee.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
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
            return $this->apartmentDetailResponse($apartment, $entityManager, 'Catalogue mis a jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
        }

        return $this->redirectToRoute('admin_apartment_show', ['id' => (int) $request->request->get('apartmentId')]);
    }

    #[Route('/rooms/{id}/equipments', name: 'admin_room_equipment_add', methods: ['POST'])]
    public function addRoomEquipment(Room $room, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $displayOrder = $room->getRoomEquipments()->count() + 1;
        $notes = $this->nullable($request->request->get('notes'));
        $addedCount = 0;

        $catalogIds = array_values(array_filter(
            array_map('intval', (array) $request->request->all('catalogEquipmentIds')),
            static fn (int $id): bool => $id > 0
        ));

        if ($catalogIds !== []) {
            $catalogItems = $entityManager->getRepository(EquipmentCatalog::class)->findBy(['id' => $catalogIds]);

            foreach ($catalogItems as $catalogEquipment) {
                if (!$catalogEquipment instanceof EquipmentCatalog) {
                    continue;
                }

                $equipment = new RoomEquipment();
                $equipment
                    ->setDisplayOrder($displayOrder++)
                    ->setNotes($notes)
                    ->setCatalogEquipment($catalogEquipment)
                    ->setLabel($catalogEquipment->getName());

                $room->addRoomEquipment($equipment);
                $entityManager->persist($equipment);
                $addedCount++;
            }
        }

        $manualLabel = trim((string) $request->request->get('manualLabel'));
        if ($manualLabel !== '') {
            $equipment = new RoomEquipment();
            $equipment
                ->setDisplayOrder($displayOrder++)
                ->setNotes($notes)
                ->setLabel($manualLabel);

            $room->addRoomEquipment($equipment);
            $entityManager->persist($equipment);
            $addedCount++;
        }

        if ($addedCount === 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Sélectionne au moins un équipement ou saisis un équipement manuel.',
            ], 422);
        }

        $entityManager->flush();

        return $this->apartmentDetailResponse(
            $room->getApartment(),
            $entityManager,
            $addedCount > 1 ? 'Équipements ajoutés.' : 'Équipement ajouté.',
            $this->normalizeApartmentDetailSection((string) $request->request->get('section'))
        );
    }

    #[Route('/rooms/{id}/delete', name: 'admin_room_delete', methods: ['POST'])]
    public function deleteRoom(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $room = $entityManager->getRepository(Room::class)->find($id);
        if (!$room instanceof Room) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Cette pièce n’existe plus. Recharge la page pour récupérer la dernière version.',
            ], 404);
        }

        if (count($room->getActiveRoomEquipments()) > 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Suppression impossible : retire d abord les equipements de cette piece.',
            ], 422);
        }

        $hasCheckoutHistory = $entityManager->getRepository(CheckoutLine::class)->count(['room' => $room]) > 0;
        $hasAnomalyHistory = $entityManager->getRepository(Anomaly::class)->count(['room' => $room]) > 0;

        $apartment = $room->getApartment();
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Appartement introuvable.'], 404);
        }

        if ($hasCheckoutHistory || $hasAnomalyHistory) {
            $room->markAsDeleted();
        } else {
            $apartment->removeRoom($room);
            $entityManager->remove($room);
        }
        $entityManager->flush();

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Piece supprimee.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    #[Route('/room-equipments/{id}/delete', name: 'admin_room_equipment_delete', methods: ['POST'])]
    public function deleteRoomEquipment(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $equipment = $entityManager->getRepository(RoomEquipment::class)->find($id);
        if (!$equipment instanceof RoomEquipment) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Cet équipement n’existe plus. Recharge la page pour récupérer la dernière version.',
            ], 404);
        }

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

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Equipement supprime de la piece.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
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

        if (!$apartment->getAssignedEmployees()->contains($employee)) {
            return new JsonResponse(['success' => false, 'message' => 'Choisis un employé assigné à cet appartement.'], 422);
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

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Check-out créé et assigné.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    #[Route('/checkouts/{id}', name: 'admin_checkout_show', methods: ['GET'])]
    public function showCheckout(Checkout $checkout): Response
    {
        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);

        return $this->render('employee/checkout_show.html.twig', [
            'checkout' => $checkout,
            'roomGroups' => $this->buildCheckoutRoomGroups($checkout),
            'dashboardRoute' => 'admin_dashboard',
            'checkoutShowRoute' => 'admin_checkout_show',
            'checkoutRoomRoute' => 'admin_checkout_room_show',
            'checkoutPauseRoute' => 'admin_checkout_pause',
            'checkoutResumeRoute' => 'admin_checkout_resume',
            'checkoutCompleteRoute' => 'admin_checkout_complete',
            'checkoutLineUpdateRoute' => 'admin_checkout_line_update',
        ]);
    }

    #[Route('/checkouts/{checkout}/rooms/{room}', name: 'admin_checkout_room_show', methods: ['GET'])]
    public function showCheckoutRoom(Checkout $checkout, Room $room): Response
    {
        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);

        $group = $this->findCheckoutRoomGroup($checkout, $room);
        if ($group === null) {
            throw $this->createNotFoundException('Pièce introuvable pour ce check-out.');
        }

        return $this->render('employee/room_show.html.twig', [
            'checkout' => $checkout,
            'roomGroup' => $group,
            'checkStatuses' => EquipmentCheckStatus::cases(),
            'checkoutShowRoute' => 'admin_checkout_show',
            'checkoutLineUpdateRoute' => 'admin_checkout_line_update',
        ]);
    }

    #[Route('/checkouts/lines/{id}', name: 'admin_checkout_line_update', methods: ['POST'])]
    public function updateCheckoutLine(CheckoutLine $line, Request $request, CheckoutManager $checkoutManager, EntityManagerInterface $entityManager): JsonResponse
    {
        $checkout = $line->getCheckout();
        if (!$checkout instanceof Checkout) {
            return new JsonResponse(['success' => false, 'message' => 'Check-out introuvable.'], 404);
        }

        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);

        try {
            $checkoutManager->updateLine(
                $line,
                EquipmentCheckStatus::from((string) $request->request->get('status')),
                $request->request->get('comment'),
                $request->files->get('photo')
            );
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return $this->adminRoomWorkspaceResponse($checkout, $line->getRoom(), 'Équipement mis à jour.');
    }

    #[Route('/checkouts/{id}/pause', name: 'admin_checkout_pause', methods: ['POST'])]
    public function pauseCheckout(Checkout $checkout, Request $request, CheckoutManager $checkoutManager, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);
        $checkoutManager->pause($checkout, (string) $request->request->get('reason'));
        $entityManager->flush();

        return $this->adminCheckoutRedirectResponse($checkout, 'Check-out mis en pause.');
    }

    #[Route('/checkouts/{id}/resume', name: 'admin_checkout_resume', methods: ['POST'])]
    public function resumeCheckout(Checkout $checkout, CheckoutManager $checkoutManager, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);
        $checkoutManager->resume($checkout);
        $entityManager->flush();

        return $this->adminCheckoutRedirectResponse($checkout, 'Check-out repris.');
    }

    #[Route('/checkouts/{id}/complete', name: 'admin_checkout_complete', methods: ['POST'])]
    public function completeCheckout(Checkout $checkout, CheckoutManager $checkoutManager, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);

        try {
            $checkoutManager->complete($checkout);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return $this->adminRedirectToDashboardResponse('Check-out terminé.');
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

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Date du check-out mise a jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    #[Route('/checkouts/{id}/cancel', name: 'admin_checkout_cancel', methods: ['POST'])]
    public function cancelCheckout(Checkout $checkout, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        if (in_array($checkout->getStatus(), [CheckoutStatus::Completed, CheckoutStatus::Cancelled], true)) {
            return new JsonResponse(['success' => false, 'message' => 'Ce check-out ne peut plus être annulé.'], 422);
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

        if ((string) $request->request->get('context') === 'dashboard') {
            return new JsonResponse([
                'success' => true,
                'html' => $this->renderView('admin/_dashboard_content.html.twig', $this->buildDashboardData($entityManager)),
                'message' => 'Check-out annulé.',
            ]);
        }

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Check-out annulé.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    private function apartmentDetailResponse(Apartment $apartment, EntityManagerInterface $entityManager, string $message, ?string $currentSection = null): JsonResponse
    {
        $html = $this->renderView('admin/_apartment_detail_content.html.twig', $this->buildApartmentDetailData($apartment, $entityManager, $currentSection));

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

    private function userDetailResponse(User $user, EntityManagerInterface $entityManager, string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('admin/_user_detail_content.html.twig', $this->buildUserDetailData($user, $entityManager)),
            'message' => $message,
        ]);
    }

    private function serviceOfferResponse(ServiceOffer $serviceOffer, EntityManagerInterface $entityManager, string $context, string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView(
                $context === 'user-detail' ? 'admin/_user_detail_content.html.twig' : 'admin/_dashboard_content.html.twig',
                $context === 'user-detail' && $serviceOffer->getCreatedBy() instanceof User
                    ? $this->buildUserDetailData($serviceOffer->getCreatedBy(), $entityManager)
                    : $this->buildDashboardData($entityManager)
            ),
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApartmentDetailData(Apartment $apartment, EntityManagerInterface $entityManager, ?string $currentSection = null): array
    {
        $anomalies = $entityManager->getRepository(Anomaly::class)->findBy(['apartment' => $apartment], ['createdAt' => 'DESC']);
        $normalizedSection = $this->normalizeApartmentDetailSection($currentSection);

        return [
            'apartment' => $apartment,
            'currentSection' => $normalizedSection,
            'detailUpdateTarget' => $normalizedSection === null ? '#apartment-detail-root' : '#apartment-section-root',
            'employees' => $entityManager->getRepository(User::class)->findBy([], ['fullName' => 'ASC']),
            'catalog' => $entityManager->getRepository(EquipmentCatalog::class)->findBy(['isActive' => true], ['roomType' => 'ASC', 'name' => 'ASC']),
            'activeCheckouts' => $entityManager->createQueryBuilder()
                ->select('checkout')
                ->from(Checkout::class, 'checkout')
                ->where('checkout.apartment = :apartment')
                ->andWhere('checkout.status NOT IN (:excludedStatuses)')
                ->setParameter('apartment', $apartment)
                ->setParameter('excludedStatuses', [CheckoutStatus::Cancelled, CheckoutStatus::Completed])
                ->orderBy('checkout.id', 'DESC')
                ->setMaxResults(10)
                ->getQuery()
                ->getResult(),
            'completedCheckouts' => $entityManager->createQueryBuilder()
                ->select('checkout', 'assignedTo', 'anomalies', 'room', 'roomEquipment')
                ->from(Checkout::class, 'checkout')
                ->leftJoin('checkout.assignedTo', 'assignedTo')
                ->leftJoin('checkout.anomalies', 'anomalies')
                ->leftJoin('anomalies.room', 'room')
                ->leftJoin('anomalies.roomEquipment', 'roomEquipment')
                ->where('checkout.apartment = :apartment')
                ->andWhere('checkout.status = :completedStatus')
                ->setParameter('apartment', $apartment)
                ->setParameter('completedStatus', CheckoutStatus::Completed)
                ->orderBy('checkout.completedAt', 'DESC')
                ->addOrderBy('checkout.id', 'DESC')
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

    private function normalizeApartmentDetailSection(?string $section): ?string
    {
        if (!is_string($section) || $section === '') {
            return null;
        }

        return in_array($section, self::APARTMENT_DETAIL_SECTIONS, true) ? $section : null;
    }

    private function reorderApartmentAccessSteps(Apartment $apartment, EntityManagerInterface $entityManager): void
    {
        $position = 1;
        foreach ($apartment->getOrderedAccessSteps() as $step) {
            $step->setDisplayOrder($position);
            ++$position;
        }

        $entityManager->flush();
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

    private function denyAccessUnlessGrantedToAdminCheckout(Checkout $checkout): void
    {
        $apartment = $checkout->getApartment();
        if (!$apartment instanceof Apartment || $apartment->getStatus() !== ApartmentStatus::Active) {
            throw $this->createAccessDeniedException();
        }
    }

    private function adminRoomWorkspaceResponse(Checkout $checkout, ?Room $room, string $message): JsonResponse
    {
        if (!$room instanceof Room) {
            return $this->adminCheckoutRedirectResponse($checkout, $message);
        }

        $group = $this->findCheckoutRoomGroup($checkout, $room);
        if ($group === null) {
            return $this->adminCheckoutRedirectResponse($checkout, $message);
        }

        if ($group['totalCount'] > 0 && $group['checkedCount'] >= $group['totalCount']) {
            return new JsonResponse([
                'success' => true,
                'redirect' => $this->generateUrl('admin_checkout_show', ['id' => $checkout->getId()]),
                'message' => 'Pièce terminée. Retour à la liste des pièces.',
            ]);
        }

        $html = $this->renderView('employee/_room_workspace.html.twig', [
            'checkout' => $checkout,
            'checkStatuses' => EquipmentCheckStatus::cases(),
            'roomGroup' => $group,
            'checkoutShowRoute' => 'admin_checkout_show',
            'checkoutLineUpdateRoute' => 'admin_checkout_line_update',
        ]);

        return new JsonResponse([
            'success' => true,
            'html' => $html,
            'message' => $message,
        ]);
    }

    private function adminCheckoutRedirectResponse(Checkout $checkout, string $message): JsonResponse
    {
        $this->addFlash('success', $message);

        return new JsonResponse([
            'success' => true,
            'redirect' => $this->generateUrl('admin_checkout_show', ['id' => $checkout->getId()]),
            'message' => $message,
        ]);
    }

    private function adminRedirectToDashboardResponse(string $message): JsonResponse
    {
        $this->addFlash('success', $message);

        return new JsonResponse([
            'success' => true,
            'redirect' => $this->generateUrl('admin_dashboard'),
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardData(EntityManagerInterface $entityManager): array
    {
        $apartmentRepository = $entityManager->getRepository(Apartment::class);
        $checkoutRepository = $entityManager->getRepository(Checkout::class);
        $pendingServiceOffers = $entityManager->getRepository(ServiceOffer::class)->findBy(
            ['status' => ServiceOffer::STATUS_PENDING],
            ['createdAt' => 'DESC']
        );

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
            'pendingServiceOffers' => $pendingServiceOffers,
            'employeeCount' => count(array_filter(
                $entityManager->getRepository(User::class)->findBy([], ['fullName' => 'ASC']),
                static fn (User $user): bool => !in_array('ROLE_ADMIN', $user->getRoles(), true)
            )),
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

    /**
     * @return array{user: User}
     */
    private function buildUserDetailData(User $user, EntityManagerInterface $entityManager): array
    {
        $approvedStandardServices = $entityManager->getRepository(ServiceOffer::class)->findBy(
            ['isStandard' => true, 'status' => ServiceOffer::STATUS_APPROVED],
            ['label' => 'ASC']
        );

        $customServices = $entityManager->createQueryBuilder()
            ->select('serviceOffer')
            ->from(ServiceOffer::class, 'serviceOffer')
            ->where('serviceOffer.createdBy = :user')
            ->andWhere('serviceOffer.isStandard = :isStandard')
            ->andWhere('serviceOffer.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('isStandard', false)
            ->setParameter('statuses', [ServiceOffer::STATUS_PENDING, ServiceOffer::STATUS_APPROVED])
            ->orderBy('serviceOffer.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return [
            'user' => $user,
            'approvedStandardServices' => $approvedStandardServices,
            'customServiceOffers' => $customServices,
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
     * @return list<array{
     *     apartment: Apartment,
     *     openCheckout: ?Checkout,
     *     anomalyCount: int,
     *     openAnomalyCount: int,
     *     assignedEmployeeNames: string,
     *     assignedEmployees: list<User>,
     *     firstAssignedEmployeeId: ?int,
     *     canQuickLaunch: bool,
     *     canLaunchCheckout: bool
     * }>
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
                'assignedEmployees' => $assignedEmployees,
                'firstAssignedEmployeeId' => count($assignedEmployees) === 1 ? $assignedEmployees[0]->getId() : null,
                'canQuickLaunch' => $openCheckout === null && count($assignedEmployees) === 1,
                'canLaunchCheckout' => $openCheckout === null,
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

    /**
     * @return array<int, array{room: Room, lines: array<int, CheckoutLine>, checkedCount:int, anomalyCount:int, totalCount:int, completionPercent:int}>
     */
    private function buildCheckoutRoomGroups(Checkout $checkout): array
    {
        $groups = [];
        foreach ($checkout->getLines() as $line) {
            $room = $line->getRoom();
            if (!$room instanceof Room) {
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
     * @return array{room: Room, lines: array<int, CheckoutLine>, checkedCount:int, anomalyCount:int, totalCount:int, completionPercent:int}|null
     */
    private function findCheckoutRoomGroup(Checkout $checkout, Room $room): ?array
    {
        foreach ($this->buildCheckoutRoomGroups($checkout) as $group) {
            if (($group['room']->getId() ?? null) === $room->getId()) {
                return $group;
            }
        }

        return null;
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

    private function assertManageableEmployee(User $user): void
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw $this->createNotFoundException();
        }
    }

    private function nullable(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeServiceOfferLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');

        return mb_substr($label, 0, 160);
    }

    private function applyAdminApartmentFieldUpdate(Apartment $apartment, string $field, string $value): void
    {
        $normalizedValue = $value === '' ? null : $value;

        match ($field) {
            'addressLine1' => $apartment->setAddressLine1($value !== '' ? $value : $apartment->getAddressLine1()),
            'addressLine2' => $apartment->setAddressLine2($normalizedValue),
            'city' => $apartment->setCity($value !== '' ? $value : $apartment->getCity()),
            'postalCode' => $apartment->setPostalCode($value !== '' ? $value : $apartment->getPostalCode()),
            'floor' => $apartment->setFloor($normalizedValue),
            'doorNumber' => $apartment->setDoorNumber($normalizedValue),
            'mailboxNumber' => $apartment->setMailboxNumber($normalizedValue),
            'buildingAccessCode' => $apartment->setBuildingAccessCode($normalizedValue),
            'keyBoxCode' => $apartment->setKeyBoxCode($normalizedValue),
            'googleMapsLink' => $apartment->setGoogleMapsLink($normalizedValue),
            'entryInstructions' => $apartment->setEntryInstructions($value === '' ? 'Aucune consigne pour le moment.' : $value),
            'ownerName' => $apartment->setOwnerName($normalizedValue),
            'ownerPhone' => $apartment->setOwnerPhone($normalizedValue),
            'internalNotes' => $apartment->setInternalNotes($normalizedValue),
            default => throw new \InvalidArgumentException('Champ non modifiable.'),
        };

        if (in_array($field, ['addressLine1', 'city', 'postalCode'], true)) {
            $apartment->setWazeLink($this->buildWazeLink($apartment->getAddressLine1(), $apartment->getCity(), $apartment->getPostalCode()));
        }
    }

    private function applyAdminUserFieldUpdate(User $user, string $field, string $value, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): void
    {
        $normalizedValue = $value === '' ? null : $value;

        match ($field) {
            'fullName' => $user->setFullName($value !== '' ? $value : $user->getFullName()),
            'email' => $this->updateAdminUserEmail($user, $value, $entityManager),
            'phoneNumber' => $user->setPhoneNumber($normalizedValue),
            'password' => $this->updateAdminUserPassword($user, $value, $passwordHasher),
            'isActive' => $user->setIsActive(in_array(strtolower($value), ['1', 'true', 'oui', 'actif'], true)),
            'canManageAnomalyWorkflow' => $user->setCanManageAnomalyWorkflow(in_array(strtolower($value), ['1', 'true', 'oui', 'autorise'], true)),
            default => throw new \InvalidArgumentException('Champ employe non modifiable.'),
        };
    }

    private function updateAdminUserEmail(User $user, string $value, EntityManagerInterface $entityManager): void
    {
        $email = mb_strtolower(trim($value));
        if ($email === '') {
            throw new \InvalidArgumentException('L email est obligatoire.');
        }

        $existing = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User && $existing->getId() !== $user->getId()) {
            throw new \InvalidArgumentException('Cet email existe deja.');
        }

        $user->setEmail($email);
    }

    private function updateAdminUserPassword(User $user, string $value, UserPasswordHasherInterface $passwordHasher): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Le mot de passe ne peut pas etre vide.');
        }

        $user->setPassword($passwordHasher->hashPassword($user, $value));
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

    private function storeUserPhoto(UploadedFile $photo): string
    {
        $targetDir = $this->getParameter('kernel.project_dir') . '/public/uploads/users';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $safeName = pathinfo($photo->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $safeName) ?: 'employee';
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(4)), $photo->guessExtension() ?: 'jpg');

        $photo->move($targetDir, $filename);

        return '/uploads/users/' . $filename;
    }

    private function storeApartmentAccessStepImage(UploadedFile $image): string
    {
        $targetDir = $this->getParameter('kernel.project_dir') . '/public/uploads/apartment-access';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $safeName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $safeName) ?: 'access-step';
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(4)), $image->guessExtension() ?: 'jpg');

        $image->move($targetDir, $filename);

        return '/uploads/apartment-access/' . $filename;
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

    private function deleteApartmentAccessStepImage(?string $imagePath): void
    {
        if (!is_string($imagePath) || $imagePath === '' || !str_starts_with($imagePath, '/uploads/apartment-access/')) {
            return;
        }

        $fullPath = $this->getParameter('kernel.project_dir') . '/public' . $imagePath;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
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
