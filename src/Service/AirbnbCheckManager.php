<?php

namespace App\Service;

use App\Entity\AirbnbCheck;
use App\Entity\AirbnbCheckEquipment;
use App\Entity\AirbnbCheckRoom;
use App\Entity\Apartment;
use App\Entity\Room;
use App\Entity\RoomEquipment;
use App\Entity\User;
use App\Enum\RoomType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AirbnbCheckManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AirbnbUsabilityChecklist $checklist,
        private readonly string $projectDir,
    ) {
    }

    public function createCheck(Apartment $apartment, User $createdBy): AirbnbCheck
    {
        $check = (new AirbnbCheck())
            ->setApartment($apartment)
            ->setCreatedBy($createdBy);

        foreach ($this->checklist->rooms() as $roomIndex => $roomDefinition) {
            $room = (new AirbnbCheckRoom())
                ->setRoomKey($roomDefinition['key'])
                ->setRoomName($roomDefinition['name'])
                ->setRoomType($roomDefinition['roomType'])
                ->setIcon($roomDefinition['icon'])
                ->setDisplayOrder($roomIndex + 1);

            foreach ($roomDefinition['equipments'] as $equipmentIndex => $equipmentDefinition) {
                $equipment = (new AirbnbCheckEquipment())
                    ->setEquipmentKey($equipmentDefinition['key'])
                    ->setName($equipmentDefinition['name'])
                    ->setCategory($roomDefinition['name'])
                    ->setIcon($equipmentDefinition['icon'])
                    ->setImportance($equipmentDefinition['importance'])
                    ->setWeight($equipmentDefinition['weight'])
                    ->setDisplayOrder($equipmentIndex + 1);

                $room->addEquipment($equipment);
            }

            $check->addRoom($room);
        }

        $this->recalculate($check);
        $this->entityManager->persist($check);

        return $check;
    }

    public function updateEquipment(AirbnbCheckEquipment $equipment, string $status, ?string $note, ?string $taskLabel, ?UploadedFile $photo): void
    {
        $equipment
            ->setStatus($status)
            ->setNote($note)
            ->setTaskLabel($taskLabel)
            ->setUpdatedAt(new \DateTimeImmutable());

        if ($photo instanceof UploadedFile && $photo->getError() !== UPLOAD_ERR_NO_FILE) {
            $equipment->setPhotoPath($this->storePhoto($photo));
        }

        $check = $equipment->getRoom()?->getCheck();
        if ($check instanceof AirbnbCheck) {
            $this->recalculate($check);
        }
    }

    public function validatePendingRoomEquipments(AirbnbCheckRoom $room): int
    {
        $updatedCount = 0;

        foreach ($room->getEquipments() as $equipment) {
            if ($equipment->getStatus() !== null) {
                continue;
            }

            $equipment
                ->setStatus(AirbnbCheckEquipment::STATUS_GOOD)
                ->setUpdatedAt(new \DateTimeImmutable());
            ++$updatedCount;
        }

        $check = $room->getCheck();
        if ($check instanceof AirbnbCheck) {
            $this->recalculate($check);
        }

        return $updatedCount;
    }

    public function complete(AirbnbCheck $check): void
    {
        $this->recalculate($check);

        if (!$check->isCompleted()) {
            $check
                ->setStatus(AirbnbCheck::STATUS_COMPLETED)
                ->setCompletedAt(new \DateTimeImmutable());
        }

        $this->syncApartmentRoomsAndEquipments($check);
    }

    public function recalculate(AirbnbCheck $check): void
    {
        $globalPossible = 0;
        $globalEarned = 0.0;
        $issueCount = 0;

        foreach ($check->getRooms() as $room) {
            $roomPossible = 0;
            $roomEarned = 0.0;

            foreach ($room->getEquipments() as $equipment) {
                if (!$equipment->isIncludedInScore()) {
                    continue;
                }

                $roomPossible += $equipment->getWeight();
                $roomEarned += $equipment->getWeightedScore();

                if ($equipment->isIssue()) {
                    ++$issueCount;
                }
            }

            $room->setScore($roomPossible > 0 ? (int) round(($roomEarned / $roomPossible) * 100) : 100);
            $globalPossible += $roomPossible;
            $globalEarned += $roomEarned;
        }

        $check
            ->setScore($globalPossible > 0 ? (int) round(($globalEarned / $globalPossible) * 100) : 100)
            ->setMissingIssueCount($issueCount);
    }

    private function syncApartmentRoomsAndEquipments(AirbnbCheck $check): void
    {
        $apartment = $check->getApartment();
        if (!$apartment instanceof Apartment) {
            return;
        }

        foreach ($check->getRooms() as $checkRoom) {
            $room = $this->findOrCreateRoom($apartment, $checkRoom);

            foreach ($checkRoom->getEquipments() as $checkEquipment) {
                if (!in_array($checkEquipment->getStatus(), [AirbnbCheckEquipment::STATUS_GOOD, AirbnbCheckEquipment::STATUS_AVERAGE], true)) {
                    continue;
                }

                if ($this->roomHasEquipment($room, $checkEquipment->getName())) {
                    continue;
                }

                $equipment = (new RoomEquipment())
                    ->setLabel($checkEquipment->getName())
                    ->setDisplayOrder($room->getRoomEquipments()->count() + 1)
                    ->setQuantity(1)
                    ->setIsActive(true)
                    ->setNotes(sprintf('Ajouté automatiquement depuis le check d’utilisabilité Airbnb du %s.', ($check->getCompletedAt() ?? new \DateTimeImmutable())->format('d/m/Y')));

                $room->addRoomEquipment($equipment);
                $this->entityManager->persist($equipment);
            }
        }
    }

    private function findOrCreateRoom(Apartment $apartment, AirbnbCheckRoom $checkRoom): Room
    {
        foreach ($apartment->getActiveRooms() as $room) {
            if ($this->normalizeLabel($room->getName()) === $this->normalizeLabel($checkRoom->getRoomName())) {
                return $room;
            }
        }

        $roomType = RoomType::tryFrom($checkRoom->getRoomType()) ?? RoomType::Other;
        $room = (new Room())
            ->setApartment($apartment)
            ->setType($roomType)
            ->setName($checkRoom->getRoomName())
            ->setDisplayOrder($apartment->getRooms()->count() + 1);

        $apartment->addRoom($room);
        $this->entityManager->persist($room);

        return $room;
    }

    private function roomHasEquipment(Room $room, string $label): bool
    {
        $normalizedLabel = $this->normalizeLabel($label);
        foreach ($room->getActiveRoomEquipments() as $equipment) {
            if ($this->normalizeLabel($equipment->getLabel()) === $normalizedLabel) {
                return true;
            }
        }

        return false;
    }

    private function storePhoto(UploadedFile $photo): string
    {
        if ($photo->getSize() !== null && $photo->getSize() > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('La photo dépasse la taille autorisée.');
        }

        $mimeType = (string) ($photo->getMimeType() ?? '');
        if (!str_starts_with($mimeType, 'image/')) {
            throw new \InvalidArgumentException('La photo doit être une image valide.');
        }

        $targetDir = $this->projectDir . '/public/uploads/airbnb-checks';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $safeName = pathinfo($photo->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $safeName) ?: 'photo';
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(4)), $photo->guessExtension() ?: 'jpg');

        $photo->move($targetDir, $filename);

        return '/uploads/airbnb-checks/' . $filename;
    }

    private function normalizeLabel(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = strtr($value, [
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'î' => 'i',
            'ï' => 'i',
            'ô' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
        ]);

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;
    }
}
