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

    #[ORM\ManyToMany(targetEntity: artist::class, inversedBy: 'labels')]
    private Collection $artist_IdArtist;

    public function __construct()
    {
        $this->artist_IdArtist = new ArrayCollection();
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

    /**
     * @return Collection<int, artist>
     */
    public function getArtistIdArtist(): Collection
    {
        return $this->artist_IdArtist;
    }

    public function addArtistIdArtist(artist $artistIdArtist): static
    {
        if (!$this->artist_IdArtist->contains($artistIdArtist)) {
            $this->artist_IdArtist->add($artistIdArtist);
        }

        return $this;
    }

    public function removeArtistIdArtist(artist $artistIdArtist): static
    {
        $this->artist_IdArtist->removeElement($artistIdArtist);

        return $this;
    }

    
}
