<?php

namespace App\Controller;

use App\Entity\AirbnbCheck;
use App\Entity\AirbnbCheckEquipment;
use App\Entity\AirbnbCheckRoom;
use App\Entity\Apartment;
use App\Entity\User;
use App\Enum\ApartmentStatus;
use App\Service\AirbnbCheckManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/airbnb-check')]
class AirbnbCheckController extends AbstractController
{
    public function __construct(
        private readonly string $mailerFromEmail,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'admin_airbnb_check_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $apartments = $entityManager->getRepository(Apartment::class)->findBy(
            ['status' => ApartmentStatus::Active],
            ['name' => 'ASC']
        );

        return $this->render('airbnb_check/index.html.twig', [
            'apartments' => $apartments,
            'latestChecks' => $this->findLatestCompletedChecksByApartment($apartments, $entityManager),
            'reportCount' => $entityManager->getRepository(AirbnbCheck::class)->count(['status' => AirbnbCheck::STATUS_COMPLETED]),
        ]);
    }

    #[Route('/apartments/{id}/launch', name: 'admin_airbnb_check_launch', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function launch(Apartment $apartment, Request $request, AirbnbCheckManager $checkManager, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('admin_airbnb_check_launch_' . $apartment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $check = $checkManager->createCheck($apartment, $user);
        $entityManager->flush();

        return $this->redirectToRoute('admin_airbnb_check_show', ['id' => $check->getId()]);
    }

    #[Route('/reports', name: 'admin_airbnb_check_reports', methods: ['GET'])]
    public function reports(EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $reports = $entityManager->createQueryBuilder()
            ->select('audit', 'apartment', 'createdBy')
            ->from(AirbnbCheck::class, 'audit')
            ->join('audit.apartment', 'apartment')
            ->leftJoin('audit.createdBy', 'createdBy')
            ->where('audit.status = :status')
            ->setParameter('status', AirbnbCheck::STATUS_COMPLETED)
            ->orderBy('audit.completedAt', 'DESC')
            ->addOrderBy('audit.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('airbnb_check/reports.html.twig', [
            'reports' => $reports,
        ]);
    }

    #[Route('/reports/{id}', name: 'admin_airbnb_check_report_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function report(AirbnbCheck $check): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('airbnb_check/report_show.html.twig', [
            'check' => $check,
            'issueEquipments' => $this->getIssueEquipments($check),
        ]);
    }

    #[Route('/reports/{id}/send-email', name: 'admin_airbnb_check_report_send_email', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendReportEmail(AirbnbCheck $check, Request $request, MailerInterface $mailer, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('admin_airbnb_check_send_email_' . $check->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $apartment = $check->getApartment();
        $ownerEmail = $apartment?->getOwnerEmail();
        if (!$apartment instanceof Apartment || $ownerEmail === null || trim($ownerEmail) === '') {
            $this->addFlash('error', 'Adresse email du propriétaire manquante. Veuillez l’ajouter avant d’envoyer le rapport.');

            return $this->redirectToRoute('admin_airbnb_check_report_show', ['id' => $check->getId()]);
        }

        $email = (new Email())
            ->from(new Address($this->mailerFromEmail, 'Check-out'))
            ->to($ownerEmail)
            ->subject(sprintf('Rapport Airbnb - %s - %d%%', $apartment->getName(), $check->getScore()))
            ->html($this->renderView('airbnb_check/email_report.html.twig', [
                'check' => $check,
                'apartment' => $apartment,
                'issueEquipments' => $this->getIssueEquipments($check),
            ]));

        try {
            $mailer->send($email);
            $check->setReportSentAt(new \DateTimeImmutable());
            $entityManager->flush();
            $this->addFlash('success', 'Rapport envoyé au propriétaire.');
        } catch (\Throwable $exception) {
            $this->logger->error('Impossible d’envoyer le rapport Airbnb par email.', [
                'checkId' => $check->getId(),
                'apartmentId' => $apartment->getId(),
                'ownerEmail' => $ownerEmail,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->addFlash('error', 'Impossible d’envoyer l’email pour le moment. Vérifie la configuration mailer.');
        }

        return $this->redirectToRoute('admin_airbnb_check_report_show', ['id' => $check->getId()]);
    }

    #[Route('/{id}', name: 'admin_airbnb_check_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(AirbnbCheck $check, AirbnbCheckManager $checkManager, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $checkManager->recalculate($check);
        $entityManager->flush();

        return $this->render('airbnb_check/show.html.twig', [
            'check' => $check,
        ]);
    }

    #[Route('/{check}/rooms/{room}', name: 'admin_airbnb_check_room', requirements: ['check' => '\d+', 'room' => '\d+'], methods: ['GET'])]
    public function room(AirbnbCheck $check, AirbnbCheckRoom $room, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->assertRoomBelongsToCheck($room, $check);

        $filter = (string) $request->query->get('filter', 'all');
        $equipments = $this->buildRoomEquipments($room, $filter);
        $statusOptions = $this->statusOptions();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'html' => $this->renderView('airbnb_check/_room_content.html.twig', [
                    'check' => $check,
                    'room' => $room,
                    'equipments' => $equipments,
                    'filter' => $filter,
                    'statusOptions' => $statusOptions,
                ]),
            ]);
        }

        return $this->render('airbnb_check/room.html.twig', [
            'check' => $check,
            'room' => $room,
            'equipments' => $equipments,
            'filter' => $filter,
            'statusOptions' => $statusOptions,
        ]);
    }

    #[Route('/{check}/equipments/{equipment}', name: 'admin_airbnb_check_equipment', requirements: ['check' => '\d+', 'equipment' => '\d+'], methods: ['GET', 'POST'])]
    public function equipment(AirbnbCheck $check, AirbnbCheckEquipment $equipment, Request $request, AirbnbCheckManager $checkManager, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->assertEquipmentBelongsToCheck($equipment, $check);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_airbnb_check_equipment_' . $equipment->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            try {
                $photo = $request->files->get('photo');
                $checkManager->updateEquipment(
                    $equipment,
                    (string) $request->request->get('status'),
                    $request->request->get('note') !== null ? (string) $request->request->get('note') : null,
                    $request->request->get('taskLabel') !== null ? (string) $request->request->get('taskLabel') : null,
                    $photo instanceof UploadedFile ? $photo : null
                );
                $entityManager->flush();
                $message = 'Équipement mis à jour.';
            } catch (\InvalidArgumentException $exception) {
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
                }

                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('admin_airbnb_check_equipment', [
                    'check' => $check->getId(),
                    'equipment' => $equipment->getId(),
                ]);
            }

            if ($request->isXmlHttpRequest()) {
                $room = $equipment->getRoom();
                if (!$room instanceof AirbnbCheckRoom) {
                    return new JsonResponse(['success' => false, 'message' => 'Pièce introuvable.'], 422);
                }

                $filter = (string) $request->request->get('filter', 'all');

                return new JsonResponse([
                    'success' => true,
                    'message' => $message,
                    'html' => $this->renderView('airbnb_check/_room_content.html.twig', [
                        'check' => $check,
                        'room' => $room,
                        'equipments' => $this->buildRoomEquipments($room, $filter),
                        'filter' => $filter,
                        'statusOptions' => $this->statusOptions(),
                    ]),
                ]);
            }

            $this->addFlash('success', $message);

            return $this->redirectToRoute('admin_airbnb_check_room', [
                'check' => $check->getId(),
                'room' => $equipment->getRoom()?->getId(),
            ]);
        }

        return $this->render('airbnb_check/equipment.html.twig', [
            'check' => $check,
            'room' => $equipment->getRoom(),
            'equipment' => $equipment,
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    #[Route('/{check}/rooms/{room}/validate-pending', name: 'admin_airbnb_check_room_validate_pending', requirements: ['check' => '\d+', 'room' => '\d+'], methods: ['POST'])]
    public function validatePendingRoomEquipments(AirbnbCheck $check, AirbnbCheckRoom $room, Request $request, AirbnbCheckManager $checkManager, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->assertRoomBelongsToCheck($room, $check);

        if (!$this->isCsrfTokenValid('admin_airbnb_check_room_validate_pending_' . $room->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $updatedCount = $checkManager->validatePendingRoomEquipments($room);
        $entityManager->flush();

        $message = $updatedCount > 0
            ? sprintf('%d équipement%s validé%s en bon état.', $updatedCount, $updatedCount > 1 ? 's' : '', $updatedCount > 1 ? 's' : '')
            : 'Aucun équipement en attente dans cette pièce.';

        if ($request->isXmlHttpRequest()) {
            $filter = (string) $request->request->get('filter', 'all');

            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'html' => $this->renderView('airbnb_check/_room_content.html.twig', [
                    'check' => $check,
                    'room' => $room,
                    'equipments' => $this->buildRoomEquipments($room, $filter),
                    'filter' => $filter,
                    'statusOptions' => $this->statusOptions(),
                ]),
            ]);
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_airbnb_check_room', [
            'check' => $check->getId(),
            'room' => $room->getId(),
        ]);
    }

    #[Route('/{id}/complete', name: 'admin_airbnb_check_complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function complete(AirbnbCheck $check, Request $request, AirbnbCheckManager $checkManager, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('admin_airbnb_check_complete_' . $check->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $checkManager->complete($check);
        $entityManager->flush();

        $this->addFlash('success', 'Audit validé. Le rapport a été enregistré et les pièces/équipements présents ont été synchronisés.');

        return $this->redirectToRoute('admin_airbnb_check_report_show', ['id' => $check->getId()]);
    }

    /**
     * @param list<Apartment> $apartments
     * @return array<int, AirbnbCheck>
     */
    private function findLatestCompletedChecksByApartment(array $apartments, EntityManagerInterface $entityManager): array
    {
        if ($apartments === []) {
            return [];
        }

        $checks = $entityManager->createQueryBuilder()
            ->select('audit', 'apartment')
            ->from(AirbnbCheck::class, 'audit')
            ->join('audit.apartment', 'apartment')
            ->where('audit.apartment IN (:apartments)')
            ->andWhere('audit.status = :status')
            ->setParameter('apartments', $apartments)
            ->setParameter('status', AirbnbCheck::STATUS_COMPLETED)
            ->orderBy('audit.completedAt', 'DESC')
            ->addOrderBy('audit.id', 'DESC')
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
     * @return list<AirbnbCheckEquipment>
     */
    private function getIssueEquipments(AirbnbCheck $check): array
    {
        $issues = [];
        foreach ($check->getRooms() as $room) {
            foreach ($room->getEquipments() as $equipment) {
                if ($equipment->isIssue()) {
                    $issues[] = $equipment;
                }
            }
        }

        return $issues;
    }

    /**
     * @return list<AirbnbCheckEquipment>
     */
    private function buildRoomEquipments(AirbnbCheckRoom $room, string $filter): array
    {
        $equipments = $room->getEquipments()->toArray();
        $equipments = array_values(array_filter($equipments, static function (AirbnbCheckEquipment $equipment) use ($filter): bool {
            return match ($filter) {
                'valid' => $equipment->getStatus() === AirbnbCheckEquipment::STATUS_GOOD,
                'missing' => $equipment->getStatus() === AirbnbCheckEquipment::STATUS_MISSING,
                'average' => $equipment->getStatus() === AirbnbCheckEquipment::STATUS_AVERAGE,
                default => true,
            };
        }));

        usort($equipments, static function (AirbnbCheckEquipment $left, AirbnbCheckEquipment $right): int {
            $leftPending = $left->getStatus() === null ? 0 : 1;
            $rightPending = $right->getStatus() === null ? 0 : 1;

            return $leftPending <=> $rightPending
                ?: $left->getDisplayOrder() <=> $right->getDisplayOrder()
                ?: strcmp($left->getName(), $right->getName());
        });

        return $equipments;
    }

    /**
     * @return list<array{value:string,label:string,description:string,icon:string}>
     */
    private function statusOptions(): array
    {
        return [
            [
                'value' => AirbnbCheckEquipment::STATUS_GOOD,
                'label' => 'Présent et en bon état',
                'description' => 'L’équipement est fonctionnel et en bon état.',
                'icon' => 'check-lg',
            ],
            [
                'value' => AirbnbCheckEquipment::STATUS_AVERAGE,
                'label' => 'Présent mais état moyen',
                'description' => 'L’équipement fonctionne mais présente des défauts.',
                'icon' => 'dash-lg',
            ],
            [
                'value' => AirbnbCheckEquipment::STATUS_MISSING,
                'label' => 'Absent',
                'description' => 'L’équipement n’est pas disponible dans le logement.',
                'icon' => 'x-lg',
            ],
            [
                'value' => AirbnbCheckEquipment::STATUS_NOT_APPLICABLE,
                'label' => 'Non applicable',
                'description' => 'Cet équipement n’est pas adapté à ce logement.',
                'icon' => 'slash-circle',
            ],
        ];
    }

    private function assertRoomBelongsToCheck(AirbnbCheckRoom $room, AirbnbCheck $check): void
    {
        if ($room->getCheck()?->getId() !== $check->getId()) {
            throw $this->createNotFoundException('Pièce introuvable pour ce check.');
        }
    }

    private function assertEquipmentBelongsToCheck(AirbnbCheckEquipment $equipment, AirbnbCheck $check): void
    {
        if ($equipment->getRoom()?->getCheck()?->getId() !== $check->getId()) {
            throw $this->createNotFoundException('Équipement introuvable pour ce check.');
        }
    }
}
