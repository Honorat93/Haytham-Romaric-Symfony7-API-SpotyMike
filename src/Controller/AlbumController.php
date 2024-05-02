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

        
    #[Route('/album/{id}', name: 'get_album', methods: ['GET'])]
    public function getAlbum(Request $request, int $id): JsonResponse
    {
        try {
            $dataMiddleware = $this->tokenVerifier->checkToken($request);
            if (gettype($dataMiddleware) === 'boolean') {
                return $this->json(
                    $this->tokenVerifier->sendJsonErrorToken($dataMiddleware),
                    JsonResponse::HTTP_UNAUTHORIZED
                );
            }
            $user = $dataMiddleware;
    
            if (!$user) {
                return $this->json([
                    'error' => true,
                    'message' => "Authentification requise. Vous devez être connecté pour effectuer cette action."
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }
            

            $id = $request->query->get('id');

            if ($id === null) {
                return $this->json([
                    'error' => true,
                    'message' => "L'id de l'album est obligatoire pour cette requête."
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $album = $this->albumRepository->find($id);
    
            if ($album === null) {
                return $this->json([
                    'error' => true,
                    'message' => "L'id de l'album est requis obligatoire pour cette requete."
                ], Response::HTTP_BAD_REQUEST);
            }
    
            if (!$album->getVisibility() && $album->getArtistUserIdUser() !== $user) {
                return $this->json([
                    'error' => true,
                    'message' => "L'album non trouvé. Vérifiez les informations fournies et réessayez.",
                ], Response::HTTP_NOT_FOUND);
            }
    
            if ($album->isDeleted() && $album->getArtistUserIdUser() !== $user) {
                return $this->json([
                    'error' => true,
                    'message' => "L'album non trouvé. Vérifiez les informations fournies et réessayez.",
                ], Response::HTTP_NOT_FOUND);
            }
    
            $songs = [];
            foreach ($album->getSongIdSong() as $song) {
                $songDetails = [
                    'id' => $song->getId(),
                    'title' => $song->getTitle(),
                    'cover' => $song->getCover(),
                    'createdAt' => $song->getCreateAt(),
                    'featuring' => []
                ];
    
                foreach ($song->getArtistIdUser() as $artist) {
                    $artistUser = $artist->getArtistUserIdUser();
                    if ($artistUser !== null) {
                      
                        $artistDetails = [
                            'id' => $artistUser->getId(),
                            'firstname' => $artistUser->getFirstName(),
                            'lastname' => $artistUser->getLastName(),
                            'fullname' => $artistUser->getFullName(),
                            'avatar' => null, 
                            'follower' => $artistUser->getFollower(),
                            'cover' => $artistUser->getCover(),
                            'sexe' => $artistUser->getSexe(),
                            'dateBirth' => $artistUser->getDateBirth(),
                            'createdAt' => $artistUser->getCreateAt()
                        ];
    
                        $avatarDirectory = $this->getParameter('avatar_directory');
                        $avatarFilename = $artistUser->getFullname(); 
                        $avatarFileExtensions = ['jpg', 'jpeg', 'png'];
    
                        foreach ($avatarFileExtensions as $extension) {
                            $avatarFile = $avatarDirectory . '/' . $avatarFilename . '.' . $extension;
                            if (file_exists($avatarFile)) {
                                $artistDetails['avatar'] = $avatarFile; 
                                break; 
                            }
                        }
    
                        $songDetails['artist'] = $artistDetails;
                    }
                }
    
                
                foreach ($song->getCollabSong() as $collabArtist) {
                    $collabArtistUser = $collabArtist->getArtistUserIdUser();
                    if ($collabArtistUser !== null) {
                        
                        $collabArtistDetails = [
                            'id' => $collabArtistUser->getId(),
                            'firstname' => $collabArtistUser->getFirstName(),
                            'lastname' => $collabArtistUser->getLastName(),
                            'fullname' => $collabArtistUser->getFullName(),
                            'avatar' => null, 
                            'follower' => $collabArtistUser->getFollower(),
                            'cover' => $collabArtistUser->getCover(),
                            'sexe' => $collabArtistUser->getSexe(),
                            'dateBirth' => $collabArtistUser->getDateBirth(),
                            'createdAt' => $collabArtistUser->getCreateAt()
                        ];
    
                        
                        $avatarDirectory = $this->getParameter('avatar_directory');
                        $avatarFilename = $collabArtistUser->getFullname(); 
                        $avatarFileExtensions = ['jpg', 'jpeg', 'png'];
    
                        foreach ($avatarFileExtensions as $extension) {
                            $avatarFile = $avatarDirectory . '/' . $avatarFilename . '.' . $extension;
                            if (file_exists($avatarFile)) {
                                $collabArtistDetails['avatar'] = $avatarFile; 
                                break;
                            }
                        }
    
                        
                        $songDetails['featuring'][] = $collabArtistDetails;
                    }
                }
                
                $songs[] = $songDetails;
            }
    
            
            $albumData = [
                'error' => false,
                'id' => $album->getId(),
                'nom' => $album->getNom(),
                'categ' => $album->getCateg(),
                'label' => $album->getArtistUserIdUser()->getLabel()->getName(),
                'cover' => $album->getCover(),
                'year' => $album->getYear(),
                'createdAt' => $album->getCreateAt(),
                'songs' => $songs,
            ];
    
            
            $artist = $album->getArtistUserIdUser();
            if ($artist !== null) {
                $artistData = [
                    'firstname' => $user->getFirstName(),
                    'lastname' => $user->getLastName(),
                    'fullname' => $artist->getFullName(),
                    'avatar' => null, 
                    'follower' => $artist->getFollower(),
                    'cover' => $album->getCover(),
                    'sexe' => $user->getSexe(),
                    'dateBirth' => $user->getBirth(),
                    'createdAt' => $artist->getCreateAt()
                ];
    
                
                $avatarDirectory = $this->getParameter('avatar_directory');
                $avatarFilename = $artist->getFullname(); 
                $avatarFileExtensions = ['jpg', 'jpeg', 'png'];
    
                foreach ($avatarFileExtensions as $extension) {
                    $avatarFile = $avatarDirectory . '/' . $avatarFilename . '.' . $extension;
                    if (file_exists($avatarFile)) {
                        $artistData['avatar'] = $avatarFile; 
                        break; 
                    }
                }
    
                $albumData['artist'] = $artistData;
            }
    
            return $this->json($albumData);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Error: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    
    #[Route('/albums', name: 'get_albums', methods: ['GET'])]
    public function getAllAlbums(Request $request): JsonResponse
    {
        try {
            $dataMiddleware = $this->tokenVerifier->checkToken($request);
            if (gettype($dataMiddleware) === 'boolean') {
                return $this->json(
                    $this->tokenVerifier->sendJsonErrorToken($dataMiddleware),
                    JsonResponse::HTTP_UNAUTHORIZED
                );
            }
            $user = $dataMiddleware;
    
            if (!$user) {
                return $this->json([
                    'error' => true,
                    'message' => "Authentification requise. Vous devez être connecté pour effectuer cette action."
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }
    
            $page = $request->query->get("currentPage");
            $limit = $request->query->get("limit", 5);
    
            if (!is_numeric($page) || $page < 1 || !is_numeric($limit) || $limit < 1) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le paramètre de pagination est invalide. Veuillez fournir un numéro de page valide.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
    
            $offset = ($page - 1) * $limit;
    
            $totalAlbums = $this->albumRepository->count([]);
    
            $albums = $this->albumRepository->findBy([], null, $limit, $offset);
    
            if (empty($albums)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Aucun album trouvé pour la page demandée.",
                ], JsonResponse::HTTP_NOT_FOUND);
            }
    
    
            $albumsData = [];
    
            foreach ($albums as $album) {
                $songs = [];
                foreach ($album->getSongIdSong() as $song) {
                    $songDetails = [
                        'id' => $song->getId(),
                        'title' => $song->getTitle(),
                        'cover' => $song->getCover(),
                        'createdAt' => $song->getCreateAt(),
                        'featuring' => []
                    ];
    
                    foreach ($song->getArtistIdUser() as $artist) {
                        $artistUser = $artist->getArtistUserIdUser();
                        if ($artistUser !== null) {
                            $artistDetails = [
                                'id' => $artistUser->getId(),
                                'firstname' => $artistUser->getFirstName(),
                                'lastname' => $artistUser->getLastName(),
                                'fullname' => $artistUser->getFullName(),
                                'avatar' => null,
                                'follower' => $artistUser->getFollower(),
                                'cover' => $artistUser->getCover(),
                                'sexe' => $artistUser->getSexe(),
                                'dateBirth' => $artistUser->getBirth(),
                                'createdAt' => $artistUser->getCreateAt()
                            ];
    
                            $avatarDirectory = $this->getParameter('avatar_directory');
                            $avatarFilename = $artistUser->getFullname();
                            $avatarFileExtensions = ['jpg', 'jpeg', 'png'];
    
                            foreach ($avatarFileExtensions as $extension) {
                                $avatarFile = $avatarDirectory . '/' . $avatarFilename . '.' . $extension;
                                if (file_exists($avatarFile)) {
                                    $artistDetails['avatar'] = $avatarFile;
                                    break;
                                }
                            }
    
                            $songDetails['artist'] = $artistDetails;
                        }
                    }
    
                    foreach ($song->getCollabSong() as $collabArtist) {
                        $collabArtistUser = $collabArtist->getArtistUserIdUser();
                        if ($collabArtistUser !== null) {
                            $collabArtistDetails = [
                                'id' => $collabArtistUser->getId(),
                                'firstname' => $collabArtistUser->getFirstName(),
                                'lastname' => $collabArtistUser->getLastName(),
                                'fullname' => $collabArtistUser->getFullName(),
                                'avatar' => null,
                                'follower' => $collabArtistUser->getFollower(),
                                'cover' => $collabArtistUser->getCover(),
                                'sexe' => $collabArtistUser->getSexe(),
                                'dateBirth' => $collabArtistUser->getBirth(),
                                'createdAt' => $collabArtistUser->getCreateAt()
                            ];
    
                            $avatarDirectory = $this->getParameter('avatar_directory');
                            $avatarFilename = $collabArtistUser->getFullname();
                            $avatarFileExtensions = ['jpg', 'jpeg', 'png'];
    
                            foreach ($avatarFileExtensions as $extension) {
                                $avatarFile = $avatarDirectory . '/' . $avatarFilename . '.' . $extension;
                                if (file_exists($avatarFile)) {
                                    $collabArtistDetails['avatar'] = $avatarFile;
                                    break;
                                }
                            }
    
                            $songDetails['featuring'][] = $collabArtistDetails;
                        }
                    }
    
                    $songs[] = $songDetails;
                }
    
                $albumData = [
                    'id' => $album->getId(),
                    'nom' => $album->getNom(),
                    'categ' => $album->getCateg(),
                    'label' => $album->getArtistUserIdUser()->getLabel()->getName(),
                    'cover' => $album->getCover(),
                    'year' => $album->getYear(),
                    'createdAt' => $album->getCreateAt(),
                    'songs' => $songs,
                ];
    
                $artist = $album->getArtistUserIdUser();
                if ($artist !== null) {
                    $artistData = [
                        'firstname' => $artist->getUserIdUser()->getFirstName(),
                        'lastname' => $artist->getUserIdUser()->getLastName(),
                        'fullname' => $artist->getFullName(),
                        'avatar' => null,
                        'follower' => $artist->getFollower(),
                        'cover' => $album->getCover(),
                        'sexe' => $artist->getUserIdUser()->getSexe(),
                        'dateBirth' => $artist->getUserIdUser()->getBirth(),
                        'createdAt' => $artist->getCreateAt()
                    ];
    
                    $avatarDirectory = $this->getParameter('avatar_directory');
                    $avatarFilename = $artist->getFullname();
                    $avatarFileExtensions = ['jpg', 'jpeg', 'png'];
    
                    foreach ($avatarFileExtensions as $extension) {
                        $avatarFile = $avatarDirectory . '/' . $avatarFilename . '.' . $extension;
                        if (file_exists($avatarFile)) {
                            $artistData['avatar'] = $avatarFile;
                            break;
                        }
                    }
    
                    $albumData['artist'] = $artistData;
                }
    
                $albumsData[] = $albumData;
            }
    
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
                'error' => true,
                'message' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
