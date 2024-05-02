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
    
    #[Route('/album/{id}/song', name: 'add_song', methods: ['POST'])]
    public function addSong(Request $request, int $id): JsonResponse
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
    
            $album = $this->entityManager->getRepository(Album::class)->find($id);

            $songFile = $request->files->get('song');
            $songId = $request->request->get('id');
    
            if (!$album) {
                return $this->json([
                    'error' => true,
                    'message' => "Aucun album trouvé correspondant au nom fourni."
                ], JsonResponse::HTTP_NOT_FOUND);
            }
    
            if ($album->getArtistUserIdUser() !== $user->getArtist()) {
                return $this->json([
                    'error' => true,
                    'message' => "Vous n'avez pas l'autorisation pour accéder à cet album."
                ], JsonResponse::HTTP_FORBIDDEN);
            }
    
            $coverDirectory = $this->getParameter('cover_directory');
            $albumCover = $album->getCover();
            if (!$albumCover || !file_exists($coverDirectory . '/' . $albumCover)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le fichier de couverture de l'album n'existe pas."
                ], JsonResponse::HTTP_NOT_FOUND);
            }
    
            $songCover = $coverDirectory . '/' . $albumCover;
    
           
            $fileSize = $songFile->getSize();
            $minFileSize = 1 * 1024 * 1024;
            $maxFileSize = 7 * 1024 * 1024;
            if ($fileSize < $minFileSize || $fileSize > $maxFileSize) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le fichier envoyé est trop ou pas assez volumineux. Vous devez respecter la taille entre 1Mb et 7Mb."
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
    
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($songFile->getPathname());
            $allowedMimeTypes = ['audio/mpeg', 'audio/mp3', 'audio/x-wav'];
            if (!in_array($mimeType, $allowedMimeTypes)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Erreur sur le format du fichier qui n'est pas pris en charge."
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
    
            $localFilePath = $songFile->getPathname();
    
            $songTitle = pathinfo($localFilePath, PATHINFO_FILENAME);
    
            $url = $localFilePath;
    
            $song = new Song();
            $song->setIdSong($songId); 
            $song->setTitle($songTitle); 
            $song->setUrl($url);
            $song->setAlbum($album);
            $song->setCover($songCover); 
            $song->setCreateAt(new \DateTimeImmutable());
    
            $songDirectory = $this->getParameter('song_directory');
            $songFileName = uniqid('song_') . '.' . $songFile->getClientOriginalExtension();
            $songFile->move($songDirectory, $songFileName);
            $song->setUrl($songFileName);
    
            $this->entityManager->persist($song);
            $this->entityManager->flush();
    
            return $this->json([
                'error' => false,
                'message' => 'Album mis à avec succès.',
                'idSong' => $song->getId()
            ], JsonResponse::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json([
                'error' => true,
                'message' => $e->getMessage()
            ], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

}
