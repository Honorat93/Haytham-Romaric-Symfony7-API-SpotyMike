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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use App\Repository\UserRepository;
use App\Repository\ArtistRepository;
use App\Entity\Label;
use App\Entity\Album;
use App\Entity\Song;
use Symfony\Component\Filesystem\Filesystem;
use App\Repository\AlbumRepository;


class AlbumController extends AbstractController
{
    private $entityManager;
    private $validator;
    private $serializer;
    private $artistRepository;
    private $jwtManager;
    private $tokenVerifier;
    private $filesystem;

    public function __construct(
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        SerializerInterface $serializer,
        ArtistRepository $artistRepository,
        AlbumRepository $albumRepository,
        JWTTokenManagerInterface $jwtManager,
        TokenManagementController $tokenVerifier,
        Filesystem $filesystem,
    )
    {
        $this->entityManager = $entityManager;
        $this->validator = $validator;
        $this->artistRepository = $artistRepository;
        $this->tokenVerifier = $tokenVerifier; 
        $this->serializer = $serializer;
        $this->jwtManager = $jwtManager;
        $this->filesystem = $filesystem;
        $this->albumRepository = $albumRepository; 
    }
    #[Route('/album', name: 'create_album', methods: ['POST'])]
    public function createAlbum(Request $request): JsonResponse
    {
        try {
            // Vérification du token
            $dataMiddleware = $this->tokenVerifier->checkToken($request);
            if (gettype($dataMiddleware) === 'boolean') {
                return $this->json(
                    $this->tokenVerifier->sendJsonErrorToken($dataMiddleware),
                    JsonResponse::HTTP_UNAUTHORIZED
                );
            }
            $user = $dataMiddleware;
    
            // Vérification de l'authentification de l'utilisateur
            if (!$user) {
                return $this->json([
                    'error' => true,
                    'message' => "Authentification requise. Vous devez être connecté pour effectuer cette action."
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }
    
            // Récupération des données de la requête
            $title = $request->request->get('title');
            $categorie = $request->request->get('categorie');
            $cover = $request->request->get('cover');
            $visibility = $request->request->get('visibility');
            $year = $request->request->get('year');
    
            
            $additionalParams = array_diff(array_keys($request->request->all()), ['title', 'categorie', 'cover', 'visibility']);
            if (!empty($additionalParams)) {
                return $this->json([
                    'error' => true,
                    'message' => "Les paramètres fournis sont invalides. Veuillez vérifier les données soumises."
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
    
            
            if ($year === null) {
                $year = 2024;
            }
    
            
            if (!$user->getArtist()) {
                return $this->json([
                    'error' => true,
                    'message' => "Accès refusé. Vous n'avez pas l'autorisation pour créer un album."
                ], JsonResponse::HTTP_FORBIDDEN);
            }
    
           
            if (empty($title) || strlen($title) < 1 || strlen($title) > 90 || !preg_match('/^[a-zA-Z0-9\s\'"!@#$%^&*()_+=\-,.?;:]+$/u', $title)) {
                return $this->json([
                    'error' => true,
                    'message' => "Erreur de validation des données pour le champ 'title'."
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
    
            
            if (!empty($categorie)) {
                $categorieArray = json_decode($categorie, true);
    
                if (!is_array($categorieArray) || empty($categorieArray)) {
                    return $this->json([
                        'error' => true,
                        'message' => "Erreur de validation des données."
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }
    
                $invalidCategories = ['rap', 'r\'n\'b', 'gospel', 'jazz', 'soul country', 'hip hop', 'mike'];
                foreach ($categorieArray as $cat) {
                    if (in_array(strtolower($cat), $invalidCategories)) {
                        return $this->json([
                            'error' => true,
                            'message' => "Les catégories ciblées sont invalides. Veuillez fournir des catégories valides."
                        ], JsonResponse::HTTP_BAD_REQUEST);
                    }
                }
            }
    
            
            if ($cover !== null) {
                $parameter = $request->getContent();
                parse_str($parameter, $data);
    
                $coverData = $data['cover'];
                $explodeData = explode(',', $coverData);
                if (count($explodeData) != 2) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "Le serveur ne peut pas décoder le contenu base64 en fichier binaire.",
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }
    
                $file = base64_decode($explodeData[1]);
                $fileSize = strlen($file);
                $minFileSize = 1 * 1024 * 1024;
                $maxFileSize = 7 * 1024 * 1024;
    
               
                
                if ($fileSize < $minFileSize || $fileSize > $maxFileSize) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "Le fichier envoyé est trop ou pas assez volumineux. Vous devez respecter la taille entre 1Mb et 7Mb.",
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }
                
    
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($file);
    
                if (!in_array($mimeType, ['image/jpeg', 'image/png'])) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "Erreur sur le format du fichier qui n'est pas pris en compte.",
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }
            }
    
            
            $album = new Album();
            $album->setTitle($title)
                ->setCategorie($categorie)
                ->setYear($year)
                ->setVisibility($visibility);
    
            
            $artist = $user->getArtist();
            if (!$artist) {
                return $this->json([
                    'error' => true,
                    'message' => "Accès refusé. Vous n'avez pas l'autorisation pour accéder à cet album."
                ], JsonResponse::HTTP_FORBIDDEN);
            }
            $album->setArtistUserIdUser($artist);
    
            
            if ($coverData !== null) {
                $coverDirectory = $this->getParameter('cover_directory');
                if (!$this->filesystem->exists($coverDirectory)) {
                    $this->filesystem->mkdir($coverDirectory);
                }
    
                $mimeType = finfo_buffer(finfo_open(), base64_decode(explode(',', $coverData)[1]), FILEINFO_MIME_TYPE);
                $extension = $mimeType === 'image/jpeg' ? 'jpg' : 'png';
    
                $coverFileName = uniqid('album_cover_') . '.' . $extension;
                $coverFilePath = $coverDirectory . '/' . $coverFileName;
    
                file_put_contents($coverFilePath, base64_decode(explode(',', $coverData)[1]));
    
                $album->setCover($coverFileName);
    
                $this->entityManager->persist($album);
                $this->entityManager->flush();
            }
    
            
            if ($visibility !== null) {
                if ($visibility != 0 && $visibility != 1) {
                    return $this->json([
                        'error' => true,
                        'message' => "La valeur du champ visibility est invalide. Les valeurs autorisées sont 0 pour invisible, 1 pour visible."
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }
                $album->setVisibility($visibility);
            }
    
            
            $existingAlbum = $this->entityManager->getRepository(Album::class)->findOneBy(['title' => $title]);
            if ($existingAlbum && $existingAlbum !== $album) {
                return $this->json([
                    'error' => true,
                    'message' => "Ce titre est déjà pris. Veuillez en choisir un autre."
                ], JsonResponse::HTTP_CONFLICT);
            }
    
           
            return $this->json([
                'error' => false,
                'message' => 'Album créé avec succès.',
                'id' => $album->getId() 
            ], JsonResponse::HTTP_CREATED);
        } catch (\Exception $e) {
            
            return $this->json([
                'error' => true,
                'message' => $e->getMessage()
            ], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

}
