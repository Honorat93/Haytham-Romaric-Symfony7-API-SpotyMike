<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Artist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use App\Repository\UserRepository;
use App\Repository\ArtistRepository;
use App\Entity\Label;


class ArtistController extends AbstractController
{
    private $entityManager;
    private $artistRepository;
    private $serializer;
    private $userRepository;
    private $jwtManager;

    public function __construct(
        EntityManagerInterface $entityManager,
        SerializerInterface $serializer,
        JWTTokenManagerInterface $jwtManager,
        UserRepository $userRepository,
        ArtistRepository $artistRepository
    ) {
        $this->entityManager = $entityManager;
        $this->artistRepository = $artistRepository;
        $this->serializer = $serializer;
        $this->jwtManager = $jwtManager;
        $this->userRepository = $userRepository;
    }

    #[Route('/artist', name: 'create_artist', methods: ['POST'])]
    public function createArtist(Request $request): JsonResponse
    {
        try {

            $currentUser = $this->getUser()->getUserIdentifier();
            $user = $this->userRepository->findOneBy(['email' => $currentUser]);

            $fullname = $request->request->get('fullname');
            $idLabel = $request->request->get('label');
            $description = $request->request->has('description') ? $request->request->get('description') : null;
            $User_idUser = $request->request->get('id');

            if ($fullname === null || $idLabel === null) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "L'id du label et le fullname sont obligatoires.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if (!preg_match('/^[0-9]+$/', $idLabel)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le format de l'id du label est invalide.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $userBirthdate = $user->getBirth();
            $age = $userBirthdate->diff(new \DateTime())->y;
            if ($age < 16) {
                return $this->json(['error' => 'L\'âge de l\'utilisateur ne permet pas (16 ans)'], Response::HTTP_BAD_REQUEST);
            }
            if ($user->getArtist() !== null) {
                return new JsonResponse(
                    [
                        'error' => "true",
                        'message' => 'Un utilisateur ne peut gérer qu\'un seul compte artiste. Veuillez supprimer le compte existant pour en créer un nouveau.'
                    ],
                    Response::HTTP_FORBIDDEN
                );
            }

            $artist = $this->artistRepository->findOneBy(['fullname' => $fullname]);
            if ($artist) {
                return new JsonResponse(
                    [
                        'error' => "true",
                        'message' => 'Le nom d\'artiste déjà pris. Veuillez en choisir un autre.'
                    ],
                    Response::HTTP_CONFLICT
                );
            }

            $label = $this->entityManager->getRepository(Label::class)->findOneBy(['idLabel' => $idLabel]);

            if (!$label) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le label n'existe pas.",
                ], JsonResponse::HTTP_NOT_FOUND);
            }


            $artist = new Artist();
            
            $artist->setUserIdUser($user);
            $artist->setFullname($fullname);
            $artist->setLabel($label);

            

            // Persister et sauvegarder l'artiste
            $this->entityManager->persist($artist);
            $this->entityManager->flush();

            // Retourner une réponse JSON avec un message de succès
            return new JsonResponse([
                'success' => true,
                'message' => 'Votre compte d\'artist a été crée avec succès. Bienvenue dans notre communauté d\'artistes!',
                'artist_id' => $artist->getId(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
    
  /*  #[Route('/artist', name: 'app_artist_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $artists = $this->artistRepository->findAll();

        return $this->json(['artists' => $artists]);
    } 
    
    
    #[Route('/artist/{id}/detail', name: 'app_artist_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $artist = $this->artistRepository->find($id);

        if (!$artist) {
            return $this->json(['erreur' => 'On ne trouve pas artiste'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'artist' => [
                'id' => $artist->getId(),
                'fullname' => $artist->getFullname(),
                'label' => $artist->getLabel(),
                'description' => $artist->getDescription(),
            ]
        ]);
    }

    #[Route('/artist/{id}/update', name: 'app_artist_update', methods: ['PUT'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $artist = $this->artistRepository->find($id);

        if (!$artist) {
            return $this->json(['erreur' => 'On ne trouve pas artiste'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['fullname'])) {
            $fullname = $data['fullname'];
            if (!preg_match('/^[a-zA-Z\s]+$/', $fullname)) {
                return $this->json(['erreur' => 'Le format du nom est invalide'], Response::HTTP_BAD_REQUEST);
            }
            $artist->setFullname($fullname);
        }

        if (isset($data['label'])) {
            $artist->setLabel($data['label']);
        }

        if (isset($data['description'])) {
            $artist->setDescription($data['description']);
        }

        $this->entityManager->flush();

        return $this->json(['artist' => $artist]);
    }

    #[Route('/artist/{id}/delete', name: 'app_artist_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $artist = $this->artistRepository->find($id);

        if (!$artist) {
            return $this->json(['erreur' => 'On ne trouve pas artiste'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($artist);
        $this->entityManager->flush();

        return $this->json(['message' => 'Artiste supprimé']);
    }*/