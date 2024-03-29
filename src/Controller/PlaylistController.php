<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use App\Entity\Playlist;
use Symfony\Component\Serializer\SerializerInterface;



class PlaylistController extends AbstractController
{

    private $entityManager;
    private $playlistRepository;
    private $serializer;

    public function __construct(EntityManagerInterface $entityManager, SerializerInterface $serializer){
        $this->entityManager = $entityManager;
        $this->playlistRepository = $entityManager->getRepository(Playlist::class);
        $this->serializer = $serializer;
    }

    #[Route('/playlist/{id}', name: 'get_playlist', methods: 'GET')]
    public function getPlaylist(int $id): JsonResponse
    {
        try {
            $playlist = $this->playlistRepository->find($id);

            if (!$playlist) {
                throw new \Exception('Playlist non trouvée');
            }

            $serializedPlaylist = $this->serializer->serialize($playlist, 'json');

            return $this->json([
                'message' => 'Playlist récupérée',
                'playlist' => json_decode($serializedPlaylist, true),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }

    #[Route('/playlist/add', name: 'create_playlist', methods: 'POST')]
    public function createPlaylist(Request $request): JsonResponse
    {
        try {
            $idPlaylist = $request->request->get('idPlaylist');
            $title = $request->request->get('title');
            $public = $request->request->get('public');

            if ($idPlaylist === null || $title === null || $public === null) {
                throw new \Exception('Manque des données');
            }

            $existingPlaylist = $this->playlistRepository->findOneBy(["idPlaylist" => $idPlaylist]);
            if ($existingPlaylist) {
                throw new \Exception('Une playlist avec cet id existe déjà');
            }

            if (strlen($idPlaylist) > 90) {
                throw new \Exception('id trop long');
            }

            if (strlen($title) > 50) {
                throw new \Exception('titre trop long');
            }

            $playlist = new Playlist();
            $playlist->setIdPlaylist($idPlaylist);
            $playlist->setTitle($title);
            $playlist->setPublic($public);
            $playlist->setCreateAt(new \DateTimeImmutable());
            $playlist->setUpdateAt(new \DateTimeImmutable());
            $this->entityManager->persist($playlist);
            $this->entityManager->flush();

            $serializedPlaylist = $this->serializer->serialize($playlist, 'json');

            $playlist = $this->playlistRepository->findOneBy(["idPlaylist" => $idPlaylist]);
            return $this->json([
                'message' => 'Playlist crée',
                'playlist' => json_decode($serializedPlaylist, true),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
        
    }

    #[Route('/playlist/edit', name: 'edit_playlist', methods: 'PUT')]
    public function editPlaylist(Request $request): JsonResponse
    {
        try {
            $idPlaylist = $request->request->get('idPlaylist');
            $title = $request->request->get('title');
            $public = $request->request->get('public');

            if ($idPlaylist === null || $title === null || $public === null) {
                throw new \Exception('Manque des données');
            }

            if (strlen($idPlaylist) > 90) {
                throw new \Exception('id trop long');
            }

            if (strlen($title) > 50) {
                throw new \Exception('titre trop long');
            }

            $playlist = $this->playlistRepository->findOneBy(["idPlaylist" => $idPlaylist]);
            $playlist->setTitle($title);
            $playlist->setPublic($public);
            $playlist->setUpdateAt(new \DateTimeImmutable());
            $this->entityManager->persist($playlist);
            $this->entityManager->flush();

            $serializedPlaylist = $this->serializer->serialize($playlist, 'json');

            return $this->json([
                'message' => 'Playlist modifiée',
                'playlist' => json_decode($serializedPlaylist, true),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
        
    }

    #[Route('/playlist/delete/{id}', name: 'delete_playlist', methods: 'DELETE')]
    public function deletePlaylist(int $id): JsonResponse
    {
        try {
            $playlist = $this->playlistRepository->find($id);

            if (!$playlist) {
                throw new \Exception('Playlist non trouvée');
            }

            $this->entityManager->remove($playlist);
            $this->entityManager->flush();

            return $this->json([
                'message' => 'Playlist supprimée',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }

}
