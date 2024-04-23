<?php

namespace App\Controller;

use App\Entity\Album;
use App\Repository\ArtistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response; 
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class AlbumController extends AbstractController
{
    private $entityManager;
    private $validator;
    private $artistRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        ArtistRepository $artistRepository
    )
    {
        $this->entityManager = $entityManager;
        $this->validator = $validator;
        $this->artistRepository = $artistRepository;
    }

    #[Route('/album/creation', name: 'create_album', methods: ['POST'])]
    public function createAlbum(Request $request): JsonResponse
    {
        try {
            $currentUser = $this->getUser();
            if (!$currentUser) {
                return $this->json([
                    'error' => true,
                    'message' => 'Authentification requise vous devez etre connecté pour effectuer cette action.'
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }
    
        
            $token = $request->headers->get('Authorization');
            if (!$token) {
                return $this->json([
                    'error' => true,
                    'message' => 'Authentification requise vous devez etre connecté pour effectuer cette action.'
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }
    
            
            try {
                $user = $this->jwtManager->decode($token);
            } catch (\Exception $e) {
                return $this->json([
                    'error' => true,
                    'message' => 'Authentification requise vous devez etre connecté pour effectuer cette action.'
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }
    
            
            $user = $this->jwtManager->decode($token);
            
            $title = $request->request->get('title');
            $categorie = $request->request->get('categorie');
            $cover = $request->request->get('cover');
            $year = $request->request->get('year');
            $visibility = $request->request->get('visibility');
            //$artistId = $request->request->get('artistId');

            $additionalParams = array_diff(array_keys($request->request->all()), ['title', 'categorie', 'cover', 'visibility']);
            if (!empty($additionalParams)) {
                return $this->json([
                    'error' => true,
                    'message' => 'Les paramètres fournis sont invalides. Veuillez vérifier les données soumises.'
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
            
        
        if ($year === null) {
            $year = 2024; 
           }

            
            $requiredFields = ['categorie', 'title', 'cover', 'visibility'];
            foreach ($requiredFields as $field) {
                if (empty($$field)) {
                    throw new \Exception("Le champ $field est obligatoire.");
                }
            }
            
           
                if (!$this->getUser()) {
                  return $this->json([
              'error' => true,
              'message' => "Authentification requise. Vous devez être connecté pour effectuer cette action."
              ], Response::HTTP_UNAUTHORIZED); 
           }
           
           
        if (!$currentUser->hasRole('ROLE_ARTIST')) {
           return $this->json([
             'error' => true,
             'message' => 'Accès refusé. Vous n\'avez pas l\'autorisation pour créer un album.'
                  ], JsonResponse::HTTP_FORBIDDEN);
           }
                                         

            
            if (empty($title) || strlen($title) < 1 || strlen($title) > 90) {
                return $this->json(['error' => true, 'message' => "Erreur de validation des données"], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (!preg_match('/^[a-zA-Z0-9\s\'"!@#$%^&*()_+=\-,.?;:]+$/u', $title)) {
                return $this->json(['error' => true, 'message' => "Erreur de validation des données"], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            
            $validCategories = ['rock', 'pop', 'jazz', 'blues'];
            $invalidCategories = ['rap', 'r\'n\'b', 'gospel', 'soul country', 'hip hop', 'Mike'];

            if (in_array($categorie, $invalidCategories)) {
                return $this->json(['error' => true, 'message' => "Les catégories ciblées sont invalides"], Response::HTTP_BAD_REQUEST);
            }

           $explodeData = explode(',', $cover);
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
            }

            
            $visibility = intval($visibility);
            if ($visibility !== 0 && $visibility !== 1) {
                return $this->json(['error' => true, 'message' => "La valeur du champ visibility est invalide. Les valeurs autorisées sont 0 pour invisible, 1 pour visible"], Response::HTTP_BAD_REQUEST); // 400 Bad Request
            }

            $existingAlbum = $this->entityManager->getRepository(Album::class)->findOneBy(['title' => $title]);
            if ($existingAlbum) {
                
                return $this->json([
                    'error' => true,
                    'message' => 'Ce titre est déjà utilisé. Veuillez en choisir un autre.'
                ], Response::HTTP_CONFLICT); 
            }

          
            $album = new Album();
            $album->setTitle($title)
                ->setCategorie($categorie)
                ->setCover($cover)
                ->setYear($year)
                ->setVisibility($visibility);
               // ->setArtistUserIdUser($artist);

            $errors = $this->validator->validate($album);
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }
                return $this->json(['error' => true, 'message' => $errorMessages], JsonResponse::HTTP_BAD_REQUEST);
            }

            
            $this->entityManager->persist($album);
            $this->entityManager->flush();

            return $this->json([
                'error' => false,
                'message' => 'Album créé avec succès.',
                'album_id' => $album->getId() 
            ], JsonResponse::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => true, 'message' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }    
}



   /* #[Route('/album/{id}/detail', name: 'app_album_detail', methods: 'GET')]
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
}*/
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

            