<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AirbnbCheckRoom
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rooms')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AirbnbCheck $check = null;

    #[ORM\Column(length: 80)]
    private string $roomKey = '';

    #[ORM\Column(length: 120)]
    private string $roomName = '';

    #[ORM\Column(length: 80)]
    private string $roomType = 'other';

    #[ORM\Column(length: 80)]
    private string $icon = 'house-check';

    #[ORM\Column]
    private int $displayOrder = 0;

    #[ORM\Column]
    private int $score = 0;

    /**
     * @var Collection<int, AirbnbCheckEquipment>
     */
    #[ORM\OneToMany(mappedBy: 'room', targetEntity: AirbnbCheckEquipment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $equipments;

    public function __construct()
    {
        $this->equipments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCheck(): ?AirbnbCheck
    {
        return $this->check;
    }

    public function setCheck(?AirbnbCheck $check): self
    {
        $this->check = $check;

        return $this;
    }

    public function getRoomKey(): string
    {
        return $this->roomKey;
    }

    public function setRoomKey(string $roomKey): self
    {
        $this->roomKey = $roomKey;

        return $this;
    }

    public function getRoomName(): string
    {
        return $this->roomName;
    }

    public function setRoomName(string $roomName): self
    {
        $this->roomName = $roomName;

        return $this;
    }

    public function getRoomType(): string
    {
        return $this->roomType;
    }

    public function setRoomType(string $roomType): self
    {
        $this->roomType = $roomType;

        return $this;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): self
    {
        $this->icon = $icon;

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

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): self
    {
        $this->score = max(0, min(100, $score));

        return $this;
    }

    /**
     * @return Collection<int, AirbnbCheckEquipment>
     */
    public function getEquipments(): Collection
    {
        return $this->equipments;
    }

    public function addEquipment(AirbnbCheckEquipment $equipment): self
    {
        if (!$this->equipments->contains($equipment)) {
            $this->equipments->add($equipment);
            $equipment->setRoom($this);
        }

        return $this;
    }

    public function getEquipmentCount(): int
    {
        return $this->equipments->count();
    }

    public function getCheckedEquipmentCount(): int
    {
        return count(array_filter(
            $this->equipments->toArray(),
            static fn (AirbnbCheckEquipment $equipment): bool => $equipment->getStatus() !== null
        ));
    }

    public function getProgressPercent(): int
    {
        $total = $this->getEquipmentCount();
        if ($total === 0) {
            return 100;
        }

        return (int) round(($this->getCheckedEquipmentCount() / $total) * 100);
    }

    public function getScoreClass(): string
    {
        if ($this->score >= 90) {
            return 'is-success';
        }

        if ($this->score >= 70) {
            return 'is-warning';
        }

        return 'is-danger';
    }
}
