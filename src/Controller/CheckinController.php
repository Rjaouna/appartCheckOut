<?php

namespace App\Controller;

use App\Entity\Apartment;
use App\Entity\ApartmentReservation;
use App\Entity\ReservationCheckin;
use App\Entity\User;
use App\Enum\ApartmentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CheckinController extends AbstractController
{
    #[Route('/admin/checkins', name: 'admin_checkins', methods: ['GET'])]
    public function adminIndex(EntityManagerInterface $entityManager): Response
    {
        return $this->render('checkin/index.html.twig', [
            'reservations' => $this->findPendingCheckinReservations($entityManager),
            'todayDate' => new \DateTimeImmutable('today'),
            'dashboardRoute' => 'admin_dashboard',
            'formRoute' => 'admin_checkin_form',
            'historyRoute' => 'admin_checkin_history',
            'isAdminView' => true,
        ]);
    }

    #[Route('/employee/checkins', name: 'employee_checkins', methods: ['GET'])]
    public function employeeIndex(EntityManagerInterface $entityManager): Response
    {
        /** @var User $employee */
        $employee = $this->getUser();

        return $this->render('checkin/index.html.twig', [
            'reservations' => $this->findPendingCheckinReservations($entityManager, $employee),
            'todayDate' => new \DateTimeImmutable('today'),
            'dashboardRoute' => 'employee_dashboard',
            'formRoute' => 'employee_checkin_form',
            'historyRoute' => null,
            'isAdminView' => false,
        ]);
    }

    #[Route('/admin/checkins/reservations/{id}', name: 'admin_checkin_form', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function adminForm(ApartmentReservation $reservation): Response
    {
        try {
            $this->assertReservationCanBeCompleted($reservation);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_checkins');
        }

        return $this->renderCheckinForm($reservation, 'admin_checkin_complete', 'admin_checkins');
    }

    #[Route('/employee/checkins/reservations/{id}', name: 'employee_checkin_form', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function employeeForm(ApartmentReservation $reservation): Response
    {
        /** @var User $employee */
        $employee = $this->getUser();
        try {
            $this->assertReservationCanBeCompleted($reservation, $employee);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('employee_checkins');
        }

        return $this->renderCheckinForm($reservation, 'employee_checkin_complete', 'employee_checkins');
    }

    #[Route('/admin/checkins/reservations/{id}', name: 'admin_checkin_complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function adminComplete(
        ApartmentReservation $reservation,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        return $this->completeCheckin($reservation, $request, $entityManager, 'admin_checkins');
    }

    #[Route('/employee/checkins/reservations/{id}', name: 'employee_checkin_complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function employeeComplete(
        ApartmentReservation $reservation,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        /** @var User $employee */
        $employee = $this->getUser();

        return $this->completeCheckin($reservation, $request, $entityManager, 'employee_checkins', $employee);
    }

    #[Route('/admin/checkins/history', name: 'admin_checkin_history', methods: ['GET'])]
    public function history(EntityManagerInterface $entityManager): Response
    {
        return $this->renderCheckinHistory($entityManager, [
            'pageTitle' => 'Historique des check-ins',
            'pageEyebrow' => 'Administration',
            'pageDescription' => 'fiche enregistrée pour la transmission papier.',
            'pageDescriptionPlural' => 'fiches enregistrées pour la transmission papier.',
            'activeListRoute' => 'admin_checkins',
            'activeListLabel' => 'Check-ins en cours',
            'backRoute' => 'admin_dashboard',
        ]);
    }

    #[Route('/admin/arrivals/history', name: 'admin_arrivals_history', methods: ['GET'])]
    public function arrivalsHistory(EntityManagerInterface $entityManager): Response
    {
        return $this->renderCheckinHistory($entityManager, [
            'pageTitle' => 'Historique des arrivées',
            'pageEyebrow' => 'Arrivées',
            'pageDescription' => 'arrivée finalisée par une fiche check-in.',
            'pageDescriptionPlural' => 'arrivées finalisées par une fiche check-in.',
            'activeListRoute' => 'admin_arrivals',
            'activeListLabel' => 'Arrivées actives',
            'backRoute' => 'admin_arrivals',
        ]);
    }

    /**
     * @param array<string, string> $viewOptions
     */
    private function renderCheckinHistory(EntityManagerInterface $entityManager, array $viewOptions): Response
    {
        $checkins = $this->findCompletedCheckins($entityManager);

        return $this->render('checkin/history.html.twig', [
            'checkins' => $checkins,
            ...$viewOptions,
        ]);
    }

    /**
     * @return list<ReservationCheckin>
     */
    private function findCompletedCheckins(EntityManagerInterface $entityManager): array
    {
        return $entityManager->createQueryBuilder()
            ->select('checkin', 'reservation', 'apartment', 'completedBy', 'processedBy')
            ->from(ReservationCheckin::class, 'checkin')
            ->leftJoin('checkin.reservation', 'reservation')
            ->leftJoin('reservation.apartment', 'apartment')
            ->leftJoin('checkin.completedBy', 'completedBy')
            ->leftJoin('checkin.processedBy', 'processedBy')
            ->orderBy('checkin.completedAt', 'DESC')
            ->addOrderBy('checkin.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    #[Route('/admin/checkins/history/{id}', name: 'admin_checkin_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ReservationCheckin $checkin): Response
    {
        return $this->render('checkin/show.html.twig', [
            'checkin' => $checkin,
            'reservation' => $checkin->getReservation(),
            'apartment' => $checkin->getReservation()?->getApartment(),
        ]);
    }

    #[Route('/admin/checkins/history/{id}/processed', name: 'admin_checkin_mark_processed', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markProcessed(ReservationCheckin $checkin, EntityManagerInterface $entityManager): Response
    {
        /** @var User|null $actor */
        $actor = $this->getUser();

        $checkin
            ->setProcessedAt(new \DateTimeImmutable())
            ->setProcessedBy($actor);

        $entityManager->flush();
        $this->addFlash('success', 'Fiche check-in marquée comme traitée.');

        return $this->redirectToRoute('admin_checkin_show', ['id' => $checkin->getId()]);
    }

    private function renderCheckinForm(ApartmentReservation $reservation, string $submitRoute, string $indexRoute): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        return $this->render('checkin/form.html.twig', [
            'reservation' => $reservation,
            'apartment' => $reservation->getApartment(),
            'guestRows' => $this->buildDefaultGuestRows($reservation),
            'submitRoute' => $submitRoute,
            'indexRoute' => $indexRoute,
            'defaultHostAgentName' => $user instanceof User ? $user->getFullName() : '',
            'defaultHostAgentPhone' => $user instanceof User ? $user->getPhoneNumber() : '',
            'defaultCheckInDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'defaultCheckOutDate' => $reservation->getDepartureDate()?->format('Y-m-d') ?? (new \DateTimeImmutable())->format('Y-m-d'),
        ]);
    }

    private function completeCheckin(
        ApartmentReservation $reservation,
        Request $request,
        EntityManagerInterface $entityManager,
        string $redirectRoute,
        ?User $employee = null
    ): Response {
        try {
            $this->assertReservationCanBeCompleted($reservation, $employee);
            $checkin = $this->buildCheckinFromRequest($reservation, $request);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute($employee instanceof User ? 'employee_checkin_form' : 'admin_checkin_form', [
                'id' => $reservation->getId(),
            ]);
        }

        /** @var User|null $actor */
        $actor = $this->getUser();
        $checkin
            ->setReservation($reservation)
            ->snapshotFromReservation($reservation)
            ->setCompletedBy($actor)
            ->setCompletedAt(new \DateTimeImmutable());

        $reservation->setCheckin($checkin);

        $entityManager->persist($checkin);
        $entityManager->flush();

        $this->addFlash('success', 'Check-in enregistré. La fiche est disponible dans l’historique administrateur.');

        return $this->redirectToRoute($redirectRoute);
    }

    private function buildCheckinFromRequest(ApartmentReservation $reservation, Request $request): ReservationCheckin
    {
        $hostAgentName = trim((string) $request->request->get('hostAgentName'));
        if ($hostAgentName === '') {
            throw new \InvalidArgumentException('Le nom de l agent d accueil est obligatoire.');
        }

        $guestIdentities = $this->extractGuestIdentities($request);
        if ($guestIdentities === []) {
            throw new \InvalidArgumentException('Au moins un voyageur avec son document d identite est obligatoire.');
        }

        $guestCount = count($guestIdentities);
        if ($guestCount > 20) {
            throw new \InvalidArgumentException('Le nombre de voyageurs doit être compris entre 1 et 20.');
        }

        $checkInDate = new \DateTimeImmutable('today');
        $checkOutDate = $reservation->getDepartureDate();
        if (!$checkOutDate instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('La date de sortie de la réservation est introuvable.');
        }

        if ($checkOutDate < $checkInDate) {
            throw new \InvalidArgumentException('La date de sortie doit être postérieure ou égale à la date d’entrée.');
        }

        $checkOutTime = '12:00';

        $signatureName = trim($reservation->getGuestName()) ?: $guestIdentities[0]['name'];
        $signatureData = $this->normalizeSignatureData($request->request->get('signatureData'));

        if (!$request->request->has('noUnregisteredGuestsAccepted')) {
            throw new \InvalidArgumentException('La consigne sur les personnes non déclarées doit être acceptée.');
        }

        if (!$request->request->has('noDualNationalityAccepted')) {
            throw new \InvalidArgumentException('La consigne sur la double nationalité doit être acceptée.');
        }

        if (!$request->request->has('rulesAccepted')) {
            throw new \InvalidArgumentException('Le règlement intérieur doit être accepté.');
        }

        return (new ReservationCheckin())
            ->setHostAgentName($hostAgentName)
            ->setHostAgentPhone($this->nullableText($request->request->get('hostAgentPhone')))
            ->setGuestCount($guestCount)
            ->setGuestIdentities($guestIdentities)
            ->setCheckInDate($checkInDate)
            ->setCheckOutDate($checkOutDate)
            ->setCheckOutTime($checkOutTime)
            ->setReturnTransport($this->nullableText($request->request->get('returnTransport')))
            ->setExtensionRequested($this->nullableText($request->request->get('extensionRequested')))
            ->setVisitedMarrakechBefore($this->parseNullableBoolean($request->request->get('visitedMarrakechBefore')))
            ->setNoUnregisteredGuestsAccepted(true)
            ->setNoDualNationalityAccepted(true)
            ->setRulesAccepted(true)
            ->setSignatureName($signatureName)
            ->setSignatureData($signatureData);
    }

    /**
     * @return list<ApartmentReservation>
     */
    private function findPendingCheckinReservations(EntityManagerInterface $entityManager, ?User $employee = null): array
    {
        $today = new \DateTimeImmutable('today');
        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('reservation', 'apartment', 'checkin')
            ->from(ApartmentReservation::class, 'reservation')
            ->join('reservation.apartment', 'apartment')
            ->leftJoin('reservation.checkin', 'checkin')
            ->where('apartment.status = :apartmentStatus')
            ->andWhere('reservation.departureDate >= :today')
            ->andWhere('checkin.id IS NULL')
            ->setParameter('apartmentStatus', ApartmentStatus::Active)
            ->setParameter('today', $today, 'date_immutable')
            ->orderBy('reservation.arrivalDate', 'ASC')
            ->addOrderBy('reservation.id', 'DESC');

        if ($employee instanceof User) {
            $queryBuilder
                ->join('apartment.assignedEmployees', 'employee')
                ->andWhere('employee = :employee')
                ->setParameter('employee', $employee);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    private function assertReservationCanBeCompleted(ApartmentReservation $reservation, ?User $employee = null): void
    {
        $apartment = $reservation->getApartment();
        if (!$apartment instanceof Apartment || $apartment->getStatus() !== ApartmentStatus::Active) {
            throw new \InvalidArgumentException('Appartement introuvable ou inactif.');
        }

        if ($employee instanceof User && !$apartment->getAssignedEmployees()->contains($employee)) {
            throw $this->createAccessDeniedException('Ce check-in ne fait pas partie de tes appartements assignes.');
        }

        if ($reservation->hasCompletedCheckin()) {
            throw new \InvalidArgumentException('Ce check-in est déjà finalisé.');
        }

        if (!$this->isReservationActionable($reservation, new \DateTimeImmutable('today'))) {
            throw new \InvalidArgumentException('Ce check-in n’est pas encore actif ou le séjour est déjà terminé.');
        }
    }

    private function isReservationActionable(ApartmentReservation $reservation, \DateTimeImmutable $today): bool
    {
        $arrivalDate = $reservation->getArrivalDate();
        $departureDate = $reservation->getDepartureDate();

        if (!$arrivalDate instanceof \DateTimeImmutable || !$departureDate instanceof \DateTimeImmutable) {
            return false;
        }

        $today = $today->setTime(0, 0);

        return $arrivalDate <= $today && $departureDate >= $today;
    }

    /**
     * @return list<array{name: string, identityNumber: string}>
     */
    private function extractGuestIdentities(Request $request): array
    {
        $guestNames = $request->request->all('guestNames');
        $guestIdentityNumbers = $request->request->all('guestIdentityNumbers');
        $rows = [];
        $maxRows = max(count($guestNames), count($guestIdentityNumbers));

        for ($index = 0; $index < $maxRows; ++$index) {
            $name = trim((string) ($guestNames[$index] ?? ''));
            $identityNumber = trim((string) ($guestIdentityNumbers[$index] ?? ''));

            if ($name === '' && $identityNumber === '') {
                if ($maxRows > 1) {
                    throw new \InvalidArgumentException('Chaque entrée ajoutée doit être complétée ou supprimée.');
                }

                continue;
            }

            if ($name === '' || $identityNumber === '') {
                throw new \InvalidArgumentException('Chaque voyageur renseigné doit avoir un nom et un numéro de passeport ou CIN.');
            }

            $rows[] = [
                'name' => $name,
                'identityNumber' => $identityNumber,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, identityNumber: string}>
     */
    private function buildDefaultGuestRows(ApartmentReservation $reservation): array
    {
        return [
            ['name' => $reservation->getGuestName(), 'identityNumber' => ''],
        ];
    }

    private function parseNullableBoolean(mixed $value): ?bool
    {
        return match ((string) $value) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    private function normalizeSignatureData(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new \InvalidArgumentException('La signature du locataire est obligatoire.');
        }

        if (strlen($value) > 500000) {
            throw new \InvalidArgumentException('La signature est trop volumineuse. Efface-la puis recommence.');
        }

        if (preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $value) !== 1) {
            throw new \InvalidArgumentException('La signature est invalide. Efface-la puis recommence.');
        }

        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
