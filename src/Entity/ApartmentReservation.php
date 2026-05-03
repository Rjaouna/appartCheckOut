<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ApartmentReservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Apartment $apartment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    #[ORM\Column(length: 120)]
    private string $guestName = '';

    #[ORM\Column(length: 30)]
    private string $guestWhatsappNumber = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $arrivalDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $departureDate = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $accessMessageSentAt = null;

    #[ORM\Column]
    private int $accessMessageSentCount = 0;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Checkout $linkedCheckout = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    public function getGuestName(): string
    {
        return $this->guestName;
    }

    public function setGuestName(string $guestName): self
    {
        $this->guestName = $guestName;
        $this->touch();

        return $this;
    }

    public function getGuestWhatsappNumber(): string
    {
        return $this->guestWhatsappNumber;
    }

    public function setGuestWhatsappNumber(string $guestWhatsappNumber): self
    {
        $this->guestWhatsappNumber = $guestWhatsappNumber;
        $this->touch();

        return $this;
    }

    public function getArrivalDate(): ?\DateTimeImmutable
    {
        return $this->arrivalDate;
    }

    public function setArrivalDate(?\DateTimeImmutable $arrivalDate): self
    {
        $this->arrivalDate = $arrivalDate;
        $this->touch();

        return $this;
    }

    public function getDepartureDate(): ?\DateTimeImmutable
    {
        return $this->departureDate;
    }

    public function setDepartureDate(?\DateTimeImmutable $departureDate): self
    {
        $this->departureDate = $departureDate;
        $this->touch();

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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getAccessMessageSentAt(): ?\DateTimeImmutable
    {
        return $this->accessMessageSentAt;
    }

    public function setAccessMessageSentAt(?\DateTimeImmutable $accessMessageSentAt): self
    {
        $this->accessMessageSentAt = $accessMessageSentAt;
        $this->touch();

        return $this;
    }

    public function getAccessMessageSentCount(): int
    {
        return $this->accessMessageSentCount;
    }

    public function setAccessMessageSentCount(int $accessMessageSentCount): self
    {
        $this->accessMessageSentCount = max(0, $accessMessageSentCount);
        $this->touch();

        return $this;
    }

    public function incrementAccessMessageSentCount(): self
    {
        ++$this->accessMessageSentCount;
        $this->touch();

        return $this;
    }

    public function getLinkedCheckout(): ?Checkout
    {
        return $this->linkedCheckout;
    }

    public function setLinkedCheckout(?Checkout $linkedCheckout): self
    {
        $this->linkedCheckout = $linkedCheckout;
        $this->touch();

        return $this;
    }

    public function hasSentAccessMessage(): bool
    {
        return $this->accessMessageSentAt instanceof \DateTimeImmutable && $this->accessMessageSentCount > 0;
    }

    public function isArrivalToday(\DateTimeImmutable $today): bool
    {
        if (!$this->arrivalDate instanceof \DateTimeImmutable) {
            return false;
        }

        return $this->arrivalDate->format('Y-m-d') === $today->format('Y-m-d');
    }

    public function isArrivalInFuture(\DateTimeImmutable $today): bool
    {
        if (!$this->arrivalDate instanceof \DateTimeImmutable) {
            return false;
        }

        return $this->arrivalDate > $today->setTime(0, 0);
    }

    public function isArrivalPast(\DateTimeImmutable $today): bool
    {
        if (!$this->arrivalDate instanceof \DateTimeImmutable) {
            return false;
        }

        return $this->arrivalDate < $today->setTime(0, 0);
    }

    public function getStayDateLabel(): string
    {
        if (!$this->arrivalDate instanceof \DateTimeImmutable || !$this->departureDate instanceof \DateTimeImmutable) {
            return 'Dates à confirmer';
        }

        return sprintf('%s → %s', $this->arrivalDate->format('d/m/Y'), $this->departureDate->format('d/m/Y'));
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
