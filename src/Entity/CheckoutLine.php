<?php

namespace App\Entity;

use App\Enum\EquipmentCheckStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class CheckoutLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Checkout $checkout = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Room $room = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?RoomEquipment $roomEquipment = null;

    #[ORM\Column(enumType: EquipmentCheckStatus::class, nullable: true)]
    private ?EquipmentCheckStatus $status = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column]
    private int $sequence = 0;

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

    public function getStatus(): ?EquipmentCheckStatus
    {
        return $this->status;
    }

    public function setStatus(?EquipmentCheckStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
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

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function setCheckedAt(?\DateTimeImmutable $checkedAt): self
    {
        $this->checkedAt = $checkedAt;

        return $this;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function setSequence(int $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }
}
