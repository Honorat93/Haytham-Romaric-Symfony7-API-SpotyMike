<?php

namespace App\Entity;

use App\Repository\ArtistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Label;

#[ORM\Entity(repositoryClass: ArtistRepository::class)]
class Artist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'artist', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $User_idUser = null;

    #[ORM\Column(length: 90)]
    private ?string $fullname = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToMany(targetEntity: Song::class, mappedBy: 'Artist_idUser')]
    private Collection $songs;

    #[ORM\OneToMany(targetEntity: Album::class, mappedBy: 'artist_User_idUser')]
    private Collection $albums;

    #[ORM\ManyToOne(targetEntity: Label::class, inversedBy: 'artists')]
    #[ORM\JoinColumn(name: 'label_id', referencedColumnName: 'id', nullable: false)]
    private ?Label $label = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createAt = null;

    public function __construct()
    {
        $this->songs = new ArrayCollection();
        $this->albums = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdUser(): ?User
    {
        return $this->User_idUser;
    }

    public function setUserIdUser(User $User_idUser): static
    {
        $this->User_idUser = $User_idUser;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, Song>
     */
    public function getSongs(): Collection
    {
        return $this->songs;
    }

    public function addSong(Song $song): static
    {
        if (!$this->songs->contains($song)) {
            $this->songs->add($song);
            $song->addArtistIdUser($this);
        }

        return $this;
    }

    public function removeSong(Song $song): static
    {
        if ($this->songs->removeElement($song)) {
            $song->removeArtistIdUser($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Album>
     */
    public function getAlbums(): Collection
    {
        return $this->albums;
    }

    public function addAlbum(Album $album): static
    {
        if (!$this->albums->contains($album)) {
            $this->albums->add($album);
            $album->setArtistUserIdUser($this);
        }

        return $this;
    }

    public function removeAlbum(Album $album): static
    {
        if ($this->albums->removeElement($album)) {
            if ($album->getArtistUserIdUser() === $this) {
                $album->setArtistUserIdUser(null);
            }
        }

        return $this;
    }

    public function setFullname(string $fullname): void
    {
        $this->fullname = $fullname;
    }

    public function getFullname(): ?string
    {
        return $this->fullname;
    }

    public function getLabel(): ?Label
    {
        return $this->label;
    }

    public function setLabel(?Label $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getCreateAt(): ?\DateTimeImmutable
    {
        return $this->createAt;
    }

    public function setCreateAt(\DateTimeImmutable $createAt): static
    {
        $this->createAt = $createAt;

        return $this;
    }
}
