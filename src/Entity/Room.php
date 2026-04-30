<?php

namespace App\Entity;

use App\Enum\RoomType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Room
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rooms')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Apartment $apartment = null;

    #[ORM\Column(enumType: RoomType::class)]
    private RoomType $type = RoomType::Other;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column]
    private int $displayOrder = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * @var Collection<int, RoomEquipment>
     */
    #[ORM\OneToMany(mappedBy: 'room', targetEntity: RoomEquipment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $roomEquipments;

    public function __construct()
    {
        $this->roomEquipments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApartment(): ?Apartment
    {
        return $this->apartment;
    }

    public function setApartment(?Apartment $apartment): self
    {
        $this->apartment = $apartment;

        return $this;
    }

    public function getType(): RoomType
    {
        return $this->type;
    }

    public function setType(RoomType $type): self
    {
        $this->type = $type;

        return $this;
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

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * @return Collection<int, RoomEquipment>
     */
    public function getRoomEquipments(): Collection
    {
        return $this->roomEquipments;
    }

    /**
     * @return list<RoomEquipment>
     */
    public function getActiveRoomEquipments(): array
    {
        return array_values(array_filter(
            $this->roomEquipments->toArray(),
            static fn (RoomEquipment $roomEquipment): bool => $roomEquipment->isActive()
        ));
    }

    public function addRoomEquipment(RoomEquipment $roomEquipment): self
    {
        if (!$this->roomEquipments->contains($roomEquipment)) {
            $this->roomEquipments->add($roomEquipment);
            $roomEquipment->setRoom($this);
        }

        return $this;
    }
}
