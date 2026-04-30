<?php

namespace App\Entity;

use App\Enum\CheckoutStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Checkout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'checkouts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Apartment $apartment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $assignedTo = null;

    #[ORM\Column(enumType: CheckoutStatus::class)]
    private CheckoutStatus $status = CheckoutStatus::Todo;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pauseReason = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $blockReason = null;

    #[ORM\Column(length: 30)]
    private string $priority = 'normal';

    /**
     * @var Collection<int, CheckoutLine>
     */
    #[ORM\OneToMany(mappedBy: 'checkout', targetEntity: CheckoutLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sequence' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /**
     * @var Collection<int, Anomaly>
     */
    #[ORM\OneToMany(mappedBy: 'checkout', targetEntity: Anomaly::class, cascade: ['persist', 'remove'])]
    private Collection $anomalies;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->anomalies = new ArrayCollection();
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

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): self
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    public function getStatus(): CheckoutStatus
    {
        return $this->status;
    }

    public function setStatus(CheckoutStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getPausedAt(): ?\DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function setPausedAt(?\DateTimeImmutable $pausedAt): self
    {
        $this->pausedAt = $pausedAt;

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

    public function getPauseReason(): ?string
    {
        return $this->pauseReason;
    }

    public function setPauseReason(?string $pauseReason): self
    {
        $this->pauseReason = $pauseReason;

        return $this;
    }

    public function getBlockReason(): ?string
    {
        return $this->blockReason;
    }

    public function setBlockReason(?string $blockReason): self
    {
        $this->blockReason = $blockReason;

        return $this;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * @return Collection<int, CheckoutLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(CheckoutLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setCheckout($this);
        }

        return $this;
    }

    public function getProgressPercent(): int
    {
        $total = $this->lines->count();
        if ($total === 0) {
            return 0;
        }

        $done = $this->lines->filter(static fn (CheckoutLine $line) => $line->getStatus() !== null)->count();

        return (int) floor(($done / $total) * 100);
    }

    /**
     * @return Collection<int, Anomaly>
     */
    public function getAnomalies(): Collection
    {
        return $this->anomalies;
    }
}
