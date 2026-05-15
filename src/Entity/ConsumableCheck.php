<?php

namespace App\Entity;

use App\Enum\ConsumableCheckStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'consumable_check')]
#[ORM\UniqueConstraint(name: 'UNIQ_CONSUMABLE_CHECK_CHECKOUT_ITEM', columns: ['checkout_id', 'consumable_item_id'])]
class ConsumableCheck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Checkout $checkout = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ConsumableItem $consumableItem = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Apartment $apartment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $checkedBy = null;

    #[ORM\Column(enumType: ConsumableCheckStatus::class)]
    private ConsumableCheckStatus $status = ConsumableCheckStatus::Ok;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(nullable: true)]
    private ?int $quantity = null;

    #[ORM\Column]
    private \DateTimeImmutable $checkedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $restockedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $restockedBy = null;

    public function __construct()
    {
        $this->checkedAt = new \DateTimeImmutable();
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

    public function getConsumableItem(): ?ConsumableItem
    {
        return $this->consumableItem;
    }

    public function setConsumableItem(?ConsumableItem $consumableItem): self
    {
        $this->consumableItem = $consumableItem;

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

    public function getCheckedBy(): ?User
    {
        return $this->checkedBy;
    }

    public function setCheckedBy(?User $checkedBy): self
    {
        $this->checkedBy = $checkedBy;

        return $this;
    }

    public function getStatus(): ConsumableCheckStatus
    {
        return $this->status;
    }

    public function setStatus(ConsumableCheckStatus $status): self
    {
        $this->status = $status;
        $this->restockedAt = null;
        $this->restockedBy = null;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $note = $note !== null ? trim($note) : null;
        $this->note = $note !== '' ? $note : null;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): self
    {
        $this->quantity = $quantity !== null ? max(0, $quantity) : null;

        return $this;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function setCheckedAt(\DateTimeImmutable $checkedAt): self
    {
        $this->checkedAt = $checkedAt;

        return $this;
    }

    public function getRestockedAt(): ?\DateTimeImmutable
    {
        return $this->restockedAt;
    }

    public function getRestockedBy(): ?User
    {
        return $this->restockedBy;
    }

    public function markRestocked(User $user): self
    {
        $this->restockedAt = new \DateTimeImmutable();
        $this->restockedBy = $user;

        return $this;
    }

    public function isRestockOpen(): bool
    {
        return $this->status->requiresRestock() && $this->restockedAt === null;
    }
}
