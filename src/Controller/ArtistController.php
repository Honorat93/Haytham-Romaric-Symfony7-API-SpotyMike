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

class ArtistController extends AbstractController
{
    private $entityManager;
    private $artistRepository;
    private $serializer;
    private $jwtManager;

    public function __construct(EntityManagerInterface $entityManager, SerializerInterface $serializer, JWTTokenManagerInterface $jwtManager)
    {
        $this->entityManager = $entityManager;
        $this->artistRepository = $entityManager->getRepository(Artist::class);
        $this->serializer = $serializer;
        $this->jwtManager = $jwtManager;
    }

    #[Route('/artist/creation', name: 'app_artist_creation', methods: ['POST'])]
    public function creation(Request $request, ValidatorInterface $validator): JsonResponse
    {
    // Récupérer le token JWT de l'en-tête Authorization
    $jwtToken = $request->headers->get('Authorization');
    
    // Vérifier si le token JWT est présent
    if (!$jwtToken) {
        return $this->json(['error' => 'Token non trouvé'], Response::HTTP_UNAUTHORIZED);
    }

    // Supprimer le préfixe "Bearer " du token JWT
    $jwtToken = str_replace('Bearer ', '', $jwtToken);

    // Vérifier si le token JWT est valide
    try {
        $decodedToken = $this->jwtManager->parse($jwtToken);
    } catch (JWTException $e) {
        return $this->json(['error' => 'Votre token n\'est pas correct'], Response::HTTP_UNAUTHORIZED);
    }
      // Récupérer l'utilisateur à partir du token JWT
       $user = $this->getUser();
        
       

        // Récupérer les données de la requête
    $fullname = $request->request->get('fullname');
    $label = $request->request->get('label');
    $description = $request->request->get('description');
    $User_idUser = $request->request->get('id');

       // Vérifier si les champs requis sont présents dans la requête
   $requiredFields = ['fullname', 'label'];
   foreach ($requiredFields as $field) {
    if (!$request->request->get($field)) {
        return $this->json(['error' => 'Une ou plusieurs données sont manquantes'], Response::HTTP_BAD_REQUEST);
    }

    // Vérifier la taille du fullname et du label
if (strlen($fullname) > 90 || strlen($label) > 90) {
    return $this->json(['error' => 'Une ou plusieurs données sont érronnées'], Response::HTTP_CONFLICT);
}

// Vérifier que le fullname contient uniquement des lettres et des espaces
if (!preg_match('/^[a-zA-Z\s]+$/', $fullname)) {
    return $this->json(['error' => 'Une ou plusieurs données sont érronnées'], Response::HTTP_CONFLICT);
}



}


// Vérifier si l'utilisateur a au moins 16 ans
$userBirthdate = $user->getBirth();
if (!$userBirthdate instanceof \DateTimeInterface) {
    return $this->json(['error' => 'La date de naissance de l\'utilisateur n\'est pas renseignée'], Response::HTTP_BAD_REQUEST);
}
$age = $userBirthdate->diff(new \DateTime())->y;
if ($age < 16) {
    return $this->json(['error' => 'L\'âge de l\'utilisateur ne permet pas (16 ans)'], Response::HTTP_NOT_ACCEPTABLE);
}

// Vérifier si l'utilisateur est déjà un artiste
if ($user->getArtist() !== null) {
    return $this->json(['error' => 'Un compte utilisant est déjà un compte artiste'], Response::HTTP_CONFLICT);
}


$existingArtistName = $this->artistRepository->findOneBy(['fullname' => $fullname]);
if ($existingArtistName) {
    return $this->json(['error' => 'Un compte avec ce nom d\'artiste est déjà enregistré'], Response::HTTP_CONFLICT);
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
        $serializedArtist = $this->serializer->serialize($artist, 'json', [AbstractNormalizer::IGNORED_ATTRIBUTES => ['__initializer__', '__cloner__', '__isInitialized__']]);
        
        // Retourner une réponse JSON avec un message de succès
        return new JsonResponse([

            'error' => false,
            'message' => 'Votre inscription a été bien prise en compte'
        ], Response::HTTP_CREATED);
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