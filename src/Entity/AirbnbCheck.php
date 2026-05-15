<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AirbnbCheck
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Apartment $apartment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column]
    private int $missingIssueCount = 0;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reportSentAt = null;

    /**
     * @var Collection<int, AirbnbCheckRoom>
     */
    #[ORM\OneToMany(mappedBy: 'check', targetEntity: AirbnbCheckRoom::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $rooms;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->rooms = new ArrayCollection();
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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

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

    public function getMissingIssueCount(): int
    {
        return $this->missingIssueCount;
    }

    public function setMissingIssueCount(int $missingIssueCount): self
    {
        $this->missingIssueCount = max(0, $missingIssueCount);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_COMPLETED], true)) {
            throw new \InvalidArgumentException('Statut de rapport Airbnb invalide.');
        }

        $this->status = $status;

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

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getReportSentAt(): ?\DateTimeImmutable
    {
        return $this->reportSentAt;
    }

    public function setReportSentAt(?\DateTimeImmutable $reportSentAt): self
    {
        $this->reportSentAt = $reportSentAt;

        return $this;
    }

    /**
     * @return Collection<int, AirbnbCheckRoom>
     */
    public function getRooms(): Collection
    {
        return $this->rooms;
    }

    public function addRoom(AirbnbCheckRoom $room): self
    {
        if (!$this->rooms->contains($room)) {
            $this->rooms->add($room);
            $room->setCheck($this);
        }

        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getValidationLabel(): string
    {
        if ($this->score >= 100) {
            return 'Appartement validé Airbnb';
        }

        if ($this->score >= 70) {
            return 'Appartement exploitable avec améliorations';
        }

        return 'Appartement non recommandé';
    }

    public function getBadgeLabel(): string
    {
        if (!$this->isCompleted()) {
            return 'Audit en cours';
        }

        if ($this->score >= 100) {
            return 'Validé 100%';
        }

        if ($this->score >= 70) {
            return sprintf('À compléter %d%%', $this->score);
        }

        return sprintf('Non conforme %d%%', $this->score);
    }

    public function getBadgeClass(): string
    {
        if (!$this->isCompleted()) {
            return 'is-neutral';
        }

        if ($this->score >= 100) {
            return 'is-success';
        }

        if ($this->score >= 70) {
            return 'is-warning';
        }

        return 'is-danger';
    }
}
