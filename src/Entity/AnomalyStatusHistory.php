<?php

namespace App\Entity;

use App\Enum\AnomalyStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AnomalyStatusHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'statusHistories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Anomaly $anomaly = null;

    #[ORM\Column(enumType: AnomalyStatus::class, nullable: true)]
    private ?AnomalyStatus $fromStatus = null;

    #[ORM\Column(enumType: AnomalyStatus::class)]
    private AnomalyStatus $toStatus = AnomalyStatus::New;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $changedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $changedAt;

    public function __construct()
    {
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnomaly(): ?Anomaly
    {
        return $this->anomaly;
    }

    public function setAnomaly(?Anomaly $anomaly): self
    {
        $this->anomaly = $anomaly;

        return $this;
    }

    public function getFromStatus(): ?AnomalyStatus
    {
        return $this->fromStatus;
    }

    public function setFromStatus(?AnomalyStatus $fromStatus): self
    {
        $this->fromStatus = $fromStatus;

        return $this;
    }

    public function getToStatus(): AnomalyStatus
    {
        return $this->toStatus;
    }

    public function setToStatus(AnomalyStatus $toStatus): self
    {
        $this->toStatus = $toStatus;

        return $this;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }

    public function setChangedBy(?User $changedBy): self
    {
        $this->changedBy = $changedBy;

        return $this;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function setChangedAt(\DateTimeImmutable $changedAt): self
    {
        $this->changedAt = $changedAt;

        return $this;
    }
}
