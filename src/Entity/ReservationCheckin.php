<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ReservationCheckin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'checkin')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ApartmentReservation $reservation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $completedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $processedBy = null;

    #[ORM\Column(length: 120)]
    private string $hostAgentName = '';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $hostAgentPhone = null;

    #[ORM\Column(length: 120)]
    private string $guestName = '';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $guestWhatsappNumber = null;

    #[ORM\Column(length: 150)]
    private string $apartmentName = '';

    #[ORM\Column(length: 500)]
    private string $apartmentAddress = '';

    #[ORM\Column]
    private int $guestCount = 1;

    /**
     * @var list<array{name: string, identityNumber: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $guestIdentities = [];

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $checkInDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $checkOutDate = null;

    #[ORM\Column(length: 5)]
    private string $checkOutTime = '12:00';

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $returnTransport = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $extensionRequested = null;

    #[ORM\Column(nullable: true)]
    private ?bool $visitedMarrakechBefore = null;

    #[ORM\Column]
    private bool $noUnregisteredGuestsAccepted = false;

    #[ORM\Column]
    private bool $noDualNationalityAccepted = false;

    #[ORM\Column]
    private bool $rulesAccepted = false;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $signatureName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signatureData = null;

    #[ORM\Column]
    private \DateTimeImmutable $completedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->completedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): ?ApartmentReservation
    {
        return $this->reservation;
    }

    public function setReservation(?ApartmentReservation $reservation): self
    {
        if ($this->reservation === $reservation) {
            return $this;
        }

        $previousReservation = $this->reservation;
        $this->reservation = $reservation;

        if ($previousReservation instanceof ApartmentReservation && $previousReservation->getCheckin() === $this) {
            $previousReservation->setCheckin(null);
        }

        if ($reservation instanceof ApartmentReservation && $reservation->getCheckin() !== $this) {
            $reservation->setCheckin($this);
        }

        return $this;
    }

    public function getCompletedBy(): ?User
    {
        return $this->completedBy;
    }

    public function setCompletedBy(?User $completedBy): self
    {
        $this->completedBy = $completedBy;
        $this->touch();

        return $this;
    }

    public function getProcessedBy(): ?User
    {
        return $this->processedBy;
    }

    public function setProcessedBy(?User $processedBy): self
    {
        $this->processedBy = $processedBy;
        $this->touch();

        return $this;
    }

    public function getHostAgentName(): string
    {
        return $this->hostAgentName;
    }

    public function setHostAgentName(string $hostAgentName): self
    {
        $this->hostAgentName = $hostAgentName;
        $this->touch();

        return $this;
    }

    public function getHostAgentPhone(): ?string
    {
        return $this->hostAgentPhone;
    }

    public function setHostAgentPhone(?string $hostAgentPhone): self
    {
        $this->hostAgentPhone = $hostAgentPhone;
        $this->touch();

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

    public function getGuestWhatsappNumber(): ?string
    {
        return $this->guestWhatsappNumber;
    }

    public function setGuestWhatsappNumber(?string $guestWhatsappNumber): self
    {
        $this->guestWhatsappNumber = $guestWhatsappNumber;
        $this->touch();

        return $this;
    }

    public function getApartmentName(): string
    {
        return $this->apartmentName;
    }

    public function setApartmentName(string $apartmentName): self
    {
        $this->apartmentName = $apartmentName;
        $this->touch();

        return $this;
    }

    public function getApartmentAddress(): string
    {
        return $this->apartmentAddress;
    }

    public function setApartmentAddress(string $apartmentAddress): self
    {
        $this->apartmentAddress = $apartmentAddress;
        $this->touch();

        return $this;
    }

    public function snapshotFromReservation(ApartmentReservation $reservation): self
    {
        $apartment = $reservation->getApartment();

        $this
            ->setGuestName($reservation->getGuestName())
            ->setGuestWhatsappNumber($reservation->getGuestWhatsappNumber());

        if ($apartment !== null) {
            $this
                ->setApartmentName($apartment->getName())
                ->setApartmentAddress($apartment->getFullAddress());
        }

        return $this;
    }

    public function getGuestCount(): int
    {
        return $this->guestCount;
    }

    public function setGuestCount(int $guestCount): self
    {
        $this->guestCount = max(1, $guestCount);
        $this->touch();

        return $this;
    }

    /**
     * @return list<array{name: string, identityNumber: string}>
     */
    public function getGuestIdentities(): array
    {
        return $this->guestIdentities;
    }

    /**
     * @param list<array{name: string, identityNumber: string}> $guestIdentities
     */
    public function setGuestIdentities(array $guestIdentities): self
    {
        $this->guestIdentities = array_values($guestIdentities);
        $this->touch();

        return $this;
    }

    public function getCheckInDate(): ?\DateTimeImmutable
    {
        return $this->checkInDate;
    }

    public function setCheckInDate(?\DateTimeImmutable $checkInDate): self
    {
        $this->checkInDate = $checkInDate;
        $this->touch();

        return $this;
    }

    public function getCheckOutDate(): ?\DateTimeImmutable
    {
        return $this->checkOutDate;
    }

    public function setCheckOutDate(?\DateTimeImmutable $checkOutDate): self
    {
        $this->checkOutDate = $checkOutDate;
        $this->touch();

        return $this;
    }

    public function getCheckOutTime(): string
    {
        return $this->checkOutTime;
    }

    public function setCheckOutTime(string $checkOutTime): self
    {
        $this->checkOutTime = $checkOutTime;
        $this->touch();

        return $this;
    }

    public function getReturnTransport(): ?string
    {
        return $this->returnTransport;
    }

    public function setReturnTransport(?string $returnTransport): self
    {
        $this->returnTransport = $returnTransport;
        $this->touch();

        return $this;
    }

    public function getExtensionRequested(): ?string
    {
        return $this->extensionRequested;
    }

    public function setExtensionRequested(?string $extensionRequested): self
    {
        $this->extensionRequested = $extensionRequested;
        $this->touch();

        return $this;
    }

    public function getVisitedMarrakechBefore(): ?bool
    {
        return $this->visitedMarrakechBefore;
    }

    public function setVisitedMarrakechBefore(?bool $visitedMarrakechBefore): self
    {
        $this->visitedMarrakechBefore = $visitedMarrakechBefore;
        $this->touch();

        return $this;
    }

    public function hasAcceptedNoUnregisteredGuests(): bool
    {
        return $this->noUnregisteredGuestsAccepted;
    }

    public function setNoUnregisteredGuestsAccepted(bool $noUnregisteredGuestsAccepted): self
    {
        $this->noUnregisteredGuestsAccepted = $noUnregisteredGuestsAccepted;
        $this->touch();

        return $this;
    }

    public function hasAcceptedNoDualNationality(): bool
    {
        return $this->noDualNationalityAccepted;
    }

    public function setNoDualNationalityAccepted(bool $noDualNationalityAccepted): self
    {
        $this->noDualNationalityAccepted = $noDualNationalityAccepted;
        $this->touch();

        return $this;
    }

    public function hasAcceptedRules(): bool
    {
        return $this->rulesAccepted;
    }

    public function setRulesAccepted(bool $rulesAccepted): self
    {
        $this->rulesAccepted = $rulesAccepted;
        $this->touch();

        return $this;
    }

    public function getSignatureName(): ?string
    {
        return $this->signatureName;
    }

    public function setSignatureName(?string $signatureName): self
    {
        $this->signatureName = $signatureName;
        $this->touch();

        return $this;
    }

    public function getSignatureData(): ?string
    {
        return $this->signatureData;
    }

    public function setSignatureData(?string $signatureData): self
    {
        $this->signatureData = $signatureData;
        $this->touch();

        return $this;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;
        $this->touch();

        return $this;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?\DateTimeImmutable $processedAt): self
    {
        $this->processedAt = $processedAt;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isProcessed(): bool
    {
        return $this->processedAt instanceof \DateTimeImmutable;
    }

    public function getVisitedMarrakechLabel(): string
    {
        return match ($this->visitedMarrakechBefore) {
            true => 'Oui',
            false => 'Non',
            default => 'Non renseigné',
        };
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
