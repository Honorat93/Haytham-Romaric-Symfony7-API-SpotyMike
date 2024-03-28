<?php

namespace App\Controller;

use App\Entity\Album;
use App\Entity\Artist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AlbumController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/album', name: 'app_album', methods :'GET')]
    public function index(): JsonResponse
    {
        $albumRepository = $this->entityManager->getRepository(Album::class);
        $albums = $albumRepository->findAll();
        return $this->json(['albums' => $albums ]);
    }

    #[Route('/album/add', name: 'app_album_add', methods: ['POST'])]
public function add(Request $request, ValidatorInterface $validator): JsonResponse
{
    $data = json_decode($request->getContent(), true);

    $requiredFields = ['fullname', 'nom', 'categ', 'cover', 'year', 'artistId'];
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            $missingFields[] = $field;
        }
    }
    if (!empty($missingFields)) {
        return $this->json(['erreur' => 'Veuillez remplir tous les champs : ' . implode(', ', $missingFields)], Response::HTTP_BAD_REQUEST);
    }

    $coverFormat = '/\.(jpeg|jpg|png)$/';
    if (!preg_match($coverFormat, $data['cover'])) {
        return $this->json(['erreur' => 'Le format de la couverture doit être une image (jpeg, jpg, png)'], Response::HTTP_BAD_REQUEST);
    }

    $album = new Album();
    $album->setIdAlbum($data['fullname'])
        ->setNom($data['nom'])
        ->setCateg($data['categ'])
        ->setCover($data['cover'])
        ->setYear($data['year']);

    $artist = $this->entityManager->getRepository(Artist::class)->find($data['artistId']);
    if (!$artist) {
        return $this->json(['erreur' => 'L\'artiste n\'a pas été trouvé'], Response::HTTP_NOT_FOUND);
    }

    $album->setArtistUserIdUser($artist);

    $errors = $validator->validate($album);
    if (count($errors) > 0) {
        $errorMessages = [];
        foreach ($errors as $error) {
            $errorMessages[] = $error->getMessage();
        }
        return $this->json(['erreur' => $errorMessages], Response::HTTP_BAD_REQUEST);
    }

    $this->entityManager->persist($album);
    $this->entityManager->flush();

    return $this->json(['album' => $album], Response::HTTP_CREATED);
}

    #[Route('/album/{id}/detail', name: 'app_album_detail', methods: 'GET')]
    public function detail(int $id): JsonResponse
    {
        $albumRepository = $this->entityManager->getRepository(Album::class);
        $album = $albumRepository->find($id);

        if (!$album) {
            return $this->json(['erreur' => 'l\'album n\'a pas ete trouve.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id' => $album->getId(),
            'idAlbum' => $album->getIdAlbum(),
            'nom' => $album->getNom(),
            'categ' => $album->getCateg(),
            'cover' => $album->getCover(),
            'year' => $album->getYear(),
            'artist' => [
                'id' => $album->getArtistUserIdUser()->getId(),
                'nom' => $album->getArtistUserIdUser()->getFullname(),
            ]
        ]);
    }

    #[Route('/album/{id}/delete', name: 'app_album_delete', methods: 'DELETE')]
    public function delete(int $id): JsonResponse
    {
        $albumRepository = $this->entityManager->getRepository(Album::class);
        $album = $albumRepository->find($id);
    
        if (!$album) {
            return $this->json(['erreur' => 'L\'album n\'existe pas'], Response::HTTP_NOT_FOUND);
        }
    
        $this->entityManager->remove($album);
        $this->entityManager->flush();
    
        return $this->json(['message' => 'L\'album a été supprimé']);
    }

    #[Route('/album/{id}/update', name: 'app_album_update', methods: 'PUT')]
    public function update(Request $request, int $id): JsonResponse
    {
        $albumRepository = $this->entityManager->getRepository(Album::class);
        $album = $albumRepository->find($id);

        if (!$album) {
            return $this->json(['erreur' => 'Album non trouve.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!empty($data['nom'])) {
            $album->setNom($data['nom']);
        }
        if (!empty($data['categ'])) {
            $album->setCateg($data['categ']);
        }
        if (!empty($data['cover'])) {
            $album->setCover($data['cover']);
        }
        if (!empty($data['year'])) {
            $album->setYear($data['year']);
        }

        $this->entityManager->flush();

        return $this->json(['album' => $album]);
    }
}
