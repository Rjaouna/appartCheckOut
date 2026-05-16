<?php

namespace App\Controller;

use App\Entity\Anomaly;
use App\Entity\AirbnbCheck;
use App\Entity\AppAppearanceSettings;
use App\Entity\Apartment;
use App\Entity\ApartmentAccessStep;
use App\Entity\ApartmentManual;
use App\Entity\ApartmentReservation;
use App\Entity\Checkout;
use App\Entity\CheckoutLine;
use App\Entity\ConsumableCheck;
use App\Entity\ConsumableItem;
use App\Entity\EquipmentCatalog;
use App\Entity\Room;
use App\Entity\RoomEquipment;
use App\Entity\ServiceOffer;
use App\Entity\User;
use App\Enum\ConsumableCheckStatus;
use App\Enum\EquipmentCheckStatus;
use App\Enum\ApartmentStatus;
use App\Enum\CheckoutStatus;
use App\Enum\RoomType;
use App\Enum\AnomalyStatus;
use App\Service\AnomalyWorkflowManager;
use App\Service\ApartmentReservationMessenger;
use App\Service\CheckoutManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/admin')]
class AdminController extends AbstractController
{
    private const APARTMENT_DETAIL_SECTIONS = ['checkout', 'access', 'apartment-access', 'guidebook', 'arrivals', 'assignment', 'rooms', 'consumables', 'anomalies', 'settings'];
    private const STANDARD_CONSUMABLES = [
        ['name' => 'Papier toilette', 'unit' => 'rouleaux', 'minimumQuantity' => 2],
        ['name' => 'Savon mains', 'unit' => 'flacons', 'minimumQuantity' => 1],
        ['name' => 'Gel douche', 'unit' => 'flacons', 'minimumQuantity' => 1],
        ['name' => 'Shampooing', 'unit' => 'flacons', 'minimumQuantity' => 1],
        ['name' => 'Capsules café', 'unit' => 'capsules', 'minimumQuantity' => 10],
        ['name' => 'Sacs poubelle', 'unit' => 'sacs', 'minimumQuantity' => 3],
        ['name' => 'Liquide vaisselle', 'unit' => 'flacons', 'minimumQuantity' => 1],
        ['name' => 'Éponge', 'unit' => 'pièces', 'minimumQuantity' => 1],
        ['name' => 'Piles', 'unit' => 'pièces', 'minimumQuantity' => 2],
    ];
    private const APARTMENT_RICH_TEXT_FIELDS = [
        'entryInstructions',
        'internalNotes',
        'guestWifiInstructions',
        'guestHouseRules',
        'guestDepartureInstructions',
        'guestEmergencyInfo',
        'guestEquipmentInfo',
    ];

    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(EntityManagerInterface $entityManager): Response
    {
        return $this->render('admin/dashboard.html.twig', $this->buildDashboardData($entityManager));
    }

    #[Route('/arrivals', name: 'admin_arrivals', methods: ['GET'])]
    public function arrivals(EntityManagerInterface $entityManager): Response
    {
        return $this->render('admin/arrivals.html.twig', $this->buildArrivalsPageData($entityManager));
    }

    #[Route('/appearance', name: 'admin_appearance', methods: ['GET', 'POST'])]
    public function appearance(Request $request, EntityManagerInterface $entityManager): Response
    {
        $settingsTableReady = $this->appearanceSettingsStorageReady($entityManager);
        $settings = $settingsTableReady
            ? $this->getAppearanceSettings($entityManager)
            : AppAppearanceSettings::default();

        if ($request->isMethod('POST')) {
            if (!$settingsTableReady) {
                $this->addFlash('error', 'La table de configuration de l’apparence n’est pas encore installée. Lance la migration Doctrine avant d’enregistrer la palette.');

                return $this->redirectToRoute('admin_appearance');
            }

            if (!$this->isCsrfTokenValid('admin_appearance', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide. Réessaie la mise à jour.');

                return $this->redirectToRoute('admin_appearance');
            }

            try {
                if ($request->request->has('resetPalette')) {
                    $settings
                        ->setPrimaryColor(AppAppearanceSettings::DEFAULT_PRIMARY_COLOR)
                        ->setSecondaryColor(AppAppearanceSettings::DEFAULT_SECONDARY_COLOR)
                        ->setTertiaryColor(AppAppearanceSettings::DEFAULT_TERTIARY_COLOR)
                        ->setBackgroundColor(AppAppearanceSettings::DEFAULT_BACKGROUND_COLOR)
                        ->setSurfaceColor(AppAppearanceSettings::DEFAULT_SURFACE_COLOR)
                        ->setTextColor(AppAppearanceSettings::DEFAULT_TEXT_COLOR)
                        ->setMutedColor(AppAppearanceSettings::DEFAULT_MUTED_COLOR)
                        ->setBorderColor(AppAppearanceSettings::DEFAULT_BORDER_COLOR)
                        ->setSuccessColor(AppAppearanceSettings::DEFAULT_SUCCESS_COLOR)
                        ->setWarningColor(AppAppearanceSettings::DEFAULT_WARNING_COLOR)
                        ->setDangerColor(AppAppearanceSettings::DEFAULT_DANGER_COLOR);
                } else {
                    $primaryColor = $this->extractAppearanceColor($request, 'primaryColor', 'primaire');
                    $settings
                        ->setPrimaryColor($primaryColor)
                        ->setSecondaryColor($this->extractAppearanceColor($request, 'secondaryColor', 'secondaire'))
                        ->setTertiaryColor(AppAppearanceSettings::deriveTertiaryColor($primaryColor))
                        ->setBackgroundColor($this->extractAppearanceColor($request, 'backgroundColor', 'de fond'))
                        ->setSurfaceColor($this->extractAppearanceColor($request, 'surfaceColor', 'des cartes'))
                        ->setTextColor($this->extractAppearanceColor($request, 'textColor', 'du texte'))
                        ->setMutedColor($this->extractAppearanceColor($request, 'mutedColor', 'du texte secondaire'))
                        ->setBorderColor($this->extractAppearanceColor($request, 'borderColor', 'des bordures'))
                        ->setSuccessColor($this->extractAppearanceColor($request, 'successColor', 'de succès'))
                        ->setWarningColor($this->extractAppearanceColor($request, 'warningColor', 'd’alerte'))
                        ->setDangerColor($this->extractAppearanceColor($request, 'dangerColor', 'de suppression'));
                }
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('admin_appearance');
            }

            try {
                $entityManager->persist($settings);
                $entityManager->flush();
            } catch (\Throwable) {
                $this->addFlash('error', 'Impossible d’enregistrer la palette. Vérifie que les migrations Doctrine ont bien été exécutées.');

                return $this->redirectToRoute('admin_appearance');
            }

            $this->addFlash('success', $request->request->has('resetPalette')
                ? 'Palette réinitialisée.'
                : 'Palette mise à jour. Toute l’interface utilise maintenant ces couleurs.'
            );

            return $this->redirectToRoute('admin_appearance');
        }

        return $this->render('admin/appearance.html.twig', [
            'settings' => $settings,
            'settingsTableReady' => $settingsTableReady,
        ]);
    }

    #[Route('/anomalies', name: 'admin_anomalies', methods: ['GET'])]
    public function anomalies(Request $request, EntityManagerInterface $entityManager): Response
    {
        $apartments = $entityManager->createQueryBuilder()
            ->select('DISTINCT apartment')
            ->from(Apartment::class, 'apartment')
            ->join(Anomaly::class, 'anomaly', 'WITH', 'anomaly.apartment = apartment AND anomaly.status != :closedStatus')
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->orderBy('apartment.name', 'ASC')
            ->getQuery()
            ->getResult();

        $latestOpenAnomalyDates = [];
        if ($apartments !== []) {
            $latestRows = $entityManager->createQueryBuilder()
                ->select('IDENTITY(anomaly.apartment) AS apartmentId, MAX(anomaly.createdAt) AS latestCreatedAt')
                ->from(Anomaly::class, 'anomaly')
                ->where('anomaly.status != :closedStatus')
                ->andWhere('anomaly.apartment IN (:apartments)')
                ->groupBy('anomaly.apartment')
                ->setParameter('closedStatus', AnomalyStatus::Closed)
                ->setParameter('apartments', $apartments)
                ->getQuery()
                ->getArrayResult();

            foreach ($latestRows as $row) {
                $apartmentId = (int) ($row['apartmentId'] ?? 0);
                $latestCreatedAt = $row['latestCreatedAt'] ?? null;
                if ($apartmentId > 0 && is_string($latestCreatedAt) && $latestCreatedAt !== '') {
                    $latestOpenAnomalyDates[$apartmentId] = new \DateTimeImmutable($latestCreatedAt);
                }
            }
        }

        $apartmentCards = $this->buildApartmentCards($apartments, $entityManager);
        foreach ($apartmentCards as &$card) {
            $apartmentId = $card['apartment']->getId() ?? 0;
            $card['latestOpenAnomalyAt'] = $latestOpenAnomalyDates[$apartmentId] ?? null;
        }
        unset($card);

        $selectedHistoryApartmentId = max(0, (int) $request->query->get('historyApartment'));
        $selectedHistoryUserId = max(0, (int) $request->query->get('historyUser'));

        $historyFilterApartments = $entityManager->createQueryBuilder()
            ->select('DISTINCT apartment')
            ->from(Apartment::class, 'apartment')
            ->join(Anomaly::class, 'anomaly', 'WITH', 'anomaly.apartment = apartment')
            ->where('anomaly.status = :closedStatus')
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->orderBy('apartment.name', 'ASC')
            ->getQuery()
            ->getResult();

        $historyFilterUsers = $entityManager->createQueryBuilder()
            ->select('DISTINCT user')
            ->from(User::class, 'user')
            ->join(Anomaly::class, 'anomaly', 'WITH', 'anomaly.createdBy = user')
            ->where('anomaly.status = :closedStatus')
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->orderBy('user.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        $historyQueryBuilder = $entityManager->createQueryBuilder()
            ->select('anomaly')
            ->from(Anomaly::class, 'anomaly')
            ->where('anomaly.status = :closedStatus')
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->orderBy('anomaly.closedAt', 'DESC')
            ->setMaxResults(24);

        if ($selectedHistoryApartmentId > 0) {
            $historyQueryBuilder
                ->andWhere('IDENTITY(anomaly.apartment) = :historyApartmentId')
                ->setParameter('historyApartmentId', $selectedHistoryApartmentId);
        }

        if ($selectedHistoryUserId > 0) {
            $historyQueryBuilder
                ->andWhere('IDENTITY(anomaly.createdBy) = :historyUserId')
                ->setParameter('historyUserId', $selectedHistoryUserId);
        }

        return $this->render('admin/anomalies.html.twig', [
            'apartmentCards' => $apartmentCards,
            'historyAnomalies' => $historyQueryBuilder->getQuery()->getResult(),
            'historyFilterApartments' => $historyFilterApartments,
            'historyFilterUsers' => $historyFilterUsers,
            'selectedHistoryApartmentId' => $selectedHistoryApartmentId,
            'selectedHistoryUserId' => $selectedHistoryUserId,
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
                return new JsonResponse(['success' => false, 'message' => 'Jeton de sécurité invalide.'], 422);
            }

            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
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
                'message' => 'Anomalie supprimée de la liste.',
            ]);
        }

        $this->addFlash('success', 'Anomalie supprimée de la liste.');

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

    #[Route('/manuals', name: 'admin_manuals', methods: ['GET'])]
    public function manuals(Request $request, EntityManagerInterface $entityManager): Response
    {
        return $this->render('admin/manuals.html.twig', $this->buildManualsPageData($entityManager, $request));
    }

    #[Route('/manuals', name: 'admin_manual_create', methods: ['POST'])]
    public function createManual(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        if ($this->isRequestBodyTooLarge($request)) {
            return new JsonResponse(['success' => false, 'message' => $this->buildManualUploadErrorMessage($request)], 422);
        }

        $apartment = $entityManager->getRepository(Apartment::class)->find((int) $request->request->get('apartmentId'));
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Appartement introuvable.'], 422);
        }

        $equipmentLabel = $this->normalizeManualText((string) $request->request->get('equipmentLabel'));
        if ($equipmentLabel === '') {
            return new JsonResponse(['success' => false, 'message' => 'Renseigne l’équipement concerné.'], 422);
        }

        $video = $request->files->get('video');
        if (!$video instanceof UploadedFile) {
            return new JsonResponse(['success' => false, 'message' => $this->buildManualUploadErrorMessage($request)], 422);
        }

        try {
            $this->assertAcceptedVideoUpload($video, 6 * 1024 * 1024, 'La vidéo du manuel');
            $videoPath = $this->storeApartmentManualVideo($video);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $manual = (new ApartmentManual())
            ->setApartment($apartment)
            ->setTitle($equipmentLabel)
            ->setEquipmentLabel($equipmentLabel)
            ->setShortMessage(null)
            ->setImportantNotice($this->normalizeNullableManualText($request->request->get('importantNotice')))
            ->setDisplayOrder($apartment->getManuals()->count() + 1)
            ->setIsActive($request->request->getBoolean('isActive', true))
            ->setVideoPath($videoPath);

        $entityManager->persist($manual);
        $entityManager->flush();

        return $this->manualsContentResponse($entityManager, $request, 'Manuel ajoute.');
    }

    #[Route('/manuals/{id}', name: 'admin_manual_update', methods: ['POST'])]
    public function updateManual(ApartmentManual $manual, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        if ($this->isRequestBodyTooLarge($request)) {
            return new JsonResponse(['success' => false, 'message' => $this->buildManualUploadErrorMessage($request)], 422);
        }

        $apartment = $entityManager->getRepository(Apartment::class)->find((int) $request->request->get('apartmentId'));
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Appartement introuvable.'], 422);
        }

        $equipmentLabel = $this->normalizeManualText((string) $request->request->get('equipmentLabel'));
        if ($equipmentLabel === '') {
            return new JsonResponse(['success' => false, 'message' => 'Renseigne l’équipement concerné.'], 422);
        }

        $manual
            ->setApartment($apartment)
            ->setTitle($equipmentLabel)
            ->setEquipmentLabel($equipmentLabel)
            ->setShortMessage(null)
            ->setImportantNotice($this->normalizeNullableManualText($request->request->get('importantNotice')))
            ->setIsActive($request->request->getBoolean('isActive', true));

        $newVideo = $request->files->get('video');
        $previousVideoPath = $manual->getVideoPath();
        if ($newVideo instanceof UploadedFile) {
            try {
                $this->assertAcceptedVideoUpload($newVideo, 6 * 1024 * 1024, 'La vidéo du manuel');
                $manual->setVideoPath($this->storeApartmentManualVideo($newVideo));
            } catch (\InvalidArgumentException $exception) {
                return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            } catch (\RuntimeException $exception) {
                return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }
        }

        $entityManager->flush();

        if ($newVideo instanceof UploadedFile && $previousVideoPath !== $manual->getVideoPath()) {
            $this->deleteApartmentManualVideo($previousVideoPath);
        }

        return $this->manualsContentResponse($entityManager, $request, 'Manuel mis a jour.');
    }

    #[Route('/manuals/{id}/delete', name: 'admin_manual_delete', methods: ['POST'])]
    public function deleteManual(ApartmentManual $manual, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $videoPath = $manual->getVideoPath();
        $entityManager->remove($manual);
        $entityManager->flush();
        $this->deleteApartmentManualVideo($videoPath);

        return $this->manualsContentResponse($entityManager, $request, 'Manuel supprime.');
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
            ->setRoles(['ROLE_EMPLOYEE'])
            ->setIsActive($request->request->getBoolean('isActive', true))
            ->setCanManageAnomalyWorkflow($request->request->getBoolean('canManageAnomalyWorkflow'));

        try {
            $user->setPhoneNumber($this->normalizeMoroccanPhoneNumber($request->request->get('phoneNumber')));
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $photo = $request->files->get('photo');
        if ($photo instanceof UploadedFile) {
            try {
                $this->assertAcceptedImageUpload($photo, 8 * 1024 * 1024, 'La photo employé');
            } catch (\InvalidArgumentException $exception) {
                return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }

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
            ->setRoles(['ROLE_EMPLOYEE'])
            ->setIsActive($request->request->getBoolean('isActive'))
            ->setCanManageAnomalyWorkflow($request->request->getBoolean('canManageAnomalyWorkflow'));

        try {
            $user->setPhoneNumber($this->normalizeMoroccanPhoneNumber($request->request->get('phoneNumber')));
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $password = trim((string) $request->request->get('password'));
        if ($password !== '') {
            $user->setPassword($passwordHasher->hashPassword($user, $password));
        }

        $photo = $request->files->get('photo');
        if ($photo instanceof UploadedFile) {
            try {
                $this->assertAcceptedImageUpload($photo, 8 * 1024 * 1024, 'La photo employé');
            } catch (\InvalidArgumentException $exception) {
                return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }

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

        return $this->userDetailResponse($user, $entityManager, 'Information employé mise à jour.');
    }

    #[Route('/users/{id}/photo', name: 'admin_user_photo_update', methods: ['POST'])]
    public function updateUserPhoto(User $user, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->assertManageableEmployee($user);

        $photo = $request->files->get('photo');
        if (!$photo instanceof UploadedFile) {
            return new JsonResponse(['success' => false, 'message' => 'Ajoute une photo avant de valider.'], 422);
        }

        try {
            $this->assertAcceptedImageUpload($photo, 8 * 1024 * 1024, 'La photo employé');
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $previousPhotoPath = $user->getPhotoPath();
        $user->setPhotoPath($this->storeUserPhoto($photo));
        $entityManager->flush();
        $this->deleteUserPhoto($previousPhotoPath);

        return $this->userDetailResponse($user, $entityManager, 'Photo employé mise à jour.');
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
            return new JsonResponse(['success' => false, 'message' => 'Un service standard ne peut pas être supprimé ici.'], 422);
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
        if (!$this->isCsrfTokenValid('admin_apartment_create', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            $this->addFlash('error', 'Le nom de l appartement est obligatoire.');

            return $this->redirectToRoute('admin_apartments');
        }

        $templateApartmentId = max(0, (int) $request->request->get('templateApartmentId'));
        $templateApartment = $templateApartmentId > 0
            ? $entityManager->getRepository(Apartment::class)->find($templateApartmentId)
            : null;

        $apartment = (new Apartment())
            ->setName($name)
            ->setReferenceCode($this->generateApartmentReference($entityManager))
            ->setIsTenantAccessEnabled(true);

        if ($templateApartment instanceof Apartment) {
            $this->duplicateApartmentTemplate($templateApartment, $apartment);
        } else {
            $addressLine1 = trim((string) $request->request->get('addressLine1'));
            $city = trim((string) $request->request->get('city'));
            $postalCode = trim((string) $request->request->get('postalCode'));

            if ($addressLine1 === '' || $city === '') {
                $this->addFlash('error', 'Adresse et ville sont obligatoires pour créer un appartement vide.');

                return $this->redirectToRoute('admin_apartments');
            }

            $apartment
                ->setAddressLine1($addressLine1)
                ->setAddressLine2($this->nullable($request->request->get('addressLine2')))
                ->setCity($city)
                ->setPostalCode($postalCode)
                ->setFloor($this->nullable($request->request->get('floor')))
                ->setDoorNumber($this->nullable($request->request->get('doorNumber')))
                ->setMailboxNumber($this->nullable($request->request->get('mailboxNumber')))
                ->setGoogleMapsLink($this->nullable($request->request->get('googleMapsLink')))
                ->setBuildingAccessCode($this->nullable($request->request->get('buildingAccessCode')))
                ->setKeyBoxCode($this->nullable($request->request->get('keyBoxCode')))
                ->setEntryInstructions($this->sanitizeRichText($request->request->get('entryInstructions')))
                ->setConditionStatus((string) $request->request->get('conditionStatus', 'Bon état'))
                ->setBedroomCount((int) $request->request->get('bedroomCount', 0))
                ->setSleepsCount(0)
                ->setOwnerName($this->nullable($request->request->get('ownerName')))
                ->setOwnerPhone($this->nullable($request->request->get('ownerPhone')))
                ->setOwnerEmail($this->nullable($request->request->get('ownerEmail')))
                ->setInternalNotes($this->sanitizeRichText($request->request->get('internalNotes'), true))
                ->setStatus(ApartmentStatus::from((string) $request->request->get('status', ApartmentStatus::Active->value)))
                ->setIsInventoryPriority($request->request->getBoolean('isInventoryPriority'));

            $inventoryDueAt = $request->request->get('inventoryDueAt');
            if (is_string($inventoryDueAt) && $inventoryDueAt !== '') {
                $apartment->setInventoryDueAt(new \DateTimeImmutable($inventoryDueAt));
            }

            foreach ($entityManager->getRepository(User::class)->findBy(['id' => (array) $request->request->all('assignedEmployees')]) as $employee) {
                $apartment->addAssignedEmployee($employee);
            }
        }

        $image = $request->files->get('image');
        if ($image instanceof UploadedFile && $image->getError() !== UPLOAD_ERR_NO_FILE) {
            try {
                $this->assertAcceptedImageUpload($image, 8 * 1024 * 1024, 'L’image de l’appartement');
                $apartment->setImagePath($this->storeApartmentImage($image));
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('admin_apartments');
            }
        }

        $apartment->setWazeLink($this->buildWazeLink(
            $apartment->getAddressLine1(),
            $apartment->getCity(),
            $apartment->getPostalCode()
        ));

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
        $rawValue = (string) $request->request->get('value');
        $value = $this->isApartmentRichTextField($field) ? $rawValue : trim($rawValue);

        try {
            $this->applyAdminApartmentFieldUpdate($apartment, $field, $value);
            $entityManager->flush();
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Information appartement mise à jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    #[Route('/apartments/{id}/image', name: 'admin_apartment_image_update', methods: ['POST'])]
    public function updateApartmentImage(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $image = $request->files->get('image');
        if (!$image instanceof UploadedFile || $image->getError() === UPLOAD_ERR_NO_FILE) {
            return new JsonResponse(['success' => false, 'message' => 'Ajoute une image avant de valider.'], 422);
        }

        try {
            $this->assertAcceptedImageUpload($image, 8 * 1024 * 1024, 'L’image de l’appartement');
            $oldImagePath = $apartment->getImagePath();
            $apartment->setImagePath($this->storeApartmentImage($image));
            $entityManager->flush();
            $this->deleteApartmentImage($oldImagePath);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Image de l’appartement mise à jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
    }

    #[Route('/apartments/{id}/consumables', name: 'admin_consumable_item_create', methods: ['POST'])]
    public function createConsumableItem(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $item = $this->hydrateConsumableItem(new ConsumableItem(), $apartment, $request);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $entityManager->persist($item);
        $entityManager->flush();

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Consommable ajouté au stock.', 'consumables');
    }

    #[Route('/apartments/{id}/consumables/standards', name: 'admin_consumable_item_add_standards', methods: ['POST'])]
    public function addStandardConsumables(Apartment $apartment, EntityManagerInterface $entityManager): JsonResponse
    {
        $items = $entityManager->getRepository(ConsumableItem::class)->findBy(['apartment' => $apartment]);
        $itemsByName = [];
        foreach ($items as $item) {
            if ($item instanceof ConsumableItem) {
                $itemsByName[mb_strtolower($item->getName())] = $item;
            }
        }

        $addedCount = 0;
        foreach (self::STANDARD_CONSUMABLES as $standard) {
            $key = mb_strtolower($standard['name']);
            if (isset($itemsByName[$key])) {
                if (!$itemsByName[$key]->isActive()) {
                    $itemsByName[$key]->setActive(true);
                    ++$addedCount;
                }

                continue;
            }

            $item = (new ConsumableItem())
                ->setApartment($apartment)
                ->setName($standard['name'])
                ->setUnit($standard['unit'])
                ->setMinimumQuantity((int) $standard['minimumQuantity']);

            $entityManager->persist($item);
            ++$addedCount;
        }

        $entityManager->flush();

        $message = $addedCount > 0
            ? sprintf('%d consommable%s standard%s ajouté%s.', $addedCount, $addedCount > 1 ? 's' : '', $addedCount > 1 ? 's' : '', $addedCount > 1 ? 's' : '')
            : 'Les consommables standards sont déjà présents.';

        return $this->apartmentDetailResponse($apartment, $entityManager, $message, 'consumables');
    }

    #[Route('/consumables/{id}/update', name: 'admin_consumable_item_update', methods: ['POST'])]
    public function updateConsumableItem(ConsumableItem $item, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $apartment = $item->getApartment();
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Appartement introuvable.'], 404);
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->hydrateConsumableItem($item, $apartment, $request);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        if ($this->isConsumableStockBackToMinimum($item)) {
            $this->markConsumableAlertsRestocked($item, $entityManager, $actor);
        }

        $entityManager->flush();

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Consommable mis à jour.', 'consumables');
    }

    #[Route('/consumables/{id}/restock', name: 'admin_consumable_item_restock', methods: ['POST'])]
    public function restockConsumableItem(ConsumableItem $item, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $rawQuantity = trim((string) $request->request->get('addedQuantity'));
        if ($rawQuantity === '' || !ctype_digit($rawQuantity) || (int) $rawQuantity <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Renseigne la quantité ajoutée au stock.'], 422);
        }

        $item->setCurrentQuantity(($item->getCurrentQuantity() ?? 0) + (int) $rawQuantity);
        if ($this->isConsumableStockBackToMinimum($item)) {
            $this->markConsumableAlertsRestocked($item, $entityManager, $actor);
        }

        $entityManager->flush();

        if ((string) $request->request->get('context') === 'topbar') {
            return $this->consumableNotificationPayload($entityManager, 'Stock mis à jour.');
        }

        if ((string) $request->request->get('context') === 'dashboard') {
            return new JsonResponse([
                'success' => true,
                'html' => $this->renderView('admin/_dashboard_content.html.twig', $this->buildDashboardData($entityManager)),
                'message' => 'Stock mis à jour.',
            ]);
        }

        $apartment = $item->getApartment();
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => true, 'message' => 'Stock mis à jour.']);
        }

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Stock mis à jour.', 'consumables');
    }

    #[Route('/consumables/{id}/delete', name: 'admin_consumable_item_delete', methods: ['POST'])]
    public function deleteConsumableItem(ConsumableItem $item, EntityManagerInterface $entityManager): JsonResponse
    {
        $apartment = $item->getApartment();
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Appartement introuvable.'], 404);
        }

        $item->setActive(false);
        $entityManager->flush();

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Consommable retiré de la liste.', 'consumables');
    }

    #[Route('/consumables/notifications', name: 'admin_consumable_notifications', methods: ['GET'])]
    public function consumableNotifications(EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->consumableNotificationPayload($entityManager);
    }

    #[Route('/consumable-checks/{id}/restock', name: 'admin_consumable_check_restock', methods: ['POST'])]
    public function markConsumableRestocked(ConsumableCheck $check, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $item = $check->getConsumableItem();
        if (!$item instanceof ConsumableItem) {
            return new JsonResponse(['success' => false, 'message' => 'Consommable introuvable.'], 404);
        }

        $rawQuantity = trim((string) $request->request->get('addedQuantity'));
        if ($rawQuantity === '' || !ctype_digit($rawQuantity) || (int) $rawQuantity <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Renseigne la quantité ajoutée au stock.'], 422);
        }

        $addedQuantity = (int) $rawQuantity;
        $currentQuantity = $item->getCurrentQuantity() ?? $check->getQuantity() ?? 0;
        $item->setCurrentQuantity($currentQuantity + $addedQuantity);

        if ($this->isConsumableStockBackToMinimum($item)) {
            $this->markConsumableAlertsRestocked($item, $entityManager, $actor);
        }

        $entityManager->flush();

        if ((string) $request->request->get('context') === 'topbar') {
            return $this->consumableNotificationPayload($entityManager, 'Stock mis à jour.');
        }

        if ((string) $request->request->get('context') === 'dashboard') {
            return new JsonResponse([
                'success' => true,
                'html' => $this->renderView('admin/_dashboard_content.html.twig', $this->buildDashboardData($entityManager)),
                'message' => 'Stock mis à jour.',
            ]);
        }

        $apartment = $check->getApartment();
        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => true, 'message' => 'Stock mis à jour.']);
        }

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Stock mis à jour.', 'consumables');
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
        $hasCheckouts = $this->hasCheckoutHistory($apartment, $entityManager);
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
        $apartmentImagePath = $apartment->getImagePath();

        $entityManager->remove($apartment);
        $entityManager->flush();

        foreach ($accessStepImagePaths as $imagePath) {
            $this->deleteApartmentAccessStepImage($imagePath);
        }
        $this->deleteApartmentImage($apartmentImagePath);

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

    #[Route('/apartments/{id}/tenant-access', name: 'admin_apartment_tenant_access', methods: ['POST'])]
    public function toggleTenantAccess(Apartment $apartment, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $isEnabled = $request->request->getBoolean('isTenantAccessEnabled');

        $apartment->setIsTenantAccessEnabled($isEnabled);
        $apartment->setTenantAccessLockedAt($isEnabled ? null : new \DateTimeImmutable());
        $entityManager->flush();

        return $this->apartmentDetailResponse(
            $apartment,
            $entityManager,
            $isEnabled ? 'Accès locataire réactivé.' : 'Accès locataire bloqué.',
            $this->normalizeApartmentDetailSection((string) $request->request->get('section'))
        );
    }

    #[Route('/apartments/{id}/reservations', name: 'admin_apartment_reservation_create', methods: ['POST'])]
    public function createApartmentReservation(
        Apartment $apartment,
        Request $request,
        EntityManagerInterface $entityManager,
        ApartmentReservationMessenger $reservationMessenger,
        CheckoutManager $checkoutManager
    ): JsonResponse {
        /** @var User|null $actor */
        $actor = $this->getUser();

        try {
            $reservation = $this->buildApartmentReservationFromRequest(
                new ApartmentReservation(),
                $apartment,
                $request,
                $reservationMessenger,
                $entityManager
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $reservation->setCreatedBy($actor);
        $apartment->addReservation($reservation);

        $checkoutMessage = null;
        $scheduledCheckoutAt = $reservation->getDepartureDate()?->setTime(11, 0);
        $assignedEmployee = $this->resolveReservationCheckoutAssignee($apartment, $actor);
        if ($assignedEmployee instanceof User && $scheduledCheckoutAt instanceof \DateTimeImmutable) {
            $existingCheckout = $this->findCheckoutConflict($apartment, $scheduledCheckoutAt, $entityManager);
            if ($existingCheckout instanceof Checkout) {
                $checkoutMessage = 'Un check-out était déjà programmé pour la date de départ.';
            } else {
                $reservation->setLinkedCheckout(
                    $checkoutManager->createCheckout($apartment, $assignedEmployee, 'normal', $scheduledCheckoutAt)
                );
                $checkoutMessage = 'Le check-out de fin de séjour a été programmé automatiquement.';
            }
        } elseif ($scheduledCheckoutAt instanceof \DateTimeImmutable) {
            $checkoutMessage = 'Aucun employé terrain n’est assigné : le check-out de fin de séjour reste à programmer.';
        }

        $entityManager->persist($reservation);
        $entityManager->flush();

        $message = $reservation->isArrivalToday(new \DateTimeImmutable('today'))
            ? 'Réservation enregistrée. Le message WhatsApp peut être envoyé aujourd’hui.'
            : 'Réservation enregistrée.';

        if ($checkoutMessage !== null) {
            $message .= ' ' . $checkoutMessage;
        }

        return $this->reservationContextResponse($reservation, $request, $entityManager, $message);
    }

    #[Route('/reservations/{id}/field', name: 'admin_reservation_field_update', methods: ['POST'])]
    public function updateReservationField(
        ApartmentReservation $reservation,
        Request $request,
        EntityManagerInterface $entityManager,
        ApartmentReservationMessenger $reservationMessenger
    ): JsonResponse {
        $field = (string) $request->request->get('field');
        $value = trim((string) $request->request->get('value'));

        try {
            match ($field) {
                'guestName' => $this->applyReservationGuestName($reservation, $value),
                'guestWhatsappNumber' => $reservation->setGuestWhatsappNumber($reservationMessenger->normalizeWhatsAppNumber($value)),
                default => throw new \InvalidArgumentException('Champ réservation non modifiable.'),
            };

            $this->assertReservationDoesNotOverlap($reservation, $entityManager);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return $this->reservationContextResponse($reservation, $request, $entityManager, 'Réservation mise à jour.');
    }

    #[Route('/reservations/{id}/send-access', name: 'admin_reservation_send_access', methods: ['POST'])]
    public function sendReservationAccess(
        ApartmentReservation $reservation,
        Request $request,
        EntityManagerInterface $entityManager,
        ApartmentReservationMessenger $reservationMessenger
    ): JsonResponse {
        $siteUrl = $this->generateUrl('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $whatsAppUrl = $reservationMessenger->buildWhatsAppUrl($reservation, $siteUrl);

        $reservation
            ->setAccessMessageSentAt(new \DateTimeImmutable())
            ->incrementAccessMessageSentCount();

        $entityManager->flush();

        $context = (string) $request->request->get('context', 'apartment');
        $html = null;
        if ($context === 'arrivals') {
            $html = $this->renderView('admin/_arrivals_content.html.twig', $this->buildArrivalsPageData($entityManager));
        } else {
            $apartment = $reservation->getApartment();
            if ($apartment instanceof Apartment) {
                $html = $this->renderView(
                    'admin/_apartment_detail_content.html.twig',
                    $this->buildApartmentDetailData(
                        $apartment,
                        $entityManager,
                        $this->normalizeApartmentDetailSection((string) $request->request->get('section'))
                    )
                );
            }
        }

        return new JsonResponse([
            'success' => true,
            'html' => $html,
            'redirect' => $whatsAppUrl,
            'message' => $reservation->getAccessMessageSentCount() > 1 ? 'Message WhatsApp renvoyé.' : 'Message WhatsApp prêt à être envoyé.',
        ]);
    }

    #[Route('/reservations/{id}/delete', name: 'admin_reservation_delete', methods: ['POST'])]
    public function deleteReservation(
        ApartmentReservation $reservation,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $apartment = $reservation->getApartment();
        $this->cancelCheckoutLinkedToReservation($reservation, $entityManager);
        if ($apartment instanceof Apartment) {
            $apartment->removeReservation($reservation);
        }

        $entityManager->remove($reservation);
        $entityManager->flush();

        $context = (string) $request->request->get('context', 'arrivals');

        return match ($context) {
            'dashboard' => new JsonResponse([
                'success' => true,
                'html' => $this->renderView('admin/_dashboard_content.html.twig', $this->buildDashboardData($entityManager)),
                'message' => 'Réservation annulée.',
            ]),
            'apartment' => $apartment instanceof Apartment
                ? $this->apartmentDetailResponse(
                    $apartment,
                    $entityManager,
                    'Réservation annulée.',
                    $this->normalizeApartmentDetailSection((string) $request->request->get('section'))
                )
                : new JsonResponse(['success' => true, 'message' => 'Réservation annulée.']),
            default => new JsonResponse([
                'success' => true,
                'html' => $this->renderView('admin/_arrivals_content.html.twig', $this->buildArrivalsPageData($entityManager)),
                'message' => 'Réservation annulée.',
            ]),
        };
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
            try {
                $this->assertAcceptedImageUpload($image, 8 * 1024 * 1024, 'L’image d’étape');
            } catch (\InvalidArgumentException $exception) {
                return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }

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
            try {
                $this->assertAcceptedImageUpload($newImage, 8 * 1024 * 1024, 'L’image d’étape');
            } catch (\InvalidArgumentException $exception) {
                return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
            }

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

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Pièce ajoutée.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
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
        $selectedQuantity = $request->request->get('selectedQuantity');
        $quantitySource = is_numeric((string) $selectedQuantity) && (int) $selectedQuantity > 0
            ? (int) $selectedQuantity
            : (int) $request->request->get('quantity', 1);
        $quantity = max(1, $quantitySource);
        $addedCount = 0;

        $catalogIds = array_values(array_unique(array_filter(
            array_merge(
                array_map('intval', (array) $request->request->all('catalogEquipmentIds')),
                [max(0, (int) $request->request->get('catalogEquipmentId'))]
            ),
            static fn (int $id): bool => $id > 0
        )));

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
                    ->setQuantity($quantity)
                    ->setCatalogEquipment($catalogEquipment)
                    ->setLabel($catalogEquipment->getName());

                $room->addRoomEquipment($equipment);
                $entityManager->persist($equipment);
                ++$addedCount;
            }
        }

        $manualLabel = trim((string) $request->request->get('manualLabel'));
        if ($manualLabel !== '') {
            $equipment = new RoomEquipment();
            $equipment
                ->setDisplayOrder($displayOrder++)
                ->setNotes($notes)
                ->setQuantity($quantity)
                ->setLabel($manualLabel);

            $room->addRoomEquipment($equipment);
            $entityManager->persist($equipment);
            ++$addedCount;
        }

        if ($addedCount === 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Sélectionne un équipement ou saisis un équipement manuel.',
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
                'message' => 'Suppression impossible : retire d’abord les équipements de cette pièce.',
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

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Pièce supprimée.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
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

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Équipement supprimé de la pièce.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
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

        if (in_array('ROLE_ADMIN', $employee->getRoles(), true)) {
            return new JsonResponse(['success' => false, 'message' => 'Choisis un employé terrain pour ce check-out.'], 422);
        }

        if (!$apartment->getAssignedEmployees()->contains($employee)) {
            $apartment->addAssignedEmployee($employee);
        }

        $scheduledAtRaw = (string) $request->request->get('scheduledAt');
        $scheduledAt = $scheduledAtRaw !== '' ? new \DateTimeImmutable($scheduledAtRaw) : new \DateTimeImmutable();
        if ($this->findCheckoutConflict($apartment, $scheduledAt, $entityManager) instanceof Checkout) {
            return new JsonResponse(['success' => false, 'message' => 'Un check-out est déjà programmé à cette date pour cet appartement.'], 422);
        }
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
    public function showCheckout(Checkout $checkout, EntityManagerInterface $entityManager): Response
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
            'checkoutRoomBulkOkRoute' => 'admin_checkout_room_bulk_ok',
            'checkoutConsumableUpdateRoute' => 'admin_checkout_consumable_update',
            ...$this->buildCheckoutConsumableTemplateData($checkout, $entityManager),
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

    #[Route('/checkouts/{checkout}/rooms/{room}/bulk-ok', name: 'admin_checkout_room_bulk_ok', methods: ['POST'])]
    public function markCheckoutRoomOk(Checkout $checkout, Room $room, CheckoutManager $checkoutManager, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);

        if ($this->findCheckoutRoomGroup($checkout, $room) === null) {
            return new JsonResponse(['success' => false, 'message' => 'Pièce introuvable pour ce check-out.'], 404);
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $validatedCount = $checkoutManager->markUncheckedRoomLinesOk($checkout, $room, $actor);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $message = $validatedCount > 0
            ? sprintf('%d équipement%s validé%s en OK.', $validatedCount, $validatedCount > 1 ? 's' : '', $validatedCount > 1 ? 's' : '')
            : 'Aucun équipement en attente dans cette pièce.';

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('employee/_checkout_workspace.html.twig', [
                'checkout' => $checkout,
                'roomGroups' => $this->buildCheckoutRoomGroups($checkout),
                'dashboardRoute' => 'admin_dashboard',
                'checkoutShowRoute' => 'admin_checkout_show',
                'checkoutRoomRoute' => 'admin_checkout_room_show',
                'checkoutPauseRoute' => 'admin_checkout_pause',
                'checkoutResumeRoute' => 'admin_checkout_resume',
                'checkoutCompleteRoute' => 'admin_checkout_complete',
                'checkoutLineUpdateRoute' => 'admin_checkout_line_update',
                'checkoutRoomBulkOkRoute' => 'admin_checkout_room_bulk_ok',
                'checkoutConsumableUpdateRoute' => 'admin_checkout_consumable_update',
                ...$this->buildCheckoutConsumableTemplateData($checkout, $entityManager),
            ]),
            'message' => $message,
        ]);
    }

    #[Route('/checkouts/{checkout}/consumables/{item}', name: 'admin_checkout_consumable_update', methods: ['POST'])]
    public function updateCheckoutConsumable(Checkout $checkout, ConsumableItem $item, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->saveCheckoutConsumableCheck($checkout, $item, $request, $entityManager, $actor);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('employee/_checkout_workspace.html.twig', [
                'checkout' => $checkout,
                'roomGroups' => $this->buildCheckoutRoomGroups($checkout),
                'dashboardRoute' => 'admin_dashboard',
                'checkoutShowRoute' => 'admin_checkout_show',
                'checkoutRoomRoute' => 'admin_checkout_room_show',
                'checkoutPauseRoute' => 'admin_checkout_pause',
                'checkoutResumeRoute' => 'admin_checkout_resume',
                'checkoutCompleteRoute' => 'admin_checkout_complete',
                'checkoutLineUpdateRoute' => 'admin_checkout_line_update',
                'checkoutRoomBulkOkRoute' => 'admin_checkout_room_bulk_ok',
                'checkoutConsumableUpdateRoute' => 'admin_checkout_consumable_update',
                ...$this->buildCheckoutConsumableTemplateData($checkout, $entityManager),
            ]),
            'message' => 'Stock consommable mis à jour.',
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
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $checkoutManager->updateLine(
                $line,
                EquipmentCheckStatus::from((string) $request->request->get('status')),
                $request->request->get('comment'),
                $request->files->get('photo'),
                $actor,
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

        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('employee/_checkout_completion.html.twig', [
                'apartmentName' => $checkout->getApartment()?->getName() ?? 'Appartement',
            ]),
            'redirect' => $this->hasCheckoutConsumables($checkout, $entityManager)
                ? $this->generateUrl('admin_checkout_consumable_inventory', ['id' => $checkout->getId()])
                : $this->generateUrl('admin_dashboard'),
            'redirectDelayMs' => 1200,
            'message' => 'Check-out terminé.',
        ]);
    }

    #[Route('/checkouts/{id}/consumables/inventory', name: 'admin_checkout_consumable_inventory', methods: ['GET'])]
    public function consumableInventory(Checkout $checkout, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);
        $this->denyConsumableInventoryUnlessCheckoutCompleted($checkout);

        return $this->render('employee/consumable_inventory.html.twig', [
            'checkout' => $checkout,
            'dashboardRoute' => 'admin_dashboard',
            'inventoryRoute' => 'admin_checkout_consumable_inventory',
            'skipRoute' => 'admin_checkout_consumable_inventory_skip',
            'quantityRoute' => 'admin_checkout_consumable_quantity_update',
            ...$this->buildConsumableInventoryData($checkout, $entityManager, $request),
        ]);
    }

    #[Route('/checkouts/{id}/consumables/inventory/skip', name: 'admin_checkout_consumable_inventory_skip', methods: ['POST'])]
    public function skipConsumableInventory(Checkout $checkout): Response
    {
        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);
        $this->addFlash('success', 'Check-out terminé. Inventaire consommables ignoré.');

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/checkouts/{checkout}/consumables/{item}/quantity', name: 'admin_checkout_consumable_quantity_update', methods: ['POST'])]
    public function updateConsumableInventoryQuantity(Checkout $checkout, ConsumableItem $item, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGrantedToAdminCheckout($checkout);
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->saveConsumableInventoryQuantity($checkout, $item, $request, $entityManager, $actor);
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_checkout_consumable_inventory', [
                'id' => $checkout->getId(),
                'start' => 1,
                'item' => $item->getId(),
            ]);
        }

        return $this->redirectToRoute('admin_checkout_consumable_inventory', [
            'id' => $checkout->getId(),
            'start' => 1,
        ]);
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

        return $this->apartmentDetailResponse($apartment, $entityManager, 'Date du check-out mise à jour.', $this->normalizeApartmentDetailSection((string) $request->request->get('section')));
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

    private function reservationContextResponse(
        ApartmentReservation $reservation,
        Request $request,
        EntityManagerInterface $entityManager,
        string $message
    ): JsonResponse {
        $context = (string) $request->request->get('context', 'apartment');
        $apartment = $reservation->getApartment();

        if (!$apartment instanceof Apartment) {
            return new JsonResponse(['success' => false, 'message' => 'Appartement introuvable.'], 404);
        }

        return match ($context) {
            'arrivals' => new JsonResponse([
                'success' => true,
                'html' => $this->renderView('admin/_arrivals_content.html.twig', $this->buildArrivalsPageData($entityManager)),
                'message' => $message,
            ]),
            'dashboard' => new JsonResponse([
                'success' => true,
                'html' => $this->renderView('admin/_dashboard_content.html.twig', $this->buildDashboardData($entityManager)),
                'message' => $message,
            ]),
            default => $this->apartmentDetailResponse(
                $apartment,
                $entityManager,
                $message,
                $this->normalizeApartmentDetailSection((string) $request->request->get('section'))
            ),
        };
    }

    private function buildApartmentReservationFromRequest(
        ApartmentReservation $reservation,
        Apartment $apartment,
        Request $request,
        ApartmentReservationMessenger $reservationMessenger,
        EntityManagerInterface $entityManager
    ): ApartmentReservation {
        $guestName = trim((string) $request->request->get('guestName'));
        if ($guestName === '') {
            throw new \InvalidArgumentException('Le nom du locataire est obligatoire.');
        }

        $arrivalDateRaw = trim((string) $request->request->get('arrivalDate'));
        $departureDateRaw = trim((string) $request->request->get('departureDate'));
        if ($arrivalDateRaw === '' || $departureDateRaw === '') {
            throw new \InvalidArgumentException('La période de séjour est obligatoire.');
        }

        try {
            $arrivalDate = new \DateTimeImmutable($arrivalDateRaw);
            $departureDate = new \DateTimeImmutable($departureDateRaw);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Les dates de réservation sont invalides.');
        }

        $arrivalDate = $arrivalDate->setTime(0, 0);
        $departureDate = $departureDate->setTime(0, 0);

        if ($departureDate < $arrivalDate) {
            throw new \InvalidArgumentException('La date de départ doit être postérieure ou égale à la date d’arrivée.');
        }

        $reservation
            ->setApartment($apartment)
            ->setGuestName($guestName)
            ->setGuestWhatsappNumber($reservationMessenger->normalizeWhatsAppNumber($request->request->get('guestWhatsappNumber')))
            ->setArrivalDate($arrivalDate)
            ->setDepartureDate($departureDate);

        $this->assertReservationDoesNotOverlap($reservation, $entityManager);

        return $reservation;
    }

    private function applyReservationGuestName(ApartmentReservation $reservation, string $value): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Le nom du locataire est obligatoire.');
        }

        $reservation->setGuestName($value);
    }

    private function assertReservationDoesNotOverlap(ApartmentReservation $reservation, EntityManagerInterface $entityManager): void
    {
        $apartment = $reservation->getApartment();
        $arrivalDate = $reservation->getArrivalDate();
        $departureDate = $reservation->getDepartureDate();

        if (!$apartment instanceof Apartment || !$arrivalDate instanceof \DateTimeImmutable || !$departureDate instanceof \DateTimeImmutable) {
            return;
        }

        $existingReservations = $entityManager->getRepository(ApartmentReservation::class)->findBy(
            ['apartment' => $apartment],
            ['arrivalDate' => 'ASC']
        );

        foreach ($existingReservations as $existingReservation) {
            if (!$existingReservation instanceof ApartmentReservation) {
                continue;
            }

            if ($existingReservation->getId() === $reservation->getId()) {
                continue;
            }

            $existingArrival = $existingReservation->getArrivalDate();
            $existingDeparture = $existingReservation->getDepartureDate();
            if (!$existingArrival instanceof \DateTimeImmutable || !$existingDeparture instanceof \DateTimeImmutable) {
                continue;
            }

            if ($arrivalDate <= $existingDeparture && $departureDate >= $existingArrival) {
                throw new \InvalidArgumentException('Une autre réservation couvre déjà cette période pour cet appartement.');
            }
        }
    }

    private function resolveReservationCheckoutAssignee(Apartment $apartment, ?User $actor): ?User
    {
        foreach ($apartment->getAssignedEmployees() as $employee) {
            if ($employee instanceof User && !in_array('ROLE_ADMIN', $employee->getRoles(), true)) {
                return $employee;
            }
        }

        return $actor instanceof User ? $actor : null;
    }

    private function findCheckoutConflict(
        Apartment $apartment,
        \DateTimeImmutable $scheduledAt,
        EntityManagerInterface $entityManager,
        ?Checkout $ignoredCheckout = null
    ): ?Checkout {
        $openStatuses = [
            CheckoutStatus::Todo,
            CheckoutStatus::InProgress,
            CheckoutStatus::Paused,
            CheckoutStatus::PendingValidation,
            CheckoutStatus::Blocked,
        ];

        $dayStart = $scheduledAt->setTime(0, 0);
        $dayEnd = $scheduledAt->setTime(23, 59, 59);
        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('checkout')
            ->from(Checkout::class, 'checkout')
            ->where('checkout.apartment = :apartment')
            ->andWhere('checkout.status IN (:statuses)')
            ->andWhere('checkout.scheduledAt IS NOT NULL')
            ->andWhere('checkout.scheduledAt >= :dayStart')
            ->andWhere('checkout.scheduledAt <= :dayEnd')
            ->setParameter('apartment', $apartment)
            ->setParameter('statuses', $openStatuses)
            ->setParameter('dayStart', $dayStart)
            ->setParameter('dayEnd', $dayEnd)
            ->orderBy('checkout.scheduledAt', 'ASC')
            ->setMaxResults(1);

        if ($ignoredCheckout instanceof Checkout && $ignoredCheckout->getId() !== null) {
            $queryBuilder
                ->andWhere('checkout.id != :ignoredCheckoutId')
                ->setParameter('ignoredCheckoutId', $ignoredCheckout->getId());
        }

        return $queryBuilder->getQuery()->getOneOrNullResult();
    }

    private function cancelCheckoutLinkedToReservation(ApartmentReservation $reservation, EntityManagerInterface $entityManager): void
    {
        $checkout = $reservation->getLinkedCheckout();
        if (
            !$checkout instanceof Checkout
            && $reservation->getApartment() instanceof Apartment
            && $reservation->getDepartureDate() instanceof \DateTimeImmutable
        ) {
            $checkout = $this->findCheckoutConflict(
                $reservation->getApartment(),
                $reservation->getDepartureDate()->setTime(11, 0),
                $entityManager
            );
        }

        if (!$checkout instanceof Checkout) {
            return;
        }

        if (in_array($checkout->getStatus(), [CheckoutStatus::Completed, CheckoutStatus::Cancelled], true)) {
            return;
        }

        $checkout
            ->setStatus(CheckoutStatus::Cancelled)
            ->setPausedAt(null)
            ->setPauseReason(null)
            ->setBlockReason(null);
    }

    /**
     * @return list<ApartmentReservation>
     */
    private function findUpcomingReservations(EntityManagerInterface $entityManager, ?User $employee = null, int $limit = 50): array
    {
        $today = new \DateTimeImmutable('today');
        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('reservation', 'apartment', 'createdBy')
            ->from(ApartmentReservation::class, 'reservation')
            ->join('reservation.apartment', 'apartment')
            ->leftJoin('reservation.createdBy', 'createdBy')
            ->leftJoin('reservation.checkin', 'checkin')
            ->where('apartment.status = :activeStatus')
            ->andWhere('reservation.departureDate >= :today')
            ->andWhere('checkin.id IS NULL')
            ->setParameter('activeStatus', ApartmentStatus::Active)
            ->setParameter('today', $today, 'date_immutable')
            ->orderBy('reservation.arrivalDate', 'ASC')
            ->addOrderBy('reservation.id', 'DESC')
            ->setMaxResults($limit);

        if ($employee instanceof User) {
            $queryBuilder
                ->join('apartment.assignedEmployees', 'assignedEmployee')
                ->andWhere('assignedEmployee = :employee')
                ->setParameter('employee', $employee);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @return list<ApartmentReservation>
     */
    private function findApartmentReservations(Apartment $apartment, EntityManagerInterface $entityManager): array
    {
        $today = new \DateTimeImmutable('today');

        return $entityManager->createQueryBuilder()
            ->select('reservation', 'createdBy')
            ->from(ApartmentReservation::class, 'reservation')
            ->leftJoin('reservation.createdBy', 'createdBy')
            ->leftJoin('reservation.checkin', 'checkin')
            ->where('reservation.apartment = :apartment')
            ->andWhere('reservation.departureDate >= :today')
            ->andWhere('checkin.id IS NULL')
            ->setParameter('apartment', $apartment)
            ->setParameter('today', $today, 'date_immutable')
            ->orderBy('reservation.arrivalDate', 'ASC')
            ->addOrderBy('reservation.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApartmentDetailData(Apartment $apartment, EntityManagerInterface $entityManager, ?string $currentSection = null): array
    {
        $anomalies = $entityManager->getRepository(Anomaly::class)->findBy(['apartment' => $apartment], ['createdAt' => 'DESC']);
        $normalizedSection = $this->normalizeApartmentDetailSection($currentSection);
        $reservations = $this->findApartmentReservations($apartment, $entityManager);
        $today = new \DateTimeImmutable('today');
        $nextArrivalReservation = null;
        foreach ($reservations as $reservation) {
            if (!$reservation instanceof ApartmentReservation || !$reservation->getDepartureDate() instanceof \DateTimeImmutable) {
                continue;
            }

            if ($reservation->getDepartureDate() >= $today) {
                $nextArrivalReservation = $reservation;
                break;
            }
        }

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
            'reservations' => $reservations,
            'nextArrivalReservation' => $nextArrivalReservation,
            'todayDate' => $today,
            'consumableItems' => $this->findConsumableItems($apartment, $entityManager),
            'consumableStatuses' => ConsumableCheckStatus::cases(),
            'apartmentConsumableRestockAlerts' => $this->buildConsumableRestockAlertRows($entityManager, $apartment),
            'hasOpenCheckout' => $this->hasOpenCheckout($apartment, $entityManager),
            'anomalyCount' => count($anomalies),
            'anomalyGroups' => $this->buildAnomalyGroups($anomalies, $this->buildApartmentRepeatCounts($apartment, $entityManager)),
            'canDeleteApartment' => !$this->hasCheckoutHistory($apartment, $entityManager)
                && !$this->hasOpenAnomalies($apartment, $entityManager),
        ];
    }

    private function hydrateConsumableItem(ConsumableItem $item, Apartment $apartment, Request $request): ConsumableItem
    {
        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            throw new \InvalidArgumentException('Le nom du consommable est obligatoire.');
        }

        $currentQuantityValue = trim((string) $request->request->get('currentQuantity', ''));

        return $item
            ->setApartment($apartment)
            ->setName($name)
            ->setUnit($request->request->get('unit') !== null ? (string) $request->request->get('unit') : null)
            ->setMinimumQuantity(max(0, (int) $request->request->get('minimumQuantity', 0)))
            ->setCurrentQuantity($currentQuantityValue !== '' ? max(0, (int) $currentQuantityValue) : null)
            ->setActive(true);
    }

    /**
     * @return list<ConsumableItem>
     */
    private function findConsumableItems(Apartment $apartment, EntityManagerInterface $entityManager): array
    {
        return $entityManager->getRepository(ConsumableItem::class)->findBy(
            ['apartment' => $apartment, 'active' => true],
            ['name' => 'ASC']
        );
    }

    /**
     * @return list<ConsumableCheck>
     */
    private function findOpenConsumableRestockAlerts(EntityManagerInterface $entityManager, ?Apartment $apartment = null, ?int $limit = null): array
    {
        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('consumableCheck', 'item', 'apartment', 'checkout', 'checkedBy')
            ->from(ConsumableCheck::class, 'consumableCheck')
            ->join('consumableCheck.consumableItem', 'item')
            ->join('consumableCheck.apartment', 'apartment')
            ->join('consumableCheck.checkout', 'checkout')
            ->leftJoin('consumableCheck.checkedBy', 'checkedBy')
            ->where('consumableCheck.status IN (:statuses)')
            ->andWhere('consumableCheck.restockedAt IS NULL')
            ->andWhere('item.active = :active')
            ->setParameter('statuses', [ConsumableCheckStatus::Low, ConsumableCheckStatus::Missing])
            ->setParameter('active', true)
            ->orderBy('consumableCheck.checkedAt', 'DESC');

        if ($apartment instanceof Apartment) {
            $queryBuilder
                ->andWhere('consumableCheck.apartment = :apartmentFilter')
                ->setParameter('apartmentFilter', $apartment);
        }

        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    private function consumableNotificationPayload(EntityManagerInterface $entityManager, string $message = ''): JsonResponse
    {
        $alerts = $this->buildConsumableRestockAlertRows($entityManager, null, 12);

        return new JsonResponse([
            'success' => true,
            'count' => count($alerts),
            'html' => $this->renderView('admin/_consumable_notifications_panel.html.twig', [
                'consumableRestockAlerts' => $alerts,
            ]),
            'message' => $message,
        ]);
    }

    /**
     * @return list<array{kind:string,key:string,item:ConsumableItem,apartment:Apartment,checkout:?Checkout,status:ConsumableCheckStatus,quantity:?int,note:?string,restockRoute:string,routeParams:array<string,int>}>
     */
    private function buildConsumableRestockAlertRows(EntityManagerInterface $entityManager, ?Apartment $apartment = null, ?int $limit = null): array
    {
        $alerts = [];
        $itemIdsWithAlert = [];

        foreach ($this->findOpenConsumableRestockAlerts($entityManager, $apartment) as $check) {
            $item = $check->getConsumableItem();
            $alertApartment = $check->getApartment();
            if (!$item instanceof ConsumableItem || !$alertApartment instanceof Apartment) {
                continue;
            }

            $currentQuantity = $item->getCurrentQuantity();
            if ($currentQuantity !== null && $this->isConsumableStockBackToMinimum($item)) {
                continue;
            }

            $itemId = $item->getId();
            if ($itemId !== null) {
                $itemIdsWithAlert[$itemId] = true;
            }

            $alerts[] = [
                'kind' => 'check',
                'key' => 'check-' . $check->getId(),
                'item' => $item,
                'apartment' => $alertApartment,
                'checkout' => $check->getCheckout(),
                'status' => $check->getStatus(),
                'quantity' => $currentQuantity ?? $check->getQuantity(),
                'note' => $check->getNote(),
                'restockRoute' => 'admin_consumable_check_restock',
                'routeParams' => ['id' => (int) $check->getId()],
            ];
        }

        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('item', 'apartment')
            ->from(ConsumableItem::class, 'item')
            ->join('item.apartment', 'apartment')
            ->where('item.active = :active')
            ->andWhere('item.currentQuantity IS NOT NULL')
            ->andWhere('item.minimumQuantity > 0')
            ->andWhere('item.currentQuantity < item.minimumQuantity')
            ->setParameter('active', true)
            ->orderBy('apartment.name', 'ASC')
            ->addOrderBy('item.name', 'ASC');

        if ($apartment instanceof Apartment) {
            $queryBuilder
                ->andWhere('item.apartment = :apartmentFilter')
                ->setParameter('apartmentFilter', $apartment);
        }

        foreach ($queryBuilder->getQuery()->getResult() as $item) {
            if (!$item instanceof ConsumableItem || !$item->getApartment() instanceof Apartment || $item->getId() === null || isset($itemIdsWithAlert[$item->getId()])) {
                continue;
            }

            $alerts[] = [
                'kind' => 'item',
                'key' => 'item-' . $item->getId(),
                'item' => $item,
                'apartment' => $item->getApartment(),
                'checkout' => null,
                'status' => ($item->getCurrentQuantity() ?? 0) <= 0 ? ConsumableCheckStatus::Missing : ConsumableCheckStatus::Low,
                'quantity' => $item->getCurrentQuantity(),
                'note' => null,
                'restockRoute' => 'admin_consumable_item_restock',
                'routeParams' => ['id' => $item->getId()],
            ];
        }

        usort($alerts, static fn (array $left, array $right): int => strcasecmp($left['apartment']->getName(), $right['apartment']->getName()) ?: strcasecmp($left['item']->getName(), $right['item']->getName()));

        return $limit !== null ? array_slice($alerts, 0, $limit) : $alerts;
    }

    private function isConsumableStockBackToMinimum(ConsumableItem $item): bool
    {
        $currentQuantity = $item->getCurrentQuantity();
        if ($currentQuantity === null) {
            return false;
        }

        $minimumQuantity = $item->getMinimumQuantity() > 0 ? $item->getMinimumQuantity() : 1;

        return $currentQuantity >= $minimumQuantity;
    }

    private function markConsumableAlertsRestocked(ConsumableItem $item, EntityManagerInterface $entityManager, User $actor): void
    {
        $openAlerts = $entityManager->createQueryBuilder()
            ->select('consumableCheck')
            ->from(ConsumableCheck::class, 'consumableCheck')
            ->where('consumableCheck.consumableItem = :item')
            ->andWhere('consumableCheck.status IN (:statuses)')
            ->andWhere('consumableCheck.restockedAt IS NULL')
            ->setParameter('item', $item)
            ->setParameter('statuses', [ConsumableCheckStatus::Low, ConsumableCheckStatus::Missing])
            ->getQuery()
            ->getResult();

        foreach ($openAlerts as $alert) {
            if ($alert instanceof ConsumableCheck) {
                $alert->markRestocked($actor);
            }
        }
    }

    /**
     * @return array{consumableItems:list<ConsumableItem>, consumableChecksByItemId:array<int, ConsumableCheck>, consumableStatuses:list<ConsumableCheckStatus>}
     */
    private function buildCheckoutConsumableTemplateData(Checkout $checkout, EntityManagerInterface $entityManager): array
    {
        $apartment = $checkout->getApartment();
        if (!$apartment instanceof Apartment) {
            return [
                'consumableItems' => [],
                'consumableChecksByItemId' => [],
                'consumableStatuses' => ConsumableCheckStatus::cases(),
            ];
        }

        $checks = $entityManager->getRepository(ConsumableCheck::class)->findBy(['checkout' => $checkout]);
        $checksByItemId = [];
        foreach ($checks as $check) {
            if (!$check instanceof ConsumableCheck || !$check->getConsumableItem() instanceof ConsumableItem) {
                continue;
            }

            $itemId = $check->getConsumableItem()->getId();
            if ($itemId !== null) {
                $checksByItemId[$itemId] = $check;
            }
        }

        return [
            'consumableItems' => $this->findConsumableItems($apartment, $entityManager),
            'consumableChecksByItemId' => $checksByItemId,
            'consumableStatuses' => ConsumableCheckStatus::cases(),
        ];
    }

    private function saveCheckoutConsumableCheck(Checkout $checkout, ConsumableItem $item, Request $request, EntityManagerInterface $entityManager, User $actor): void
    {
        $apartment = $checkout->getApartment();
        if (!$apartment instanceof Apartment || !$item->getApartment() instanceof Apartment || $item->getApartment()->getId() !== $apartment->getId() || !$item->isActive()) {
            throw new \InvalidArgumentException('Ce consommable ne correspond pas à cet appartement.');
        }

        $status = ConsumableCheckStatus::tryFrom((string) $request->request->get('status'));
        if (!$status instanceof ConsumableCheckStatus) {
            throw new \InvalidArgumentException('Sélectionne un statut de stock valide.');
        }

        $check = $entityManager->getRepository(ConsumableCheck::class)->findOneBy([
            'checkout' => $checkout,
            'consumableItem' => $item,
        ]);

        if (!$check instanceof ConsumableCheck) {
            $check = (new ConsumableCheck())
                ->setCheckout($checkout)
                ->setConsumableItem($item)
                ->setApartment($apartment);
            $entityManager->persist($check);
        }

        $check
            ->setStatus($status)
            ->setNote($request->request->get('note') !== null ? (string) $request->request->get('note') : null)
            ->setCheckedBy($actor)
            ->setCheckedAt(new \DateTimeImmutable());
    }

    private function hasCheckoutConsumables(Checkout $checkout, EntityManagerInterface $entityManager): bool
    {
        $apartment = $checkout->getApartment();
        if (!$apartment instanceof Apartment) {
            return false;
        }

        return count($this->findConsumableItems($apartment, $entityManager)) > 0;
    }

    private function denyConsumableInventoryUnlessCheckoutCompleted(Checkout $checkout): void
    {
        if ($checkout->getStatus() !== CheckoutStatus::Completed) {
            throw $this->createAccessDeniedException('L’inventaire des consommables est disponible après la clôture du check-out.');
        }
    }

    /**
     * @return array{started:bool, consumableItems:list<ConsumableItem>, consumableChecksByItemId:array<int, ConsumableCheck>, completedConsumableCount:int, currentConsumable:?ConsumableItem, currentConsumableCheck:?ConsumableCheck, isFinished:bool}
     */
    private function buildConsumableInventoryData(Checkout $checkout, EntityManagerInterface $entityManager, Request $request): array
    {
        $apartment = $checkout->getApartment();
        $items = $apartment instanceof Apartment ? $this->findConsumableItems($apartment, $entityManager) : [];
        $checksByItemId = $this->buildConsumableChecksByItemId($checkout, $entityManager);
        $started = (bool) $request->query->get('start', false);
        $requestedItemId = max(0, (int) $request->query->get('item', 0));
        $currentItem = null;
        $completedCount = 0;

        foreach ($items as $item) {
            $itemId = $item->getId();
            $check = $itemId !== null && isset($checksByItemId[$itemId]) ? $checksByItemId[$itemId] : null;
            if ($check instanceof ConsumableCheck && $check->getQuantity() !== null) {
                ++$completedCount;
            }

            if ($started && $requestedItemId > 0 && $itemId === $requestedItemId) {
                $currentItem = $item;
            }
        }

        if ($started && !$currentItem instanceof ConsumableItem) {
            foreach ($items as $item) {
                $itemId = $item->getId();
                $check = $itemId !== null && isset($checksByItemId[$itemId]) ? $checksByItemId[$itemId] : null;
                if (!$check instanceof ConsumableCheck || $check->getQuantity() === null) {
                    $currentItem = $item;
                    break;
                }
            }
        }

        $currentCheck = $currentItem instanceof ConsumableItem && $currentItem->getId() !== null && isset($checksByItemId[$currentItem->getId()])
            ? $checksByItemId[$currentItem->getId()]
            : null;

        return [
            'started' => $started,
            'consumableItems' => $items,
            'consumableChecksByItemId' => $checksByItemId,
            'completedConsumableCount' => $completedCount,
            'currentConsumable' => $currentItem,
            'currentConsumableCheck' => $currentCheck,
            'isFinished' => $started && count($items) > 0 && !$currentItem instanceof ConsumableItem,
        ];
    }

    /**
     * @return array<int, ConsumableCheck>
     */
    private function buildConsumableChecksByItemId(Checkout $checkout, EntityManagerInterface $entityManager): array
    {
        $checksByItemId = [];
        foreach ($entityManager->getRepository(ConsumableCheck::class)->findBy(['checkout' => $checkout]) as $check) {
            if (!$check instanceof ConsumableCheck || !$check->getConsumableItem() instanceof ConsumableItem) {
                continue;
            }

            $itemId = $check->getConsumableItem()->getId();
            if ($itemId !== null) {
                $checksByItemId[$itemId] = $check;
            }
        }

        return $checksByItemId;
    }

    private function saveConsumableInventoryQuantity(Checkout $checkout, ConsumableItem $item, Request $request, EntityManagerInterface $entityManager, User $actor): void
    {
        $apartment = $checkout->getApartment();
        if (!$apartment instanceof Apartment || !$item->getApartment() instanceof Apartment || $item->getApartment()->getId() !== $apartment->getId() || !$item->isActive()) {
            throw new \InvalidArgumentException('Ce consommable ne correspond pas à cet appartement.');
        }

        $rawQuantity = trim((string) $request->request->get('quantity'));
        if ($rawQuantity === '' || !ctype_digit($rawQuantity)) {
            throw new \InvalidArgumentException('Renseigne une quantité actuelle valide.');
        }

        $quantity = (int) $rawQuantity;
        $status = ConsumableCheckStatus::Ok;
        if ($quantity <= 0) {
            $status = ConsumableCheckStatus::Missing;
        } elseif ($item->getMinimumQuantity() > 0 && $quantity < $item->getMinimumQuantity()) {
            $status = ConsumableCheckStatus::Low;
        }

        $check = $entityManager->getRepository(ConsumableCheck::class)->findOneBy([
            'checkout' => $checkout,
            'consumableItem' => $item,
        ]);

        if (!$check instanceof ConsumableCheck) {
            $check = (new ConsumableCheck())
                ->setCheckout($checkout)
                ->setConsumableItem($item)
                ->setApartment($apartment);
            $entityManager->persist($check);
        }

        $item->setCurrentQuantity($quantity);
        $check
            ->setQuantity($quantity)
            ->setStatus($status)
            ->setNote($request->request->get('note') !== null ? (string) $request->request->get('note') : null)
            ->setCheckedBy($actor)
            ->setCheckedAt(new \DateTimeImmutable());

        $this->closePreviousConsumableRestockAlerts($item, $check, $entityManager, $actor);
    }

    private function closePreviousConsumableRestockAlerts(ConsumableItem $item, ConsumableCheck $currentCheck, EntityManagerInterface $entityManager, User $actor): void
    {
        $openAlerts = $entityManager->createQueryBuilder()
            ->select('consumableCheck')
            ->from(ConsumableCheck::class, 'consumableCheck')
            ->where('consumableCheck.consumableItem = :item')
            ->andWhere('consumableCheck.status IN (:statuses)')
            ->andWhere('consumableCheck.restockedAt IS NULL')
            ->setParameter('item', $item)
            ->setParameter('statuses', [ConsumableCheckStatus::Low, ConsumableCheckStatus::Missing])
            ->getQuery()
            ->getResult();

        foreach ($openAlerts as $alert) {
            if (!$alert instanceof ConsumableCheck || ($currentCheck->getId() !== null && $alert->getId() === $currentCheck->getId())) {
                continue;
            }

            $alert->markRestocked($actor);
        }
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

    private function hasCheckoutHistory(Apartment $apartment, EntityManagerInterface $entityManager): bool
    {
        return $entityManager->createQueryBuilder()
            ->select('COUNT(checkout.id)')
            ->from(Checkout::class, 'checkout')
            ->where('checkout.apartment = :apartment')
            ->andWhere('checkout.status NOT IN (:excludedStatuses)')
            ->setParameter('apartment', $apartment)
            ->setParameter('excludedStatuses', [CheckoutStatus::Cancelled, CheckoutStatus::Completed])
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
                'html' => $this->renderView('employee/_room_completion.html.twig', [
                    'roomName' => $group['room']->getName(),
                ]),
                'redirect' => $this->generateUrl('admin_checkout_show', ['id' => $checkout->getId()]),
                'redirectDelayMs' => 1900,
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
        $today = new \DateTimeImmutable('today');
        $arrivalReservations = $this->findUpcomingReservations($entityManager, null, 20);
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
            'ongoingCheckouts' => $entityManager->createQueryBuilder()
                ->select('checkout', 'apartment', 'assignedTo')
                ->from(Checkout::class, 'checkout')
                ->join('checkout.apartment', 'apartment')
                ->leftJoin('checkout.assignedTo', 'assignedTo')
                ->where('checkout.status IN (:statuses)')
                ->setParameter('statuses', [
                    CheckoutStatus::InProgress,
                    CheckoutStatus::Paused,
                    CheckoutStatus::PendingValidation,
                    CheckoutStatus::Blocked,
                ])
                ->orderBy('checkout.startedAt', 'DESC')
                ->addOrderBy('checkout.pausedAt', 'DESC')
                ->addOrderBy('checkout.scheduledAt', 'ASC')
                ->setMaxResults(8)
                ->getQuery()
                ->getResult(),
            'finishedCheckouts' => $checkoutRepository->findBy(['status' => CheckoutStatus::Completed], ['completedAt' => 'DESC'], 8),
            'arrivalReservations' => $arrivalReservations,
            'todayArrivalReservations' => array_values(array_filter(
                $arrivalReservations,
                static fn (ApartmentReservation $reservation): bool => $reservation->isArrivalToday($today)
            )),
            'upcomingArrivalReservations' => array_values(array_filter(
                $arrivalReservations,
                static fn (ApartmentReservation $reservation): bool => $reservation->isArrivalInFuture($today)
            )),
            'todayDate' => $today,
            'pendingServiceOffers' => $pendingServiceOffers,
            'consumableRestockAlerts' => $this->buildConsumableRestockAlertRows($entityManager, null, 8),
            'manualCount' => $entityManager->getRepository(ApartmentManual::class)->count([]),
            'employeeCount' => count(array_filter(
                $entityManager->getRepository(User::class)->findBy([], ['fullName' => 'ASC']),
                static fn (User $user): bool => !in_array('ROLE_ADMIN', $user->getRoles(), true)
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildArrivalsPageData(EntityManagerInterface $entityManager): array
    {
        $today = new \DateTimeImmutable('today');
        $reservations = $this->findUpcomingReservations($entityManager);
        $apartments = $entityManager->getRepository(Apartment::class)->findBy(
            ['status' => ApartmentStatus::Active],
            ['name' => 'ASC']
        );
        $reservationRangesByApartment = [];

        foreach ($apartments as $apartment) {
            if ($apartment instanceof Apartment && $apartment->getId() !== null) {
                $reservationRangesByApartment[$apartment->getId()] = [];
            }
        }

        if ($apartments !== []) {
            $activeReservations = $entityManager->createQueryBuilder()
                ->select('reservation', 'apartment', 'checkin')
                ->from(ApartmentReservation::class, 'reservation')
                ->join('reservation.apartment', 'apartment')
                ->leftJoin('reservation.checkin', 'checkin')
                ->where('apartment IN (:apartments)')
                ->andWhere('reservation.departureDate >= :today')
                ->andWhere('checkin.id IS NULL')
                ->setParameter('apartments', $apartments)
                ->setParameter('today', $today, 'date_immutable')
                ->orderBy('reservation.arrivalDate', 'ASC')
                ->addOrderBy('reservation.id', 'DESC')
                ->getQuery()
                ->getResult();

            foreach ($activeReservations as $reservation) {
                if (!$reservation instanceof ApartmentReservation) {
                    continue;
                }

                $apartment = $reservation->getApartment();
                $apartmentId = $apartment?->getId();

                if (
                    $apartmentId === null
                    || !$reservation->getArrivalDate() instanceof \DateTimeImmutable
                    || !$reservation->getDepartureDate() instanceof \DateTimeImmutable
                ) {
                    continue;
                }

                $reservationRangesByApartment[$apartmentId][] = [
                    'arrivalDate' => $reservation->getArrivalDate()->format('Y-m-d'),
                    'departureDate' => $reservation->getDepartureDate()->format('Y-m-d'),
                    'guestName' => $reservation->getGuestName(),
                ];
            }
        }

        return [
            'reservations' => $reservations,
            'apartments' => $apartments,
            'reservationRangesByApartment' => $reservationRangesByApartment,
            'todayDate' => $today,
            'todayCount' => count(array_filter(
                $reservations,
                static fn (ApartmentReservation $reservation): bool => $reservation->isArrivalToday($today)
            )),
            'futureCount' => count(array_filter(
                $reservations,
                static fn (ApartmentReservation $reservation): bool => $reservation->isArrivalInFuture($today)
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

        $latestAirbnbChecks = $this->findLatestAirbnbChecksByApartment($apartments, $entityManager);

        return [
            'apartments' => $apartments,
            'employees' => $entityManager->getRepository(User::class)->findBy([], ['fullName' => 'ASC']),
            'apartmentStatuses' => ApartmentStatus::cases(),
            'apartmentTemplates' => $entityManager->getRepository(Apartment::class)->findBy([], ['name' => 'ASC']),
            'openAnomalyCounts' => $openAnomalyCounts,
            'airbnbCheckBadges' => $this->buildAirbnbCheckBadges($latestAirbnbChecks),
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
     * @return array<string, mixed>
     */
    private function buildManualsPageData(EntityManagerInterface $entityManager, Request $request): array
    {
        $selectedApartmentId = max(0, (int) $request->get('apartmentFilter', 0));
        $selectedEquipment = trim((string) $request->get('equipmentFilter', ''));

        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('manual', 'apartment')
            ->from(ApartmentManual::class, 'manual')
            ->join('manual.apartment', 'apartment')
            ->orderBy('manual.displayOrder', 'ASC')
            ->addOrderBy('manual.createdAt', 'DESC');

        if ($selectedApartmentId > 0) {
            $queryBuilder
                ->andWhere('IDENTITY(manual.apartment) = :apartmentId')
                ->setParameter('apartmentId', $selectedApartmentId);
        }

        if ($selectedEquipment !== '') {
            $queryBuilder
                ->andWhere('LOWER(manual.equipmentLabel) = :equipmentLabel')
                ->setParameter('equipmentLabel', mb_strtolower($selectedEquipment));
        }

        $manuals = $queryBuilder->getQuery()->getResult();

        $equipmentRows = $entityManager->createQueryBuilder()
            ->select('DISTINCT manual.equipmentLabel AS equipmentLabel')
            ->from(ApartmentManual::class, 'manual')
            ->where('manual.equipmentLabel != :emptyValue')
            ->setParameter('emptyValue', '')
            ->orderBy('manual.equipmentLabel', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return [
            'manuals' => $manuals,
            'apartments' => $entityManager->getRepository(Apartment::class)->findBy(['status' => ApartmentStatus::Active], ['name' => 'ASC']),
            'equipmentOptions' => array_values(array_filter(array_map(
                static fn (array $row): string => (string) ($row['equipmentLabel'] ?? ''),
                $equipmentRows
            ))),
            'selectedApartmentId' => $selectedApartmentId,
            'selectedEquipment' => $selectedEquipment,
            'manualEquipmentSuggestions' => $this->buildManualEquipmentSuggestions($entityManager),
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
        $latestAirbnbChecks = $this->findLatestAirbnbChecksByApartment($apartments, $entityManager);
        $airbnbCheckBadges = $this->buildAirbnbCheckBadges($latestAirbnbChecks);

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

        $openAnomalyInsights = [];
        $openAnomalies = $apartments === [] ? [] : $entityManager->createQueryBuilder()
            ->select('anomaly', 'room', 'roomEquipment', 'createdBy')
            ->from(Anomaly::class, 'anomaly')
            ->leftJoin('anomaly.room', 'room')
            ->leftJoin('anomaly.roomEquipment', 'roomEquipment')
            ->leftJoin('anomaly.createdBy', 'createdBy')
            ->where('anomaly.status != :closedStatus')
            ->andWhere('anomaly.apartment IN (:apartments)')
            ->orderBy('anomaly.createdAt', 'DESC')
            ->setParameter('closedStatus', AnomalyStatus::Closed)
            ->setParameter('apartments', $apartments)
            ->getQuery()
            ->getResult();

        foreach ($openAnomalies as $anomaly) {
            if (!$anomaly instanceof Anomaly) {
                continue;
            }

            $apartmentId = $anomaly->getApartment()?->getId();
            if (!$apartmentId) {
                continue;
            }

            if (!isset($openAnomalyInsights[$apartmentId])) {
                $openAnomalyInsights[$apartmentId] = [
                    'latestOpenAnomaly' => null,
                    'affectedRooms' => [],
                    'newOpenAnomalyCount' => 0,
                    'followedOpenAnomalyCount' => 0,
                    'priorityOpenAnomalyCount' => 0,
                ];
            }

            if (!$openAnomalyInsights[$apartmentId]['latestOpenAnomaly'] instanceof Anomaly) {
                $openAnomalyInsights[$apartmentId]['latestOpenAnomaly'] = $anomaly;
            }

            $room = $anomaly->getRoom();
            if ($room instanceof Room) {
                $roomKey = (string) ($room->getId() ?? $room->getName());
                if (!isset($openAnomalyInsights[$apartmentId]['affectedRooms'][$roomKey])) {
                    $openAnomalyInsights[$apartmentId]['affectedRooms'][$roomKey] = [
                        'name' => $room->getName(),
                        'count' => 0,
                    ];
                }
                ++$openAnomalyInsights[$apartmentId]['affectedRooms'][$roomKey]['count'];
            }

            if ($anomaly->getStatus() === AnomalyStatus::New) {
                ++$openAnomalyInsights[$apartmentId]['newOpenAnomalyCount'];
            } else {
                ++$openAnomalyInsights[$apartmentId]['followedOpenAnomalyCount'];
            }

            if (in_array($anomaly->getType()->value, ['major', 'missing', 'replacement_needed'], true)) {
                ++$openAnomalyInsights[$apartmentId]['priorityOpenAnomalyCount'];
            }
        }

        $cards = [];
        foreach ($apartments as $apartment) {
            $openCheckout = $this->getOpenCheckout($apartment, $entityManager);
            $assignedEmployees = array_values($apartment->getAssignedEmployees()->toArray());
            $employeeNames = array_map(static fn (User $user) => $user->getFullName(), $assignedEmployees);
            $openAnomalyCount = $openAnomalyCounts[$apartment->getId() ?? 0] ?? 0;
            $insights = $openAnomalyInsights[$apartment->getId() ?? 0] ?? [
                'latestOpenAnomaly' => null,
                'affectedRooms' => [],
                'newOpenAnomalyCount' => 0,
                'followedOpenAnomalyCount' => 0,
                'priorityOpenAnomalyCount' => 0,
            ];
            $affectedRooms = array_values($insights['affectedRooms']);
            usort($affectedRooms, static fn (array $left, array $right): int => $right['count'] <=> $left['count'] ?: strcmp($left['name'], $right['name']));

            $cards[] = [
                'apartment' => $apartment,
                'openCheckout' => $openCheckout,
                'anomalyCount' => $entityManager->getRepository(Anomaly::class)->count(['apartment' => $apartment]),
                'openAnomalyCount' => $openAnomalyCount,
                'latestOpenAnomaly' => $insights['latestOpenAnomaly'],
                'affectedRooms' => $affectedRooms,
                'newOpenAnomalyCount' => $insights['newOpenAnomalyCount'],
                'followedOpenAnomalyCount' => $insights['followedOpenAnomalyCount'],
                'priorityOpenAnomalyCount' => $insights['priorityOpenAnomalyCount'],
                'assignedEmployeeNames' => $employeeNames !== [] ? implode(', ', $employeeNames) : 'Aucun employé assigné',
                'assignedEmployees' => $assignedEmployees,
                'firstAssignedEmployeeId' => count($assignedEmployees) === 1 ? $assignedEmployees[0]->getId() : null,
                'canQuickLaunch' => $openCheckout === null && count($assignedEmployees) === 1,
                'canLaunchCheckout' => $openCheckout === null,
                'airbnbCheck' => $latestAirbnbChecks[$apartment->getId() ?? 0] ?? null,
                'airbnbCheckBadge' => $airbnbCheckBadges[$apartment->getId() ?? 0] ?? [
                    'label' => 'Non audité',
                    'class' => 'is-neutral',
                    'score' => null,
                ],
            ];
        }

        return $cards;
    }

    /**
     * @param list<Apartment> $apartments
     * @return array<int, AirbnbCheck>
     */
    private function findLatestAirbnbChecksByApartment(array $apartments, EntityManagerInterface $entityManager): array
    {
        if ($apartments === []) {
            return [];
        }

        $checks = $entityManager->createQueryBuilder()
            ->select('airbnbCheck', 'apartment')
            ->from(AirbnbCheck::class, 'airbnbCheck')
            ->join('airbnbCheck.apartment', 'apartment')
            ->where('airbnbCheck.apartment IN (:apartments)')
            ->andWhere('airbnbCheck.status = :status')
            ->setParameter('apartments', $apartments)
            ->setParameter('status', AirbnbCheck::STATUS_COMPLETED)
            ->orderBy('airbnbCheck.completedAt', 'DESC')
            ->addOrderBy('airbnbCheck.id', 'DESC')
            ->getQuery()
            ->getResult();

        $latestChecks = [];
        foreach ($checks as $check) {
            if (!$check instanceof AirbnbCheck) {
                continue;
            }

            $apartmentId = $check->getApartment()?->getId();
            if ($apartmentId !== null && !isset($latestChecks[$apartmentId])) {
                $latestChecks[$apartmentId] = $check;
            }
        }

        return $latestChecks;
    }

    /**
     * @param array<int, AirbnbCheck> $latestChecks
     * @return array<int, array{label:string,class:string,score:?int}>
     */
    private function buildAirbnbCheckBadges(array $latestChecks): array
    {
        $badges = [];
        foreach ($latestChecks as $apartmentId => $check) {
            $badges[(int) $apartmentId] = [
                'label' => $check->getBadgeLabel(),
                'class' => $check->getBadgeClass(),
                'score' => $check->getScore(),
            ];
        }

        return $badges;
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

    private function normalizeMoroccanPhoneNumber(mixed $value): ?string
    {
        $phoneNumber = $this->nullable($value);
        if ($phoneNumber === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00212')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '212')) {
            $digits = substr($digits, 3);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (preg_match('/^[5-7]\d{8}$/', $digits) !== 1) {
            throw new \InvalidArgumentException('Le numéro doit être saisi au format 0665854858 et sera enregistré en +212.');
        }

        return '+212' . $digits;
    }

    private function normalizeServiceOfferLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');

        return mb_substr($label, 0, 160);
    }

    private function normalizeManualText(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return mb_substr($value, 0, 160);
    }

    private function normalizeNullableManualText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function isApartmentRichTextField(string $field): bool
    {
        return in_array($field, self::APARTMENT_RICH_TEXT_FIELDS, true);
    }

    private function sanitizeRichText(mixed $value, bool $allowNull = false): ?string
    {
        if ($value === null) {
            return $allowNull ? null : '';
        }

        $html = trim((string) $value);
        if ($html === '') {
            return $allowNull ? null : '';
        }

        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = preg_replace('/<\s*div[^>]*>/i', '<p>', $html) ?? $html;
        $html = preg_replace('/<\s*\/\s*div\s*>/i', '</p>', $html) ?? $html;
        $html = preg_replace('/<\s*span[^>]*>/i', '', $html) ?? $html;
        $html = preg_replace('/<\s*\/\s*span\s*>/i', '', $html) ?? $html;
        $html = preg_replace('/\n{2,}/', "\n", $html) ?? $html;
        $html = preg_replace('/\n/', '<br>', $html) ?? $html;
        $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li>');
        $html = preg_replace('/<(\/?)([a-z0-9]+)(?:\s[^>]*)?>/i', '<$1$2>', $html) ?? $html;
        $html = str_ireplace(['<b>', '</b>', '<i>', '</i>'], ['<strong>', '</strong>', '<em>', '</em>'], $html);
        $html = preg_replace('/(<br>\s*){3,}/i', '<br><br>', $html) ?? $html;
        $html = preg_replace('/<p>\s*<\/p>/i', '', $html) ?? $html;
        $html = trim($html);

        return $html === '' && $allowNull ? null : $html;
    }

    private function duplicateApartmentTemplate(Apartment $templateApartment, Apartment $apartment): void
    {
        $apartment
            ->setAddressLine1($templateApartment->getAddressLine1())
            ->setAddressLine2($templateApartment->getAddressLine2())
            ->setCity($templateApartment->getCity())
            ->setPostalCode($templateApartment->getPostalCode())
            ->setFloor($templateApartment->getFloor())
            ->setDoorNumber($templateApartment->getDoorNumber())
            ->setMailboxNumber($templateApartment->getMailboxNumber())
            ->setGoogleMapsLink($templateApartment->getGoogleMapsLink())
            ->setBuildingAccessCode($templateApartment->getBuildingAccessCode())
            ->setKeyBoxCode($templateApartment->getKeyBoxCode())
            ->setEntryInstructions($templateApartment->getEntryInstructions())
            ->setConditionStatus($templateApartment->getConditionStatus())
            ->setBedroomCount($templateApartment->getBedroomCount())
            ->setSleepsCount($templateApartment->getSleepsCount())
            ->setOwnerName($templateApartment->getOwnerName())
            ->setOwnerPhone($templateApartment->getOwnerPhone())
            ->setOwnerEmail($templateApartment->getOwnerEmail())
            ->setInternalNotes($templateApartment->getInternalNotes())
            ->setGuestWifiName($templateApartment->getGuestWifiName())
            ->setGuestWifiPassword($templateApartment->getGuestWifiPassword())
            ->setGuestWifiInstructions($templateApartment->getGuestWifiInstructions())
            ->setGuestHouseRules($templateApartment->getGuestHouseRules())
            ->setGuestDepartureInstructions($templateApartment->getGuestDepartureInstructions())
            ->setGuestEmergencyInfo($templateApartment->getGuestEmergencyInfo())
            ->setGuestEquipmentInfo($templateApartment->getGuestEquipmentInfo())
            ->setGeneralPhotos($templateApartment->getGeneralPhotos())
            ->setImagePath($templateApartment->getImagePath())
            ->setStatus(ApartmentStatus::Active)
            ->setIsInventoryPriority($templateApartment->isInventoryPriority())
            ->setInventoryDueAt($templateApartment->getInventoryDueAt());

        foreach ($templateApartment->getAssignedEmployees() as $employee) {
            $apartment->addAssignedEmployee($employee);
        }

        foreach ($templateApartment->getOrderedAccessSteps() as $existingStep) {
            $step = (new ApartmentAccessStep())
                ->setInstruction($existingStep->getInstruction())
                ->setImagePath($existingStep->getImagePath())
                ->setDisplayOrder($existingStep->getDisplayOrder());
            $apartment->addAccessStep($step);
        }

        foreach ($templateApartment->getActiveRooms() as $existingRoom) {
            $room = (new Room())
                ->setType($existingRoom->getType())
                ->setName($existingRoom->getName())
                ->setDisplayOrder($existingRoom->getDisplayOrder())
                ->setNotes($existingRoom->getNotes());
            $apartment->addRoom($room);

            foreach ($existingRoom->getActiveRoomEquipments() as $existingEquipment) {
                $equipment = (new RoomEquipment())
                    ->setCatalogEquipment($existingEquipment->getCatalogEquipment())
                    ->setLabel($existingEquipment->getLabel())
                    ->setDisplayOrder($existingEquipment->getDisplayOrder())
                    ->setQuantity($existingEquipment->getQuantity())
                    ->setNotes($existingEquipment->getNotes())
                    ->setIsActive($existingEquipment->isActive());
                $room->addRoomEquipment($equipment);
            }
        }
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
            'entryInstructions' => $apartment->setEntryInstructions($value === '' ? 'Aucune consigne pour le moment.' : $this->sanitizeRichText($value)),
            'ownerName' => $apartment->setOwnerName($normalizedValue),
            'ownerPhone' => $apartment->setOwnerPhone($normalizedValue),
            'ownerEmail' => $apartment->setOwnerEmail($normalizedValue),
            'internalNotes' => $apartment->setInternalNotes($this->sanitizeRichText($value, true)),
            'guestWifiName' => $apartment->setGuestWifiName($normalizedValue),
            'guestWifiPassword' => $apartment->setGuestWifiPassword($normalizedValue),
            'guestWifiInstructions' => $apartment->setGuestWifiInstructions($this->sanitizeRichText($value, true)),
            'guestHouseRules' => $apartment->setGuestHouseRules($this->sanitizeRichText($value, true)),
            'guestDepartureInstructions' => $apartment->setGuestDepartureInstructions($this->sanitizeRichText($value, true)),
            'guestEmergencyInfo' => $apartment->setGuestEmergencyInfo($this->sanitizeRichText($value, true)),
            'guestEquipmentInfo' => $apartment->setGuestEquipmentInfo($this->sanitizeRichText($value, true)),
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
            'phoneNumber' => $user->setPhoneNumber($this->normalizeMoroccanPhoneNumber($normalizedValue)),
            'password' => $this->updateAdminUserPassword($user, $value, $passwordHasher),
            'isActive' => $user->setIsActive(in_array(strtolower($value), ['1', 'true', 'oui', 'actif'], true)),
            'canManageAnomalyWorkflow' => $user->setCanManageAnomalyWorkflow(in_array(strtolower($value), ['1', 'true', 'oui', 'autorise'], true)),
            default => throw new \InvalidArgumentException('Champ employé non modifiable.'),
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
            throw new \InvalidArgumentException('Cet e-mail existe déjà.');
        }

        $user->setEmail($email);
    }

    private function updateAdminUserPassword(User $user, string $value, UserPasswordHasherInterface $passwordHasher): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Le mot de passe ne peut pas être vide.');
        }

        $user->setPassword($passwordHasher->hashPassword($user, $value));
    }

    private function buildWazeLink(string $addressLine1, string $city, string $postalCode = ''): string
    {
        $parts = array_filter([$addressLine1, $postalCode, $city], static fn (string $part): bool => trim($part) !== '');
        $query = rawurlencode(implode(' ', $parts));

        return 'https://www.waze.com/ul?q=' . $query;
    }

    private function manualsContentResponse(EntityManagerInterface $entityManager, Request $request, string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'html' => $this->renderView('admin/_manuals_content.html.twig', $this->buildManualsPageData($entityManager, $request)),
            'message' => $message,
        ]);
    }

    /**
     * @return list<string>
     */
    private function buildManualEquipmentSuggestions(EntityManagerInterface $entityManager): array
    {
        $manualRows = $entityManager->createQueryBuilder()
            ->select('DISTINCT manual.equipmentLabel AS equipmentLabel')
            ->from(ApartmentManual::class, 'manual')
            ->where('manual.equipmentLabel != :emptyValue')
            ->setParameter('emptyValue', '')
            ->getQuery()
            ->getArrayResult();

        $roomEquipmentRows = $entityManager->createQueryBuilder()
            ->select('DISTINCT roomEquipment.label AS equipmentLabel')
            ->from(RoomEquipment::class, 'roomEquipment')
            ->where('roomEquipment.isActive = :isActive')
            ->setParameter('isActive', true)
            ->getQuery()
            ->getArrayResult();

        $labels = array_merge(
            array_map(static fn (array $row): string => (string) ($row['equipmentLabel'] ?? ''), $manualRows),
            array_map(static fn (array $row): string => (string) ($row['equipmentLabel'] ?? ''), $roomEquipmentRows)
        );

        $labels = array_values(array_unique(array_filter(array_map(
            fn (string $label): string => $this->normalizeManualText($label),
            $labels
        ))));
        natcasesort($labels);

        return array_values($labels);
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

    private function storeApartmentImage(UploadedFile $image): string
    {
        $targetDir = $this->getParameter('kernel.project_dir') . '/public/uploads/apartments';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $safeName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $safeName) ?: 'apartment';
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(4)), $image->guessExtension() ?: 'jpg');

        $image->move($targetDir, $filename);

        return '/uploads/apartments/' . $filename;
    }

    private function storeApartmentManualVideo(UploadedFile $video): string
    {
        $targetDir = $this->getParameter('kernel.project_dir') . '/public/uploads/manuals';
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Le dossier des manuels vidéo est introuvable ou non accessible en écriture.');
        }

        if (!is_writable($targetDir)) {
            throw new \RuntimeException('Le dossier des manuels vidéo n’est pas accessible en écriture sur le serveur.');
        }

        $safeName = pathinfo($video->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $safeName) ?: 'manual';
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(4)), $video->guessExtension() ?: 'mp4');

        try {
            $video->move($targetDir, $filename);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Impossible d’enregistrer la vidéo sur le serveur. Vérifie les droits du dossier uploads/manuals.');
        }

        return '/uploads/manuals/' . $filename;
    }

    private function buildManualUploadErrorMessage(Request $request): string
    {
        $contentLength = (int) ($request->server->get('CONTENT_LENGTH') ?? 0);
        $postMaxBytes = $this->convertIniSizeToBytes((string) ini_get('post_max_size'));

        if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
            return sprintf(
                'La vidéo est trop lourde pour la configuration actuelle du serveur. Limites actuelles : upload_max_filesize=%s, post_max_size=%s.',
                (string) ini_get('upload_max_filesize'),
                (string) ini_get('post_max_size'),
            );
        }

        return sprintf(
            'Aucune vidéo valide n’a été reçue. Vérifie le format MP4, MOV ou WebM et la taille du fichier. Limite actuelle d’upload : %s.',
            (string) ini_get('upload_max_filesize')
        );
    }

    private function isRequestBodyTooLarge(Request $request): bool
    {
        $contentLength = (int) ($request->server->get('CONTENT_LENGTH') ?? 0);
        $postMaxBytes = $this->convertIniSizeToBytes((string) ini_get('post_max_size'));

        return $contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes;
    }

    private function convertIniSizeToBytes(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 0;
        }

        $unit = strtolower(substr($trimmed, -1));
        $bytes = (int) $trimmed;

        return match ($unit) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => (int) $trimmed,
        };
    }

    private function assertAcceptedImageUpload(UploadedFile $file, int $maxBytes, string $label): void
    {
        $this->assertValidUploadedFile($file, $label);

        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            throw new \InvalidArgumentException(sprintf('%s dépasse la taille autorisée.', $label));
        }

        $mimeType = (string) ($file->getMimeType() ?? '');
        if (!str_starts_with($mimeType, 'image/')) {
            throw new \InvalidArgumentException(sprintf('%s doit être une image valide.', $label));
        }
    }

    private function assertAcceptedVideoUpload(UploadedFile $file, int $maxBytes, string $label): void
    {
        $this->assertValidUploadedFile($file, $label);

        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            throw new \InvalidArgumentException(sprintf('%s dépasse la taille autorisée.', $label));
        }

        $mimeType = (string) ($file->getMimeType() ?? '');
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedMimeTypes = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v', 'application/octet-stream'];
        $allowedExtensions = ['mp4', 'mov', 'webm', 'm4v'];

        if (!in_array($mimeType, $allowedMimeTypes, true) && !in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException(sprintf('%s doit être une vidéo MP4, MOV ou WebM.', $label));
        }
    }

    private function assertValidUploadedFile(UploadedFile $file, string $label): void
    {
        if ($file->isValid()) {
            return;
        }

        throw new \InvalidArgumentException($this->buildUploadedFileErrorMessage($file, $label));
    }

    private function buildUploadedFileErrorMessage(UploadedFile $file, string $label): string
    {
        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE => sprintf(
                'Le fichier envoyé pour %s est trop lourd pour le serveur. Limites actuelles : upload_max_filesize=%s, post_max_size=%s.',
                strtolower($label),
                (string) ini_get('upload_max_filesize'),
                (string) ini_get('post_max_size'),
            ),
            UPLOAD_ERR_FORM_SIZE => sprintf('%s dépasse la taille autorisée par le formulaire.', $label),
            UPLOAD_ERR_PARTIAL => sprintf('%s n’a été envoyé que partiellement. Réessaie avec une connexion stable.', $label),
            UPLOAD_ERR_NO_FILE => sprintf('Aucun fichier n’a été reçu pour %s.', strtolower($label)),
            UPLOAD_ERR_NO_TMP_DIR => 'Le dossier temporaire d’upload est manquant sur le serveur.',
            UPLOAD_ERR_CANT_WRITE => 'Le serveur n’arrive pas à enregistrer le fichier temporaire.',
            UPLOAD_ERR_EXTENSION => 'Une extension PHP a bloqué l’upload du fichier.',
            default => sprintf('%s n’a pas pu être envoyé. Réessaie avec un fichier valide.', $label),
        };
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

    private function deleteApartmentImage(?string $imagePath): void
    {
        if (!is_string($imagePath) || $imagePath === '' || !str_starts_with($imagePath, '/uploads/apartments/')) {
            return;
        }

        $fullPath = $this->getParameter('kernel.project_dir') . '/public' . $imagePath;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    private function deleteApartmentManualVideo(?string $videoPath): void
    {
        if (!is_string($videoPath) || $videoPath === '' || !str_starts_with($videoPath, '/uploads/manuals/')) {
            return;
        }

        $fullPath = $this->getParameter('kernel.project_dir') . '/public' . $videoPath;
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

    private function getAppearanceSettings(EntityManagerInterface $entityManager): AppAppearanceSettings
    {
        try {
            $settings = $entityManager
                ->getRepository(AppAppearanceSettings::class)
                ->findOneBy([], ['id' => 'ASC']);
        } catch (\Throwable) {
            return AppAppearanceSettings::default();
        }

        if ($settings instanceof AppAppearanceSettings) {
            return $settings;
        }

        $settings = AppAppearanceSettings::default();
        $entityManager->persist($settings);

        return $settings;
    }

    private function extractAppearanceColor(Request $request, string $field, string $label): string
    {
        $value = trim((string) $request->request->get($field));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('La couleur %s doit être au format hexadécimal, par exemple #ff385c.', $label));
        }

        return strtolower($value);
    }

    private function appearanceSettingsStorageReady(EntityManagerInterface $entityManager): bool
    {
        try {
            $schemaManager = $entityManager->getConnection()->createSchemaManager();
            if (!$schemaManager->tablesExist(['app_appearance_settings'])) {
                return false;
            }

            $table = $schemaManager->introspectTable('app_appearance_settings');
            foreach ([
                'primary_color',
                'secondary_color',
                'tertiary_color',
                'background_color',
                'surface_color',
                'text_color',
                'muted_color',
                'border_color',
                'success_color',
                'warning_color',
                'danger_color',
            ] as $columnName) {
                if (!$table->hasColumn($columnName)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
