<?php

namespace App\Controller;

use App\Entity\Album;
use App\Repository\ArtistRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\AlbumRepository;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;


class AlbumController extends AbstractController
{
    private $entityManager;
    private $validator;
    private $artistRepository;
    private $albumRepository;
    private $userRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        ArtistRepository $artistRepository,
        AlbumRepository $albumRepository,
        UserRepository $userRepository
    )
    {
        $this->entityManager = $entityManager;
        $this->validator = $validator;
        $this->artistRepository = $artistRepository;
        $this->albumRepository = $albumRepository;
        $this->userRepository = $userRepository;
    }
    

    #[Route('/album/creation', name: 'create_album', methods: ['POST'])]
    public function createAlbum(Request $request): JsonResponse
    {
        try {
        // Récupérer l'utilisateur actuel
        $currentUser = $this->getUser();
        
        // Vérifier si un utilisateur est authentifié
        if ($currentUser !== null) {
            $currentUserIdentifier = $currentUser->getUserIdentifier();
            $user = $this->userRepository->findOneBy(['email' => $currentUserIdentifier]);
            
            // Votre logique pour l'utilisateur authentifié ...
        } else {
            // Si aucun utilisateur n'est authentifié
            return $this->json(['error' => true, 'message' => 'Authentification requise, vous devez être connecté pour effectuer cette action'], Response::HTTP_UNAUTHORIZED);
        }

     // Vérifier si l'utilisateur est autorisé à créer un album
     $artist = $user->getArtist();

            // Vérifier si l'utilisateur a le rôle 'ROLE_ARTIST'
            if (!$artist) {
                return $this->json([
                    'error' => true,
                    'message' => 'Accès refusé. Vous n\'avez pas l\'autorisation pour créer un album.'
                ], JsonResponse::HTTP_FORBIDDEN);
            }

            // Récupérer les données de la requête
            $nom = $request->request->get('nom');
            $categ = $request->request->get('categ');
            $cover = $request->request->get('cover');
            $year = $request->request->get('year');
            $visibility = $request->request->get('visibility');

            // Valider les données
            if (empty($nom) || empty($categ) || $visibility === null) {
                return $this->json([
                    'error' => true,
                    'message' => 'Tous les champs sont obligatoires.'
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            //$artistId = $request->request->get('artistId');

            $additionalParams = array_diff(array_keys($request->request->all()), ['nom', 'categ', 'cover', 'visibility']);
            if (!empty($additionalParams)) {
                return $this->json([
                    'error' => true,
                    'message' => 'Les paramètres fournis sont invalides. Veuillez vérifier les données soumises.'
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if ($year === null) {
                $year = 2024; 
            }

 
            if (empty($nom) || strlen($nom) < 1 || strlen($nom) > 90) {
                return $this->json(['error' => true, 'message' => "Erreur de validation des données"], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (!preg_match('/^[a-zA-Z0-9\s\'"!@#$%^&*()_+=\-,.?;:]+$/u', $nom)) {
                return $this->json(['error' => true, 'message' => "Erreur de validation des données"], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $validCategories = ['rock', 'pop', 'jazz', 'blues'];
            $invalidCategories = ['rap', 'r\'n\'b', 'gospel', 'soul country', 'hip hop', 'Mike'];

            if (in_array($categ, $invalidCategories)) {
                return $this->json(['error' => true, 'message' => "Les catégories ciblées sont invalides"], Response::HTTP_BAD_REQUEST);
            }

         /*   $explodeData = explode(',', $cover);
            if (count($explodeData) != 2 || base64_decode($explodeData[1]) === false) {
                return $this->json([
                    'error' => true,
                    'message' => "Le serveur ne peut pas décoder le contenu base64 en fichier binaire."
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $coverDecoded = base64_decode($explodeData[1]);

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $coverDecoded);
            finfo_close($finfo);

            if (!in_array($mimeType, ['image/jpeg', 'image/png'])) {
                return $this->json([
                    'error' => true,
                    'message' => "Erreur sur le format du fichier qui n'est pas pris en compte"
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $fileSize = strlen($coverDecoded);
            $minFileSize = 1 * 1024 * 1024; 
            $maxFileSize = 7 * 1024 * 1024; 

            if ($fileSize < $minFileSize || $fileSize > $maxFileSize) {
                return $this->json(['error' => true, 'message' => "Le fichier envoyé est trop ou pas assez volumineux. Vous devez respecter une taille entre 1MB et 7MB."], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }*/

            $visibility = intval($visibility);
            if ($visibility !== 0 && $visibility !== 1) {
                return $this->json(['error' => true, 'message' => "La valeur du champ visibility est invalide. Les valeurs autorisées sont 0 pour invisible, 1 pour visible"], Response::HTTP_BAD_REQUEST); // 400 Bad Request
            }

            $existingAlbum = $this->entityManager->getRepository(Album::class)->findOneBy(['nom' => $nom]);
            if ($existingAlbum) {
                return $this->json([
                    'error' => true,
                    'message' => 'Ce titre est déjà utilisé. Veuillez en choisir un autre.'
                ], Response::HTTP_CONFLICT); 
            }

            $album = new Album();
            $album->setNom($nom)
                ->setCateg($categ)
                ->setCover($cover)
                ->setYear($year)
                ->setVisibility($visibility);

            $errors = $this->validator->validate($album);
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }
                return $this->json(['error' => true, 'message' => $errorMessages], JsonResponse::HTTP_BAD_REQUEST);
            }

                    // Ajouter l'album à l'artiste en passant l'ID de l'utilisateur associé
        $artist->addAlbum($album, $user->getId());

            $this->entityManager->persist($album);
            $this->entityManager->flush();

            return $this->json([
                'error' => false,
                'message' => 'Album créé avec succès.',
                'album_id' => $album->getId() 
            ], JsonResponse::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => true, 'message' => $e->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }



    #[Route('/albums/search', name: 'search_albums', methods: ['GET'])]
    public function searchAlbums(Request $request, AlbumRepository $albumRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        try {

                    // Récupérer l'utilisateur actuel
        $currentUser = $this->getUser();
        
        // Vérifier si un utilisateur est authentifié
        if ($currentUser !== null) {
            $currentUserIdentifier = $currentUser->getUserIdentifier();
            $user = $this->userRepository->findOneBy(['email' => $currentUserIdentifier]);
            
            // Votre logique pour l'utilisateur authentifié ...
        } else {
            // Si aucun utilisateur n'est authentifié
            return $this->json(['error' => true, 'message' => 'Authentification requise, vous devez être connecté pour effectuer cette action'], Response::HTTP_UNAUTHORIZED);
        }
              
        // Récupérer les critères de recherche depuis la requête
        $criteria = [
            'limit' => $request->query->getInt('limit', 5),
            'categ' => $request->query->get('categ'),
            'featuring' => $request->query->get('featuring'),
            'year' => $request->query->get('year'),
            'label' => $request->query->get('label'),
            'fullname' => $request->query->get('fullname'),
            'nom' => $request->query->get('nom'),
            'currentPage' => $request->query->getInt('currentPage', 1)
        ];

        dump($criteria);

                    // Gérer les valeurs par défaut pour la pagination
                    $limit = $request->query->getInt('limit', 5);
                    $currentPage = $request->query->getInt('currentPage', 1);

            $additionalParams = array_diff(array_keys($request->query->all()), ['nom', 'categ', 'cover', 'visibility']);
            if (!empty($additionalParams)) {
                return $this->json([
                    'error' => true,
                    'message' => 'Les paramètres fournis sont invalides. Veuillez vérifier les données soumises.'
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
            

            if (isset($criteria['categ'])) {
                $validCategories = ['rock', 'pop', 'jazz', 'blues'];
                if (!in_array($criteria['categ'], $validCategories)) {
                    return $this->json([
                        'error' => true,
                        'message' => "Les catégories ciblées sont invalides"
                    ], JsonResponse::HTTP_BAD_REQUEST);
                }
            }
            

    
            if (isset($criteria['year']) && !is_numeric($criteria['year'])) {
                return $this->json([
                    'error' => true,
                    'message' => "L'année n'est pas valide."
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
            
            if (!is_numeric($currentPage) || $currentPage < 1 || !is_numeric($limit) || $limit < 1) {
                return $this->json([
                    'error' => true,
                    'message' => "Les paramètres de pagination sont invalides. Veuillez fournir des valeurs numériques valides."
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
    
$albums = $this->albumRepository->searchByCriteria(
    $limit,
    $criteria['categ'],
    $criteria['featuring'],
    $criteria['year'],
    $criteria['label'],
    $criteria['fullname'],
    $criteria['nom'],
    $currentPage
);

        // Construire les données des albums de manière similaire au code existant
        $albumsData = [];
        foreach ($albums as $album) {
            $songsData = [];
            foreach ($album->getSongIdSong() as $song) {
                $artistsData = [];
                foreach ($song->getArtists() as $artist) {
                    $artistsData[] = [
                        'firstname' => $artist->getUser()->getFirstName(),
                        'lastname' => $artist->getUser()->getLastName(),
                        'fullname' => $artist->getUser()->getFullName(),
                        'avatar' => $artist->getUser()->getAvatar(),
                        'follower' => $artist->getUser()->getFollower(),
                        'cover' => $artist->getUser()->getCover(),
                        'sexe' => $artist->getUser()->getSexe(),
                        'dateBirth' => $artist->getUser()->getDateBirth(),
                        'createdAt' => $artist->getUser()->getCreateAt()->format('Y-m-d'),
                    ];
                }

                $songsData[] = [
                    'id' => $song->getId(),
                    'title' => $song->getTitle(),
                    'cover' => $song->getCover(),
                    'createdAt' => $song->getCreatedAt()->format('Y-m-d'),
                    'artists' => $artistsData,
                ];
            }

            $albumsData[] = [
                'id' => $album->getId(),
                'nom' => $album->getNom(),
                'categ' => $album->getCateg(),
                'cover' => $album->getCover(),
                'year' => $album->getYear(),
                'songs' => $songsData,
                'artist' => [
                    'firstname' => $album->getArtistUserIdUser()->getUserIdUser()->getFirstName(),
                    'lastname' => $album->getArtistUserIdUser()->getUserIdUser()->getLastName(),
                    'fullname' => $album->getArtistUserIdUser()->getFullName(),
                    'follower' => $album->getArtistUserIdUser()->getFollower(),
                    'cover' => $album->getCover(),
                    'sexe' => $album->getArtistUserIdUser()->getUserIdUser()->getSexe(),
                    'dateBirth' => $album->getArtistUserIdUser()->getUserIdUser()->getBirth(),
                    'createdAt' => $album->getArtistUserIdUser()->getCreateAt()->format('Y-m-d'),
                ],
            ];
        }

        // Construction de la réponse avec les données des albums et les informations de pagination
        return $this->json([
            'error' => false,
            'albums' => $albumsData,
            'pagination' => [
                'currentPage' => $currentPage,
                'totalPages' => ceil(count($albums) / $limit),
                'totalAlbums' => count($albums),
            ]
        ]);
    } catch (\Exception $e) {
        return $this->json([
            'success' => false,
            'error' => 'Error: ' . $e->getMessage(),
        ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
    }
}
    
  /*  #[Route('/album/{id}', name: 'get_album', methods: ['GET'])]
    public function getAlbum(Request $request, int $id): JsonResponse
    {
        try {
            // Récupérer l'utilisateur actuel
            $currentUser = $this->getUser();
            
            // Vérifier si un utilisateur est authentifié
            if ($currentUser === null) {
                // Si aucun utilisateur n'est authentifié
                return $this->json([
                    'error' => true,
                    'message' => 'Authentification requise, vous devez être connecté pour effectuer cette action'
                ], Response::HTTP_UNAUTHORIZED);
            }
            
            // Récupération de l'utilisateur actuel
            $user = $this->userRepository->findOneBy(['email' => $currentUser->getUserIdentifier()]);
            
            $album = $this->albumRepository->find($id);
        
            if ($album === null) {
                return $this->json([
                    'error' => true,
                    'message' => "L'identifiant de l'album est requis."
                ], Response::HTTP_BAD_REQUEST);
            }
        
            // Vérifier si l'album est visible ou si l'utilisateur est le propriétaire
            if (!$album->getVisibility() && !$currentUser->isOwnerOfAlbum($album)) {
                return $this->json([
                    'error' => true,
                    'message' => 'Album non trouvé, vérifiez les informations fournies et réessayez',
                ], Response::HTTP_NOT_FOUND);
            }
        
            // Vérifier si l'album a été supprimé et si l'utilisateur est le propriétaire
            if ($album->isDeleted() && !$currentUser->isOwnerOfAlbum($album)) {
                return $this->json([
                    'error' => true,
                    'message' => 'Album non trouvé, vérifiez les informations fournies et réessayez',
                ], Response::HTTP_NOT_FOUND);
            }
    
            // Récupération des détails de chaque chanson de l'album
            $songs = [];
            foreach ($album->getSongIdSong() as $song) {
                $songDetails = [
                    'id' => $song->getId(),
                    'title' => $song->getTitle(),
                    'cover' => $song->getCover(),
                    'createdAt' => $song->getCreateAt(),
                    'featuring' => []
                ];
    
                // Récupération des détails de chaque artiste principal sur la chanson
                foreach ($song->getArtistIdUser() as $artist) {
                    $artistUser = $artist->getArtistUserIdUser();
                    if ($artistUser !== null) {
                        // Récupération des informations de l'artiste principal
                        $artistDetails = [
                            'id' => $artistUser->getId(),
                            'firstname' => $artistUser->getFirstName(),
                            'lastname' => $artistUser->getLastName(),
                            'fullname' => $artistUser->getFullName(),
                            'avatar' => $artistUser->getAvatar(),
                            'follower' => $artistUser->getFollower(),
                            'cover' => $artistUser->getCover(),
                            'sexe' => $artistUser->getSexe(),
                            'dateBirth' => $artistUser->getDateBirth(),
                            'createdAt' => $artistUser->getCreateAt()
                        ];
    
                        // Ajout des informations de l'artiste principal à la chanson
                        $songDetails['artist'] = $artistDetails;
                    }
                }
    
                // Récupération des détails de chaque artiste en featuring sur la chanson
                foreach ($song->getCollabSong() as $collabArtist) {
                    $collabArtistUser = $collabArtist->getArtistUserIdUser();
                    if ($collabArtistUser !== null) {
                        // Récupération des informations de l'artiste en featuring
                        $collabArtistDetails = [
                            'id' => $collabArtistUser->getId(),
                            'firstname' => $collabArtistUser->getFirstName(),
                            'lastname' => $collabArtistUser->getLastName(),
                            'fullname' => $collabArtistUser->getFullName(),
                            'avatar' => $collabArtistUser->getAvatar(),
                            'follower' => $collabArtistUser->getFollower(),
                            'cover' => $collabArtistUser->getCover(),
                            'sexe' => $collabArtistUser->getSexe(),
                            'dateBirth' => $collabArtistUser->getDateBirth(),
                            'createdAt' => $collabArtistUser->getCreateAt()
                        ];
    
                        // Ajout des informations de l'artiste en featuring à la chanson
                        $songDetails['featuring'][] = $collabArtistDetails;
                    }
                }
                
                $songs[] = $songDetails;
            }
    
            // Construction de la réponse avec les données de l'album et des chansons
            $albumData = [
                'id' => $album->getId(),
                'nom' => $album->getNom(),
                'categ' => $album->getCateg(),
                'label' => $album->getArtistUserIdUser()->getLabel()->getName(),
                'cover' => $album->getCover(),
                'year' => $album->getYear(),
                'createdAt' => $album->getCreatedAt(),
                'songs' => $songs,
            ];
    
            // Vérifier si l'album a un artiste associé
            $artist = $album->getArtistUserIdUser();
            if ($artist !== null) {
                $artistData = [
                    'firstname' => $user->getFirstName(),
                    'lastname' => $user->getLastName(),
                    'fullname' => $artist->getFullName(),
                    //'avatar' => $artist->getAvatar(),
                    'follower' => $artist->getFollower(),
                    'cover' => $album->getCover(),
                    'sexe' => $user->getSexe(),
                    'dateBirth' => $user->getBirth(),
                    'createdAt' => $artist->getCreateAt()
                ];
                $albumData['artist'] = $artistData;
            }
    
            return $this->json($albumData);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Error: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }*/
    


    #[Route('/album/{id}', name: 'app_album_update', methods: 'PUT')]
    public function updateAlbum(Request $request, int $id, SerializerInterface $serializer): JsonResponse
    {
        try {
                
        // Récupérer l'utilisateur actuel
        $currentUser = $this->getUser();
        
        // Vérifier si un utilisateur est authentifié
        if ($currentUser !== null) {
            $currentUserIdentifier = $currentUser->getUserIdentifier();
            $user = $this->userRepository->findOneBy(['email' => $currentUserIdentifier]);
            
            // Votre logique pour l'utilisateur authentifié ...
        } else {
            // Si aucun utilisateur n'est authentifié
            return $this->json(['error' => true, 'message' => 'Authentification requise, vous devez être connecté pour effectuer cette action'], Response::HTTP_UNAUTHORIZED);
        }
              
            // Récupérer l'album à mettre à jour depuis le référentiel
            $album = $this->albumRepository->find($id);

       // Vérifier si l'album existe
               if (!$album) {
                            return $this->json(['error' => true, 'message' => 'Aucun album trouvé correspoondant au nom fourni.'], JsonResponse::HTTP_NOT_FOUND);
                        }

             // Récupérer l'artiste associé à l'utilisateur
            $artist = $user->getArtist();

     // Vérifier si l'utilisateur a le rôle 'ROLE_ARTIST'
     if (!$artist) {
         return $this->json([
             'error' => true,
             'message' => 'Accès refusé. Vous n\'avez pas l\'autorisation pour accéder à cet album.'
         ], JsonResponse::HTTP_FORBIDDEN);
     }

             // Récupérer les données de la requête
             $nom = $request->request->get('nom');
             $categ = $request->request->get('categ');
             $cover = $request->request->get('cover');
             $year = $request->request->get('year');
             $visibility = $request->request->get('visibility');  


            $additionalParams = array_diff(array_keys($request->request->all()), ['nom', 'categ', 'cover', 'visibility']);
            if (!empty($additionalParams)) {
                return $this->json([
                    'error' => true,
                    'message' => 'Les paramètres fournis sont invalides. Veuillez vérifier les données soumises.'
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if ($year === null) {
                $year = 2024; 
            }

 
            if (empty($nom) || strlen($nom) < 1 || strlen($nom) > 90) {
                return $this->json(['error' => true, 'message' => "Erreur de validation des données"], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (!preg_match('/^[a-zA-Z0-9\s\'"!@#$%^&*()_+=\-,.?;:]+$/u', $nom)) {
                return $this->json(['error' => true, 'message' => "Erreur de validation des données"], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $validCategories = ['rock', 'pop', 'jazz', 'blues'];
            $invalidCategories = ['rap', 'r\'n\'b', 'gospel', 'soul country', 'hip hop', 'Mike'];

            if (in_array($categ, $invalidCategories)) {
                return $this->json(['error' => true, 'message' => "Les catégories ciblées sont invalides"], Response::HTTP_BAD_REQUEST);
            }

         /*   $explodeData = explode(',', $cover);
            if (count($explodeData) != 2 || base64_decode($explodeData[1]) === false) {
                return $this->json([
                    'error' => true,
                    'message' => "Le serveur ne peut pas décoder le contenu base64 en fichier binaire."
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $coverDecoded = base64_decode($explodeData[1]);

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $coverDecoded);
            finfo_close($finfo);

            if (!in_array($mimeType, ['image/jpeg', 'image/png'])) {
                return $this->json([
                    'error' => true,
                    'message' => "Erreur sur le format du fichier qui n'est pas pris en compte"
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $fileSize = strlen($coverDecoded);
            $minFileSize = 1 * 1024 * 1024; 
            $maxFileSize = 7 * 1024 * 1024; 

            if ($fileSize < $minFileSize || $fileSize > $maxFileSize) {
                return $this->json(['error' => true, 'message' => "Le fichier envoyé est trop ou pas assez volumineux. Vous devez respecter une taille entre 1MB et 7MB."], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }*/

            $visibility = intval($visibility);
            if ($visibility !== 0 && $visibility !== 1) {
                return $this->json(['error' => true, 'message' => "La valeur du champ visibility est invalide. Les valeurs autorisées sont 0 pour invisible, 1 pour visible"], Response::HTTP_BAD_REQUEST); // 400 Bad Request
            }

            $existingAlbum = $this->entityManager->getRepository(Album::class)->findOneBy(['nom' => $nom]);
            if ($existingAlbum) {
                return $this->json([
                    'error' => true,
                    'message' => 'Ce titre est déjà utilisé. Veuillez en choisir un autre.'
                ], Response::HTTP_CONFLICT); 
            }
// Vérifier si le champ "nom" est présent dans les données et le mettre à jour s'il a changé
if (isset($nom) && $nom !== $album->getNom()) {
    $album->setNom($nom);
}

// Vérifier si le champ "categ" est présent dans les données et le mettre à jour s'il a changé
if (isset($categ) && $categ !== $album->getCateg()) {
    $album->setCateg($categ);
}

// Vérifier si le champ "cover" est présent dans les données et le mettre à jour s'il a changé
if (isset($cover) && $cover !== $album->getCover()) {
    $album->setCover($cover);
}

// Vérifier si le champ "year" est présent dans les données et le mettre à jour s'il a changé
if (isset($year) && $year !== $album->getYear()) {
    $album->setYear($year);
}

// Vérifier si le champ "visibility" est présent dans les données et le mettre à jour s'il a changé
if (isset($visibility) && $visibility !== $album->getVisibility()) {
    $album->setVisibility($visibility);
}


            // Sauvegarder les changements dans la base de données
            $this->entityManager->flush();

            // Sérialiser l'album en utilisant le groupe "album"
            $data = $serializer->serialize($album, 'json', [AbstractNormalizer::GROUPS => ['album']]);

            // Retourner la réponse JSON avec l'album mis à jour
            return new JsonResponse(['error' => false, 'message' => 'Album mis à jour avec succès.']);
        } catch (\Exception $e) {
            return $this->json(['error' => true, 'message' => 'Une erreur est survenue lors de la mise à jour de l\'album : ' . $e->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
}
    
    
    #[Route('/albums', name: 'get_albums', methods: ['GET'])]
    public function getAllAlbums(Request $request): JsonResponse
    {
        try {
            // Récupérer l'utilisateur actuellement connecté
            $currentUser = $this->getUser()->getUserIdentifier();
    
            // Vérifier si un utilisateur est authentifié
            if ($currentUser === null) {
                return $this->json([
                    'error' => true,
                    'message' => 'Authentification requise, vous devez être connecté pour effectuer cette action'
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }
    
            // Récupérer la page actuelle et la limite par page
            $page = $request->query->get("currentPage", 1);
            $limit = $request->query->get("limit", 5);
    
            // Vérifier la validité des paramètres de pagination
            if (!is_numeric($page) || $page < 1 || !is_numeric($limit) || $limit < 1) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le paramètre de pagination est invalide. Veuillez fournir un numéro de page valide.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
    
            // Calculer l'offset pour la pagination
            $offset = ($page - 1) * $limit;
    
            // Récupérer le nombre total d'albums
            $totalAlbums = $this->albumRepository->count([]);
    
            // Récupérer les albums pour la page donnée
            $albums = $this->albumRepository->findBy([], null, $limit, $offset);
    
            // Itérer sur chaque album récupéré
            foreach ($albums as $album) {
                // Récupérer les détails de chaque chanson de l'album
                $songsData = [];
                foreach ($album->getSongIdSong() as $song) {
                    $artistsData = [];
                    foreach ($song->getArtists() as $artist) {
                        $artistsData[] = [
                            'firstname' => $artist->getUser()->getFirstName(),
                            'lastname' => $artist->getUser()->getLastName(),
                            'fullname' => $artist->getUser()->getFullName(),
                            'avatar' => $artist->getUser()->getAvatar(),
                            'follower' => $artist->getUser()->getFollower(),
                            'cover' => $artist->getUser()->getCover(),
                            'sexe' => $artist->getUser()->getSexe(),
                            'dateBirth' => $artist->getUser()->getDateBirth(),
                            'createdAt' => $artist->getUser()->getCreateAt()->format('Y-m-d'),
                        ];
                    }
    
                    // Ajouter les détails de chaque chanson
                    $songsData[] = [
                        'id' => $song->getId(),
                        'title' => $song->getTitle(),
                        'cover' => $song->getCover(),
                        'createdAt' => $song->getCreatedAt()->format('Y-m-d'),
                        'artists' => $artistsData,
                    ];
                }
    
                // Ajouter les détails de chaque album
                $albumsData[] = [
                    'id' => $album->getId(),
                    'nom' => $album->getNom(),
                    'categ' => $album->getCateg(),
                    'cover' => $album->getCover(),
                    'year' => $album->getYear(),
                    'songs' => $songsData,
                    'artist' => [
                        'firstname' => $album->getArtistUserIdUser()->getUserIdUser()->getFirstName(),
                        'lastname' => $album->getArtistUserIdUser()->getUserIdUser()->getLastName(),
                        'fullname' => $album->getArtistUserIdUser()->getFullName(),
                        //'avatar' => $album->getArtistUserIdUser()->getAvatar(),
                        'follower' => $album->getArtistUserIdUser()->getFollower(),
                        'cover' => $album->getCover(),
                        'sexe' => $album->getArtistUserIdUser()->getUserIdUser()->getSexe(),
                        'dateBirth' => $album->getArtistUserIdUser()->getUserIdUser()->getBirth(),
                        'createdAt' => $album->getArtistUserIdUser()->getCreateAt()->format('Y-m-d'),
                    ],
                ];
            }
    
            // Construction de la réponse avec les données des albums et les informations de pagination
            return $this->json([
                'error' => false,
                'albums' => $albumsData,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => ceil($totalAlbums / $limit),
                    'totalAlbums' => $totalAlbums,
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }



}    
   /* #[Route('/album/{id}/delete', name: 'app_album_delete', methods: 'DELETE')]
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
   /* #[Route('/album', name: 'app_album', methods :'GET')]
    public function index(): JsonResponse
    {
        $albumRepository = $this->entityManager->getRepository(Album::class);
        $albums = $albumRepository->findAll();
        return $this->json(['albums' => $albums ]);
    } */

    
          /*  $artist = $this->artistRepository->find($artistId);
            if (!$artist) {
                throw new \Exception("L'artiste n'a pas été trouvé.");
            }*/

            