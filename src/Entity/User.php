<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 120)]
    private string $fullName = '';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $canManageAnomalyWorkflow = false;

    /**
     * @var Collection<int, Apartment>
     */
    #[ORM\ManyToMany(targetEntity: Apartment::class, mappedBy: 'assignedEmployees')]
    private Collection $assignedApartments;

    public function __construct()
    {
        $this->assignedApartments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower($email);

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): self
    {
        $this->fullName = $fullName;

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

    public function canManageAnomalyWorkflow(): bool
    {
        return $this->canManageAnomalyWorkflow;
    }

    public function setCanManageAnomalyWorkflow(bool $canManageAnomalyWorkflow): self
    {
        $this->canManageAnomalyWorkflow = $canManageAnomalyWorkflow;

        return $this;
    }

    /**
     * @return Collection<int, Apartment>
     */
    public function getAssignedApartments(): Collection
    {
        return $this->assignedApartments;
    }

    public function addAssignedApartment(Apartment $apartment): self
    {
        if (!$this->assignedApartments->contains($apartment)) {
            $this->assignedApartments->add($apartment);
            $apartment->addAssignedEmployee($this);
        }

        return $this;
    }

    public function removeAssignedApartment(Apartment $apartment): self
    {
        if ($this->assignedApartments->removeElement($apartment)) {
            $apartment->removeAssignedEmployee($this);
        }

        return $this;
    }
}
