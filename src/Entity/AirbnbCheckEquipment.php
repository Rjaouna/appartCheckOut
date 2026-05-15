<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AirbnbCheckEquipment
{
    public const IMPORTANCE_REQUIRED = 'required';
    public const IMPORTANCE_RECOMMENDED = 'recommended';
    public const IMPORTANCE_BONUS = 'bonus';

    public const STATUS_GOOD = 'good';
    public const STATUS_AVERAGE = 'average';
    public const STATUS_MISSING = 'missing';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'equipments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AirbnbCheckRoom $room = null;

    #[ORM\Column(length: 100)]
    private string $equipmentKey = '';

    #[ORM\Column(length: 150)]
    private string $name = '';

    #[ORM\Column(length: 120)]
    private string $category = '';

    #[ORM\Column(length: 80)]
    private string $icon = 'box-seam';

    #[ORM\Column(length: 30)]
    private string $importance = self::IMPORTANCE_REQUIRED;

    #[ORM\Column]
    private int $weight = 1;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $taskLabel = null;

    #[ORM\Column]
    private int $displayOrder = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoom(): ?AirbnbCheckRoom
    {
        return $this->room;
    }

    public function setRoom(?AirbnbCheckRoom $room): self
    {
        $this->room = $room;

        return $this;
    }

    public function getEquipmentKey(): string
    {
        return $this->equipmentKey;
    }

    public function setEquipmentKey(string $equipmentKey): self
    {
        $this->equipmentKey = $equipmentKey;

        return $this;
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function getImportance(): string
    {
        return $this->importance;
    }

    public function setImportance(string $importance): self
    {
        if (!in_array($importance, [self::IMPORTANCE_REQUIRED, self::IMPORTANCE_RECOMMENDED, self::IMPORTANCE_BONUS], true)) {
            throw new \InvalidArgumentException('Importance Airbnb invalide.');
        }

        $this->importance = $importance;

        return $this;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): self
    {
        $this->weight = max(1, $weight);

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        if ($status !== null && !in_array($status, [self::STATUS_GOOD, self::STATUS_AVERAGE, self::STATUS_MISSING, self::STATUS_NOT_APPLICABLE], true)) {
            throw new \InvalidArgumentException('État d’équipement invalide.');
        }

        $this->status = $status;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $note = $note !== null ? trim($note) : null;
        $this->note = $note === '' ? null : $note;

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

    public function getTaskLabel(): ?string
    {
        return $this->taskLabel;
    }

    public function setTaskLabel(?string $taskLabel): self
    {
        $taskLabel = $taskLabel !== null ? trim($taskLabel) : null;
        $this->taskLabel = $taskLabel === '' ? null : $taskLabel;

        return $this;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;

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

    public function getImportanceLabel(): string
    {
        return match ($this->importance) {
            self::IMPORTANCE_REQUIRED => 'Obligatoire',
            self::IMPORTANCE_RECOMMENDED => 'Recommandé',
            self::IMPORTANCE_BONUS => 'Bonus',
            default => 'Équipement',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_GOOD => 'Présent et en bon état',
            self::STATUS_AVERAGE => 'Présent mais état moyen',
            self::STATUS_MISSING => 'Absent',
            self::STATUS_NOT_APPLICABLE => 'Non applicable',
            default => 'À vérifier',
        };
    }

    public function getStatusClass(): string
    {
        return match ($this->status) {
            self::STATUS_GOOD => 'is-good',
            self::STATUS_AVERAGE => 'is-average',
            self::STATUS_MISSING => 'is-missing',
            self::STATUS_NOT_APPLICABLE => 'is-na',
            default => 'is-pending',
        };
    }

    public function isIncludedInScore(): bool
    {
        return $this->status !== self::STATUS_NOT_APPLICABLE;
    }

    public function getWeightedScore(): float
    {
        return match ($this->status) {
            self::STATUS_GOOD => $this->weight,
            self::STATUS_AVERAGE => $this->weight * 0.5,
            default => 0.0,
        };
    }

    public function isIssue(): bool
    {
        return in_array($this->status, [self::STATUS_AVERAGE, self::STATUS_MISSING], true);
    }
}
