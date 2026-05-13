<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ApartmentManual
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'manuals')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Apartment $apartment = null;

    #[ORM\Column(length: 160)]
    private string $title = '';

    #[ORM\Column(length: 160)]
    private string $equipmentLabel = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $shortMessage = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $importantNotice = null;

    #[ORM\Column(length: 255)]
    private string $videoPath = '';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private int $displayOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function getEquipmentLabel(): string
    {
        return $this->equipmentLabel;
    }

    public function setEquipmentLabel(string $equipmentLabel): self
    {
        $this->equipmentLabel = trim($equipmentLabel);

        return $this;
    }

    public function getShortMessage(): ?string
    {
        return $this->shortMessage;
    }

    public function setShortMessage(?string $shortMessage): self
    {
        $this->shortMessage = $shortMessage !== null ? trim($shortMessage) : null;

        return $this;
    }

    public function getImportantNotice(): ?string
    {
        return $this->importantNotice;
    }

    public function setImportantNotice(?string $importantNotice): self
    {
        $this->importantNotice = $importantNotice !== null ? trim($importantNotice) : null;

        return $this;
    }

    public function getVideoPath(): string
    {
        return $this->videoPath;
    }

    public function setVideoPath(string $videoPath): self
    {
        $this->videoPath = $videoPath;

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

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = max(0, $displayOrder);

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
}
