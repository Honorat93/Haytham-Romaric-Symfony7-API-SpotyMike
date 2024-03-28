<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Song;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Response;



class SongController extends AbstractController
{
    private $entityManager;
    private $songRepository;
    private $serializer;

    public function __construct(EntityManagerInterface $entityManager, SerializerInterface $serializer)
    {
        $this->entityManager = $entityManager;
        $this->songRepository = $entityManager->getRepository(Song::class);
        $this->serializer = $serializer;
    }

    #[Route('/song/{id}', name: 'get_song', methods: 'GET')]
    public function getSong(int $id): JsonResponse
    {
        try{
        $song = $this->songRepository->find($id);

        if (!$song) {
            throw $this->createNotFoundException('Sons non trouvé');
        }

        $serializedSong = $this->serializer->serialize($song, 'json');

        return $this->json([
            'message' => 'Sons récupérée',
            'song' => json_decode($serializedSong, true),
        ]);
    } catch (\Exception $e) {
        return new JsonResponse([
            'error' => 'Error: ' . $e->getMessage(),
        ], JsonResponse::HTTP_NOT_FOUND);
    }

       
    }

    #[Route('/song/add', name: 'create_song', methods: 'POST')]
    public function createSong(Request $request): JsonResponse
    {
        try{
            $idSong = $request->request->get('idSong');
            $title = $request->request->get('title');
            $url = $request->request->get('url');
            $cover = $request->request->get('cover');
            $visibility = $request->request->get('visibility');

            if (!$idSong || !$title || !$url || !$cover || !$visibility) {
                throw new \Exception('Manque des données');
            }

            $existingSong = $this->songRepository->findOneBy(['idSong' => $idSong]);
            if ($existingSong) {
                throw new \Exception('Sons existe déjà');
            }

            $song = new Song();
            $song->setIdSong($idSong);
            $song->setTitle($title);
            $song->setUrl($url);
            $song->setCover($cover);
            $song->setVisibility($visibility);
            $song->setCreateAt(new \DateTimeImmutable());
            $this->entityManager->persist($song);
            $this->entityManager->flush();

            $serializedSong = $this->serializer->serialize($song, 'json');
            $song = $this->songRepository->findOneBy(['idSong' => $idSong]);
            return $this->json([
                'message' => 'Sons crée',
                'song' => json_decode($serializedSong, true),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/song/edit', name: 'edit_song', methods: 'PUT')]
    public function editPlaylist(Request $request): JsonResponse
    {
        try {
            $idSong = $request->request->get('idSong');
            $title = $request->request->get('title');
            $url = $request->request->get('url');
            $cover = $request->request->get('cover');
            $visibility = $request->request->get('visibility');

            if ($idSong === null || $title === null || $url === null || $cover === null || $visibility === null) {
                throw new \Exception('Manque des données');
            }

            $song = $this->songRepository->findOneBy(["idSong" => $idSong]);
            if (!$song) {
                throw new \Exception('Sons non trouvé');
            }

            $song = $this->songRepository->findOneBy(["idSong" => $idSong]);
            $song->setTitle($title);
            $song->setUrl($url);
            $song->setCover($cover);
            $song->setVisibility($visibility);

            $this->entityManager->persist($song);
            $this->entityManager->flush();

            $serializedPlaylist = $this->serializer->serialize($song, 'json');

            return $this->json([
                'message' => 'Sons modifiée',
                'playlist' => json_decode($serializedPlaylist, true),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

    }

    #[Route('/song/delete/{id}', name: 'delete_song', methods: 'DELETE')]
    public function deleteSong(int $id): JsonResponse
    {
        try {
            $song = $this->songRepository->find($id);

            if (!$song) {
                throw new \Exception('Sons non trouvée');
            }
            
            $this->entityManager->remove($song);
            $this->entityManager->flush();

            return $this->json([
                'message' => 'Sons supprimée',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }


}
