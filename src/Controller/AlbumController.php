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
    private $albumRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        SerializerInterface $serializer,
        ArtistRepository $artistRepository,
        AlbumRepository $albumRepository,
        JWTTokenManagerInterface $jwtManager,
        TokenManagementController $tokenVerifier,
        Filesystem $filesystem,
    ) {
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
                    'message' => "Vous n'avez pas l'autorisation pour accéder à cet album."
                ], JsonResponse::HTTP_FORBIDDEN);
            }


            if (empty($title) || strlen($title) < 1 || strlen($title) > 90 || !preg_match('/^[a-zA-Z0-9\s\'"!@#$%^&*()_+=\-,.?;:]+$/u', $title)) {
                return $this->json([
                    'error' => true,
                    'message' => "Erreur de validation des données."
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

                $invalidCategories = ['rap', 'r\'n\'b', 'gospel', 'jazz', 'soul', 'country', 'hip hop', 'mike'];
                foreach ($categorieArray as $cat) {
                    if (in_array(strtolower($cat), $invalidCategories)) {
                        return $this->json([
                            'error' => true,
                            'message' => "Les catégorie ciblée sont invalide."
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
                    'message' => "Vous n'avez pas l'autorisation pour accéder à cet album."
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



    #[Route('/album/{id}', name: 'update_album', methods: ['PUT'])]
    public function updateAlbum(Request $request, int $id): JsonResponse
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

            $title = $request->request->get('title');
            $categorie = $request->request->get('categorie');
            $cover = $request->request->get('cover');
            $year = $request->request->get('year');
            $visibility = $request->request->get('visibility');

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

            if ($title !== null) {
                if (strlen($title) < 1 || strlen($title) > 90) {
                    return $this->json([
                        'error' => true,
                        'message' => "Erreur de validation des données."
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }
                if (!preg_match('/^[a-zA-Z0-9\s\'"!@#$%^&*()_+=\-,.?;:]+$/u', $title)) {
                    return $this->json([
                        'error' => true,
                        'message' => "Erreur de validation des données."
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }
                $album->setTitle($title);
            }

            if (!empty($categorie)) {
                $categorieArray = json_decode($categorie, true);

                if (!is_array($categorieArray) || empty($categorieArray)) {
                    return $this->json([
                        'error' => true,
                        'message' => "Erreur de validation des données."
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }

                $invalidCategories = ['rap', 'r\'n\'b', 'gospel', 'jazz', 'soul', 'country', 'hip hop', 'mike'];
                foreach ($categorieArray as $cat) {
                    if (in_array(strtolower($cat), $invalidCategories)) {
                        return $this->json([
                            'error' => true,
                            'message' => "Les catégorie ciblée sont invalide."
                        ], JsonResponse::HTTP_BAD_REQUEST);
                    }
                }
                $album->setCategorie($categorie);
            }




            if ($cover !== null) {
                $explodeData = explode(',', $cover);
                if (count($explodeData) != 2) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "Le serveur ne peut pas décoder le contenu base64 en fichier binaire."
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }

                $file = base64_decode($explodeData[1]);
                $fileSize = strlen($file);
                $minFileSize = 1 * 1024 * 1024;
                $maxFileSize = 7 * 1024 * 1024;

                if ($fileSize < $minFileSize || $fileSize > $maxFileSize) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "Le fichier envoyé est trop ou pas assez volumineux. Vous devez respecter la taille entre 1Mb et 7Mb."
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($file);

                if (!in_array($mimeType, ['image/jpeg', 'image/png'])) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "Erreur sur le format du fichier qui n'est pas pris en compte."
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }

                $coverFileName = uniqid('album_cover_') . '.' . pathinfo($explodeData[0], PATHINFO_EXTENSION);
                $coverDirectory = $this->getParameter('cover_directory');
                file_put_contents($coverDirectory . '/' . $coverFileName, $file);
                $album->setCover($coverFileName);

                if ($album->getCover() !== null) {
                    $oldCoverPath = $coverDirectory . '/' . $album->getCover();
                    if ($this->filesystem->exists($oldCoverPath)) {
                        $this->filesystem->remove($oldCoverPath);
                    }
                }
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
                    'message' => 'Ce titre est déjà pris. Veuillez en choisir un autre.'
                ], JsonResponse::HTTP_CONFLICT);
            }

            $this->entityManager->flush();

            return $this->json([
                'error' => false,
                'message' => "Album mis à jour avec succès."
            ], JsonResponse::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => true, 'message' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }



      #[Route('/album/search', name: 'search_album', methods: ['GET'])]
    public function searchAlbum(Request $request): JsonResponse
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

            $limit = $request->query->get('limit', 5);
            $category = $request->query->get('categorie');
            $featurings = $request->query->get('featuring');
            $labelId = $request->query->get('label');
            $fullname = $request->query->get('fullname');
            $currentPage = $request->query->get('currentPage');
            $nom = $request->query->get('title');
            $year = $request->query->get('year');

            $additionalParams = array_diff(array_keys($request->query->all()), ['title', 'categorie', 'currentPage', 'featuring']);
            if (!empty($additionalParams)) {
                return $this->json([
                    'error' => true,
                    'message' => "Les paramètres fournis sont invalides. Veuillez vérifier les données soumises."
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if ($year && (!is_numeric($year))) {
                return $this->json([
                    'error' => true,
                    'message' => "L'année n'est pas valide."
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if (!is_numeric($currentPage) || $currentPage < 1 || !is_numeric($limit) || $limit < 1) {
                return $this->json([
                    'error' => true,
                    'message' => "Le paramètre de pagination est invalide. Veuillez fournir un numéro de page valide."
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if (!empty($category)) {
                $categoryArray = json_decode($category, true);

                if (!is_array($categoryArray) || empty($categoryArray)) {
                    return $this->json([
                        'error' => true,
                        'message' => "Les données de catégorie fournies ne sont pas valides. Veuillez soumettre un tableau valide."
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }

                $invalidCategories = ['rap', 'r\'n\'b', 'gospel', 'jazz', 'soul country', 'hip hop', 'Mike'];

                foreach ($categoryArray as $cat) {
                    if (in_array(strtolower($cat), $invalidCategories)) {
                        return $this->json([
                            'error' => true,
                            'message' => "Les catégorie ciblée sont invalide."
                        ], JsonResponse::HTTP_BAD_REQUEST);
                    }
                }
            }

            if (!empty($featurings) && !is_array($featurings)) {
                return $this->json([
                    'error' => true,
                    'message' => "Les featuring ciblées sont invalides."
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $offset = ($currentPage - 1) * $limit;

            $criteria = [];
            if ($category) {
                $criteria['categorie'] = $category;
            }
            if ($featurings) {
                $criteria['featuring'] = $featurings;
            }
            if ($year) {
                $criteria['year'] = $year;
            }
            if ($labelId) {
                $criteria['label'] = $labelId;
            }
            if ($fullname) {
                $criteria['fullname'] = $fullname;
            }
            if ($nom) {
                $criteria['title'] = $nom;
            }
            $totalAlbums = $this->albumRepository->count($criteria);

            $albums = $this->albumRepository->findBy($criteria, null, $limit, $offset);

            if (empty($albums)) {
                return $this->json([
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
                    'nom' => $album->getTitle(),
                    'categ' => $album->getCategorie(),
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
                    'currentPage' => $currentPage,
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


if (!$album) {
    return $this->json([
        'error' => true,
        'message' => "L'album correspondant à l'ID spécifié n'a pas été trouvé."
    ], Response::HTTP_NOT_FOUND);
}

if (!$album->getVisibility() && $album->getArtistUserIdUser() !== $user) {
    return $this->json([
        'error' => true,
        'message' => "Vous n'êtes pas autorisé à accéder à cet album."
    ], Response::HTTP_FORBIDDEN);
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
                'nom' => $album->getTitle(),
                'categ' => $album->getCategorie(),
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
                    'nom' => $album->getTitle(),
                    'categ' => $album->getCategorie(),
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
