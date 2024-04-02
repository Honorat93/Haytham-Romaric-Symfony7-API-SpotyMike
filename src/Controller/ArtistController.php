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
      /*$jwtToken = $request->headers->get('Authorization');
        
        // Vérifier si le token JWT est présent
        if (!$jwtToken) {
            return $this->json(['error' => 'Token non trouvé'], Response::HTTP_UNAUTHORIZED);
        }

        // Supprimer le préfixe "Bearer " du token JWT
        $jwtToken = str_replace('Bearer ', '', $jwtToken);

        // Vérifier si le token JWT est valide
        try {
            $decodedToken = $this->jwtManager->parse($jwtToken);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Votre token n\'est pas correct'], Response::HTTP_UNAUTHORIZED);
        }*/
        
        

        // Récupérer les données de la requête
    $firstname = $request->request->get('firstname');
    $fullname = $request->request->get('fullname');
    $lastname = $request->request->get('lastname');
    $label = $request->request->get('label');
    $description = $request->request->get('description');
    $User_idUser = $request->request->get('id_user');

    // Récupérer l'utilisateur
    $user = $this->entityManager->getRepository(User::class)->find($User_idUser);

   // Vérifier si les champs requis sont présents dans la requête
   $requiredFields = ['fullname', 'label'];
   foreach ($requiredFields as $field) {
    if (!$request->request->get($field)) {
        return $this->json(['error' => 'Une ou plusieurs données sont manquantes'], Response::HTTP_BAD_REQUEST);
    }
}

   // Vérifier la validité de l'utilisateur
$user = $this->entityManager->getRepository(User::class)->findOneBy([
    'firstname' => $firstname,
    'lastname' => $lastname,
]);

if (!$user) {
    return $this->json(['error' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
}

// Vérifier si l'utilisateur a au moins 16 ans
$userBirthdate = $user->getBirth();
if (!$userBirthdate instanceof \DateTimeInterface) {
    return $this->json(['error' => 'La date de naissance de l\'utilisateur n\'est pas renseignée'], Response::HTTP_BAD_REQUEST);
}
$age = $userBirthdate->diff(new \DateTime())->y;
if ($age < 16) {
    return $this->json(['error' => 'L\'âge de l\'utilisateur ne permet pas (16 ans)'], Response::HTTP_BAD_REQUEST);
}

// Vérifier que le fullname contient uniquement des lettres et des espaces
if (!preg_match('/^[a-zA-Z\s]+$/', $fullname)) {
    return $this->json(['error' => 'Le format du nom complet est invalide'], Response::HTTP_BAD_REQUEST);
}

// Extraire le prénom et le nom de famille du fullname
$names = explode(' ', $fullname);
if (count($names) != 2) {
    return $this->json(['error' => 'Le nom complet doit contenir un prénom et un nom de famille séparés par un espace'], Response::HTTP_BAD_REQUEST);
}
$firstname = $names[0];
$lastname = $names[1];

// Vérifier que le firstname et le lastname ne contiennent que des lettres
if (!preg_match('/^[a-zA-Z\s]+$/', $firstname) || !preg_match('/^[a-zA-Z\s]+$/', $lastname)) {
    return $this->json(['error' => 'Le format du prénom ou du nom est invalide'], Response::HTTP_BAD_REQUEST);
}

// Vérifier s'il existe déjà un compte artiste pour cet utilisateur
$existingArtistAccount = $this->artistRepository->findOneBy(['User_idUser' => $user]);
if ($existingArtistAccount) {
    return $this->json(['error' => 'Un compte utilisant est déjà un compte artiste'], Response::HTTP_CONFLICT);
}


        // Vérifier s'il existe déjà un artiste avec le même nom
        $existingArtistName = $this->artistRepository->findOneBy(['fullname' => $fullname]);
        if ($existingArtistName) {
            return $this->json(['error' => 'Un compte avec ce nom d\'artiste est déjà enregistré'], Response::HTTP_CONFLICT);
        }




        // Créer une nouvelle instance de l'artiste
        $artist = new Artist();
        $artist->setUserIdUser($user);
        $artist->setFirstname($firstname);
        $artist->setLastname($lastname);
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
            'artist' => json_decode($serializedArtist, true),
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