<?php

namespace App\Entity;

use App\Repository\LabelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LabelRepository::class)]
class Label
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $idLabel = null;

    #[ORM\OneToMany(targetEntity: Artist::class, mappedBy: 'label')]
    private Collection $artist;

    public function __construct()
    {
        $this->artist = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getIdLabel(): ?string
    {
        return $this->idLabel;
    }

    public function setIdLabel(string $idLabel): static
    {
        $this->idLabel = $idLabel;

        return $this;
    }

    /**
     * @return Collection<int, Artist>
     */
    public function getArtist(): Collection
    {
        return $this->artist;
    }

    public function addArtist(Artist $artist): static
    {
        if (!$this->artist->contains($artist)) {
            $this->artist->add($artist);
            $artist->setLabel($this);
        }

        return $this;
    }

    public function removeArtist(Artist $artist): static
    {
        if ($this->artist->removeElement($artist)) {
            $artist->setLabel(null);
        }

        return $this;
    }
    
}
