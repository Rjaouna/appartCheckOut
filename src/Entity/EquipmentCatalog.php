<?php

namespace App\Entity;

use App\Enum\RoomType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class EquipmentCatalog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $name = '';

    #[ORM\Column(enumType: RoomType::class)]
    private RoomType $roomType = RoomType::Other;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $isRequired = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referencePhoto = null;

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

    public function getRoomType(): RoomType
    {
        return $this->roomType;
    }

    public function setRoomType(RoomType $roomType): self
    {
        $this->roomType = $roomType;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $isRequired): self
    {
        $this->isRequired = $isRequired;

        return $this;
    }

    public function getReferencePhoto(): ?string
    {
        return $this->referencePhoto;
    }

    public function setReferencePhoto(?string $referencePhoto): self
    {
        $this->referencePhoto = $referencePhoto;

        return $this;
    }
}
