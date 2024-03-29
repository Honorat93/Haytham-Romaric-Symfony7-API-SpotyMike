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

class ArtistController extends AbstractController
{
    private $entityManager;
    private $artistRepository;
    private $serializer;

    public function __construct(EntityManagerInterface $entityManager, SerializerInterface $serializer)
    {
        $this->entityManager = $entityManager;
        $this->artistRepository = $entityManager->getRepository(Artist::class);
        $this->serializer = $serializer;
    }

    #[Route('/artist/creation', name: 'app_artist_creation', methods: ['POST'])]
    public function creation(Request $request, ValidatorInterface $validator, SerializerInterface $serializer): JsonResponse
    {
        // Récupérer les données de la requête
        $fullname = $request->request->get('fullname');
        $label = $request->request->get('label');
        $description = $request->request->get('description');
        $User_idUser = $request->request->get('id_user');

        // Vérifier si les champs requis sont présents dans la requête
        $requiredFields = ['fullname', 'label'];
        foreach ($requiredFields as $field) {
            if (!$request->request->get($field)) {
                return $this->json(['erreur' => 'Le champ ' . $field . ' est obligatoire'], Response::HTTP_BAD_REQUEST);
            }
        }

        // Récupérer l'utilisateur
        $user = $this->entityManager->getRepository(User::class)->find($User_idUser);

        if (!$user) {
            return $this->json(['erreur' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

           // Vérifier que le fullname ne contient que des lettres
    if (!preg_match('/^[a-zA-Z\s]+$/', $fullname)) {
        $errors[] = 'Une ou plusieurs données sont éronnées';
    }

    // Vérifier la longueur des données fullname et label
    if (mb_strlen($fullname) > 90) {
        $errors[] = 'Une ou plusieurs données sont éronnées';
    }
    if (mb_strlen($label) > 90) {
        $errors[] = 'Une ou plusieurs données sont éronnées';
    }

    if (!empty($errors)) {
        return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
    }

          // Vérifier si un compte artiste existe déjà pour cet utilisateur
          $existingArtist = $this->artistRepository->findOneBy(['User_idUser' => $user]);
          if ($existingArtist) {
              return $this->json(['erreur' => 'Un compte artiste est déjà associé à cet utilisateur'], Response::HTTP_CONFLICT);
          }

        // Vérifier s'il existe déjà un artiste avec le même nom
        $existingArtistName = $this->artistRepository->findOneBy(['fullname' => $fullname]);
        if ($existingArtistName !== null) {
            return $this->json(['erreur' => 'Un artiste avec le même fullname existe déjà'], Response::HTTP_CONFLICT);
        }

        // Valider le format du nom
        if (!preg_match('/^[a-zA-Z\s]+$/', $fullname)) {
            return $this->json(['erreur' => 'Le format du nom est invalide'], Response::HTTP_BAD_REQUEST);
        }

        // Créer une nouvelle instance de l'artiste
        $artist = new Artist();
        $artist->setUserIdUser($user);
        $artist->setFullname($fullname);
        $artist->setLabel($label);
        $artist->setDescription($description);

        // Valider l'entité Artist
        $errors = $validator->validate($artist);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        // Persister et sauvegarder l'artiste
        $this->entityManager->persist($artist);
        $this->entityManager->flush();

        // Sérialiser l'artiste pour la réponse
        $serializedArtist = $serializer->serialize($artist, 'json', [AbstractNormalizer::IGNORED_ATTRIBUTES => ['__initializer__', '__cloner__', '__isInitialized__']]);
        
        // Retourner une réponse JSON
        return new JsonResponse(['artist' => json_decode($serializedArtist, true)], Response::HTTP_CREATED);
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


