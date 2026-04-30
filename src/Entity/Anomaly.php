<?php

namespace App\Entity;

use App\Enum\AnomalyStatus;
use App\Enum\AnomalyType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Anomaly
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'anomalies')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Checkout $checkout = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Apartment $apartment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Room $room = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?RoomEquipment $roomEquipment = null;

    #[ORM\Column(enumType: AnomalyType::class)]
    private AnomalyType $type = AnomalyType::Minor;

    #[ORM\Column(enumType: AnomalyStatus::class)]
    private AnomalyStatus $status = AnomalyStatus::New;

    #[ORM\Column(type: Types::TEXT)]
    private string $comment = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    /**
     * @var Collection<int, AnomalyStatusHistory>
     */
    #[ORM\OneToMany(mappedBy: 'anomaly', targetEntity: AnomalyStatusHistory::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['changedAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $statusHistories;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->statusHistories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCheckout(): ?Checkout
    {
        return $this->checkout;
    }

    public function setCheckout(?Checkout $checkout): self
    {
        $this->checkout = $checkout;

        return $this;
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

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function setRoom(?Room $room): self
    {
        $this->room = $room;

        return $this;
    }

    public function getRoomEquipment(): ?RoomEquipment
    {
        return $this->roomEquipment;
    }

    public function setRoomEquipment(?RoomEquipment $roomEquipment): self
    {
        $this->roomEquipment = $roomEquipment;

        return $this;
    }

    public function getType(): AnomalyType
    {
        return $this->type;
    }

    public function setType(AnomalyType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): AnomalyStatus
    {
        return $this->status;
    }

    public function setStatus(AnomalyStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function setComment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function setPhotoPath(?string $photoPath): self
    {
        $this->photoPath = $photoPath;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    /**
     * @return Collection<int, AnomalyStatusHistory>
     */
    public function getStatusHistories(): Collection
    {
        return $this->statusHistories;
    }

    public function addStatusHistory(AnomalyStatusHistory $statusHistory): self
    {
        if (!$this->statusHistories->contains($statusHistory)) {
            $this->statusHistories->add($statusHistory);
            $statusHistory->setAnomaly($this);
        }

        return $this;
    }

    public function removeStatusHistory(AnomalyStatusHistory $statusHistory): self
    {
        if ($this->statusHistories->removeElement($statusHistory)) {
            if ($statusHistory->getAnomaly() === $this) {
                $statusHistory->setAnomaly(null);
            }
        }

        return $this;
    }
}
