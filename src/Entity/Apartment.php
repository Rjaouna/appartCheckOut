<?php

namespace App\Entity;

use App\Enum\ApartmentStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Apartment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $name = '';

    #[ORM\Column(length: 50, unique: true)]
    private string $referenceCode = '';

    #[ORM\Column(length: 255)]
    private string $addressLine1 = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressLine2 = null;

    #[ORM\Column(length: 120)]
    private string $city = '';

    #[ORM\Column(length: 20)]
    private string $postalCode = '';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $floor = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $doorNumber = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $mailboxNumber = null;

    #[ORM\Column(length: 255)]
    private string $wazeLink = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleMapsLink = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $buildingAccessCode = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $keyBoxCode = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $entryInstructions = '';

    #[ORM\Column(length: 120)]
    private string $conditionStatus = 'Bon etat';

    #[ORM\Column]
    private int $bedroomCount = 0;

    #[ORM\Column]
    private int $sleepsCount = 0;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ownerName = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $ownerPhone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalNotes = null;

    #[ORM\Column(enumType: ApartmentStatus::class)]
    private ApartmentStatus $status = ApartmentStatus::Active;

    #[ORM\Column]
    private bool $isInventoryPriority = false;

    #[ORM\Column]
    private bool $isTenantAccessEnabled = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tenantAccessLockedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $inventoryDueAt = null;

    #[ORM\Column(type: Types::JSON)]
    private array $generalPhotos = [];

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'assignedApartments')]
    #[ORM\JoinTable(name: 'apartment_assignment')]
    private Collection $assignedEmployees;

    /**
     * @var Collection<int, Room>
     */
    #[ORM\OneToMany(mappedBy: 'apartment', targetEntity: Room::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $rooms;

    /**
     * @var Collection<int, Checkout>
     */
    #[ORM\OneToMany(mappedBy: 'apartment', targetEntity: Checkout::class, cascade: ['persist', 'remove'])]
    private Collection $checkouts;

    /**
     * @var Collection<int, ApartmentAccessStep>
     */
    #[ORM\OneToMany(mappedBy: 'apartment', targetEntity: ApartmentAccessStep::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $accessSteps;

    public function __construct()
    {
        $this->assignedEmployees = new ArrayCollection();
        $this->rooms = new ArrayCollection();
        $this->checkouts = new ArrayCollection();
        $this->accessSteps = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getReferenceCode(): string
    {
        return $this->referenceCode;
    }

    public function setReferenceCode(string $referenceCode): self
    {
        $this->referenceCode = mb_strtoupper($referenceCode);

        return $this;
    }

    public function getAddressLine1(): string
    {
        return $this->addressLine1;
    }

    public function setAddressLine1(string $addressLine1): self
    {
        $this->addressLine1 = $addressLine1;

        return $this;
    }

    public function getAddressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function setAddressLine2(?string $addressLine2): self
    {
        $this->addressLine2 = $addressLine2;

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): self
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getFloor(): ?string
    {
        return $this->floor;
    }

    public function setFloor(?string $floor): self
    {
        $this->floor = $floor;

        return $this;
    }

    public function getDoorNumber(): ?string
    {
        return $this->doorNumber;
    }

    public function setDoorNumber(?string $doorNumber): self
    {
        $this->doorNumber = $doorNumber;

        return $this;
    }

    public function getMailboxNumber(): ?string
    {
        return $this->mailboxNumber;
    }

    public function setMailboxNumber(?string $mailboxNumber): self
    {
        $this->mailboxNumber = $mailboxNumber;

        return $this;
    }

    public function getWazeLink(): string
    {
        return $this->wazeLink;
    }

    public function setWazeLink(string $wazeLink): self
    {
        $this->wazeLink = $wazeLink;

        return $this;
    }

    public function getGoogleMapsLink(): ?string
    {
        return $this->googleMapsLink;
    }

    public function setGoogleMapsLink(?string $googleMapsLink): self
    {
        $this->googleMapsLink = $googleMapsLink;

        return $this;
    }

    public function getBuildingAccessCode(): ?string
    {
        return $this->buildingAccessCode;
    }

    public function setBuildingAccessCode(?string $buildingAccessCode): self
    {
        $this->buildingAccessCode = $buildingAccessCode;

        return $this;
    }

    public function getKeyBoxCode(): ?string
    {
        return $this->keyBoxCode;
    }

    public function setKeyBoxCode(?string $keyBoxCode): self
    {
        $this->keyBoxCode = $keyBoxCode;

        return $this;
    }

    public function getEntryInstructions(): string
    {
        return $this->entryInstructions;
    }

    public function setEntryInstructions(string $entryInstructions): self
    {
        $this->entryInstructions = $entryInstructions;

        return $this;
    }

    public function getConditionStatus(): string
    {
        return $this->conditionStatus;
    }

    public function setConditionStatus(string $conditionStatus): self
    {
        $this->conditionStatus = $conditionStatus;

        return $this;
    }

    public function getBedroomCount(): int
    {
        return $this->bedroomCount;
    }

    public function setBedroomCount(int $bedroomCount): self
    {
        $this->bedroomCount = $bedroomCount;

        return $this;
    }

    public function getSleepsCount(): int
    {
        return $this->sleepsCount;
    }

    public function setSleepsCount(int $sleepsCount): self
    {
        $this->sleepsCount = $sleepsCount;

        return $this;
    }

    public function getOwnerName(): ?string
    {
        return $this->ownerName;
    }

    public function setOwnerName(?string $ownerName): self
    {
        $this->ownerName = $ownerName;

        return $this;
    }

    public function getOwnerPhone(): ?string
    {
        return $this->ownerPhone;
    }

    public function setOwnerPhone(?string $ownerPhone): self
    {
        $this->ownerPhone = $ownerPhone;

        return $this;
    }

    public function getInternalNotes(): ?string
    {
        return $this->internalNotes;
    }

    public function setInternalNotes(?string $internalNotes): self
    {
        $this->internalNotes = $internalNotes;

        return $this;
    }

    public function getStatus(): ApartmentStatus
    {
        return $this->status;
    }

    public function setStatus(ApartmentStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isInventoryPriority(): bool
    {
        return $this->isInventoryPriority;
    }

    public function setIsInventoryPriority(bool $isInventoryPriority): self
    {
        $this->isInventoryPriority = $isInventoryPriority;

        return $this;
    }

    public function isTenantAccessEnabled(): bool
    {
        return $this->isTenantAccessEnabled;
    }

    public function setIsTenantAccessEnabled(bool $isTenantAccessEnabled): self
    {
        $this->isTenantAccessEnabled = $isTenantAccessEnabled;

        return $this;
    }

    public function getTenantAccessLockedAt(): ?\DateTimeImmutable
    {
        return $this->tenantAccessLockedAt;
    }

    public function setTenantAccessLockedAt(?\DateTimeImmutable $tenantAccessLockedAt): self
    {
        $this->tenantAccessLockedAt = $tenantAccessLockedAt;

        return $this;
    }

    public function getInventoryDueAt(): ?\DateTimeImmutable
    {
        return $this->inventoryDueAt;
    }

    public function setInventoryDueAt(?\DateTimeImmutable $inventoryDueAt): self
    {
        $this->inventoryDueAt = $inventoryDueAt;

        return $this;
    }

    public function getGeneralPhotos(): array
    {
        return $this->generalPhotos;
    }

    public function setGeneralPhotos(array $generalPhotos): self
    {
        $this->generalPhotos = $generalPhotos;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getAssignedEmployees(): Collection
    {
        return $this->assignedEmployees;
    }

    public function addAssignedEmployee(User $user): self
    {
        if (!$this->assignedEmployees->contains($user)) {
            $this->assignedEmployees->add($user);
        }

        return $this;
    }

    public function removeAssignedEmployee(User $user): self
    {
        $this->assignedEmployees->removeElement($user);

        return $this;
    }

    /**
     * @return Collection<int, Room>
     */
    public function getRooms(): Collection
    {
        return $this->rooms;
    }

    /**
     * @return list<Room>
     */
    public function getActiveRooms(): array
    {
        return array_values(array_filter(
            $this->rooms->toArray(),
            static fn (Room $room): bool => !$room->isDeleted()
        ));
    }

    public function addRoom(Room $room): self
    {
        if (!$this->rooms->contains($room)) {
            $this->rooms->add($room);
            $room->setApartment($this);
        }

        return $this;
    }

    public function removeRoom(Room $room): self
    {
        if ($this->rooms->removeElement($room) && $room->getApartment() === $this) {
            $room->setApartment(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, Checkout>
     */
    public function getCheckouts(): Collection
    {
        return $this->checkouts;
    }

    /**
     * @return Collection<int, ApartmentAccessStep>
     */
    public function getAccessSteps(): Collection
    {
        return $this->accessSteps;
    }

    /**
     * @return list<ApartmentAccessStep>
     */
    public function getOrderedAccessSteps(): array
    {
        return array_values($this->accessSteps->toArray());
    }

    public function addAccessStep(ApartmentAccessStep $accessStep): self
    {
        if (!$this->accessSteps->contains($accessStep)) {
            $this->accessSteps->add($accessStep);
            $accessStep->setApartment($this);
        }

        return $this;
    }

    public function removeAccessStep(ApartmentAccessStep $accessStep): self
    {
        if ($this->accessSteps->removeElement($accessStep) && $accessStep->getApartment() === $this) {
            $accessStep->setApartment(null);
        }

        return $this;
    }

    public function getFullAddress(): string
    {
        $parts = array_filter([$this->addressLine1, $this->addressLine2, $this->postalCode, $this->city]);

        return implode(', ', $parts);
    }
}
