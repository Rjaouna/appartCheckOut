<?php

namespace App\Service;

use App\Entity\Anomaly;
use App\Entity\Apartment;
use App\Entity\Checkout;
use App\Entity\CheckoutLine;
use App\Entity\User;
use App\Enum\AnomalyStatus;
use App\Enum\AnomalyType;
use App\Enum\CheckoutStatus;
use App\Enum\EquipmentCheckStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CheckoutManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
        private readonly AnomalyHistoryRecorder $anomalyHistoryRecorder,
    ) {
    }

    public function createCheckout(Apartment $apartment, User $assignedTo, string $priority = 'normal', ?\DateTimeImmutable $scheduledAt = null): Checkout
    {
        $checkout = new Checkout();
        $checkout
            ->setApartment($apartment)
            ->setAssignedTo($assignedTo)
            ->setPriority($priority)
            ->setScheduledAt($scheduledAt ?? new \DateTimeImmutable())
            ->setStatus(CheckoutStatus::Todo);

        $sequence = 1;
        foreach ($apartment->getActiveRooms() as $room) {
            foreach ($room->getRoomEquipments() as $roomEquipment) {
                if (!$roomEquipment->isActive()) {
                    continue;
                }

                $line = (new CheckoutLine())
                    ->setRoom($room)
                    ->setRoomEquipment($roomEquipment)
                    ->setSequence($sequence++);

                $checkout->addLine($line);
            }
        }

        $this->entityManager->persist($checkout);

        return $checkout;
    }

    public function updateLine(CheckoutLine $line, EquipmentCheckStatus $status, ?string $comment, ?UploadedFile $photo, User $actor): void
    {
        $comment = $comment !== null ? trim($comment) : null;
        if ($comment === '') {
            $comment = null;
        }

        $checkout = $line->getCheckout();
        if ($checkout === null) {
            throw new \LogicException('La ligne de check-out est invalide.');
        }

        if ($checkout->getStatus() === CheckoutStatus::Todo) {
            $checkout
                ->setStatus(CheckoutStatus::InProgress)
                ->setStartedAt(new \DateTimeImmutable());
        }

        $line
            ->setStatus($status)
            ->setComment($comment)
            ->setCheckedAt(new \DateTimeImmutable());

        if ($photo !== null) {
            $line->setPhotoPath($this->storeAnomalyPhoto($photo));
        }

        $this->syncAnomalyForLine($line, $actor);
    }

    public function pause(Checkout $checkout, string $reason): void
    {
        $checkout
            ->setStatus(CheckoutStatus::Paused)
            ->setPausedAt(new \DateTimeImmutable())
            ->setPauseReason(trim($reason) !== '' ? trim($reason) : 'Pause manuelle');
    }

    public function resume(Checkout $checkout): void
    {
        $checkout
            ->setStatus(CheckoutStatus::InProgress)
            ->setPausedAt(null)
            ->setPauseReason(null);

        if ($checkout->getStartedAt() === null) {
            $checkout->setStartedAt(new \DateTimeImmutable());
        }
    }

    public function complete(Checkout $checkout): void
    {
        $hasUnchecked = $checkout->getLines()->exists(static fn (int $key, CheckoutLine $line) => $line->getStatus() === null);
        if ($hasUnchecked) {
            throw new \InvalidArgumentException('Toutes les lignes doivent etre validees avant de terminer le check-out.');
        }

        $checkout
            ->setStatus(CheckoutStatus::Completed)
            ->setCompletedAt(new \DateTimeImmutable())
            ->setPausedAt(null)
            ->setPauseReason(null);
    }

    private function syncAnomalyForLine(CheckoutLine $line, User $actor): void
    {
        $checkout = $line->getCheckout();
        $status = $line->getStatus();
        if ($checkout === null || $status === null) {
            return;
        }

        $repository = $this->entityManager->getRepository(Anomaly::class);
        $existing = $repository->findOneBy([
            'checkout' => $checkout,
            'room' => $line->getRoom(),
            'roomEquipment' => $line->getRoomEquipment(),
        ]);

        $anomalyType = AnomalyType::fromCheckStatus($status);
        if ($anomalyType === null) {
            if ($existing !== null) {
                $this->entityManager->remove($existing);
            }

            return;
        }

        $anomaly = $existing ?? new Anomaly();
        $anomaly
            ->setCheckout($checkout)
            ->setApartment($checkout->getApartment())
            ->setRoom($line->getRoom())
            ->setRoomEquipment($line->getRoomEquipment())
            ->setType($anomalyType)
            ->setComment($line->getComment() ?? $anomalyType->label())
            ->setPhotoPath($line->getPhotoPath())
            ->setUpdatedAt(new \DateTimeImmutable());

        if ($existing === null) {
            $anomaly
                ->setStatus(AnomalyStatus::New)
                ->setCreatedBy($actor)
                ->setCreatedAt($line->getCheckedAt() ?? new \DateTimeImmutable());
            $this->entityManager->persist($anomaly);
            $this->anomalyHistoryRecorder->record($anomaly, null, AnomalyStatus::New, $actor);
        } else {
            $anomaly->setCreatedBy($anomaly->getCreatedBy() ?? $actor);
        }
    }

    private function storeAnomalyPhoto(UploadedFile $photo): string
    {
        $targetDir = $this->projectDir . '/public/uploads/anomalies';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $safeName = pathinfo($photo->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $safeName) ?: 'photo';
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(4)), $photo->guessExtension() ?: 'jpg');

        $photo->move($targetDir, $filename);

        return '/uploads/anomalies/' . $filename;
    }
}
