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
use app\Entity\Album;
use app\Entity\Song;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Constraints as Assert;


class ArtistController extends AbstractController
{
    private $entityManager;
    private $artistRepository;
    private $serializer;
    private $userRepository;
    private $jwtManager;
    private $filesystem;

    private $tokenVerifier;

    public function __construct(
        EntityManagerInterface $entityManager,
        SerializerInterface $serializer,
        JWTTokenManagerInterface $jwtManager,
        UserRepository $userRepository,
        ArtistRepository $artistRepository,
        Filesystem $filesystem,
        TokenManagementController $tokenVerifier,
    ) {
        $this->entityManager = $entityManager;
        $this->artistRepository = $artistRepository;
        $this->serializer = $serializer;
        $this->jwtManager = $jwtManager;
        $this->userRepository = $userRepository;
        $this->filesystem = $filesystem;
        $this->tokenVerifier = $tokenVerifier;
    }

    #[Route('/artist', name: 'artist_action', methods: ['POST'])]
    public function artistAction(Request $request): JsonResponse
    {
        try {

            $userData = $this->tokenVerifier->checkToken($request);
            if (!$userData) {
                return $this->json($this->tokenVerifier->sendJsonErrorToken(false), JsonResponse::HTTP_FORBIDDEN);
            }
            $dataMiddellware = $this->tokenVerifier->checkToken($request);
            if (gettype($dataMiddellware) == 'boolean') {
                return $this->json($this->tokenVerifier->sendJsonErrorToken($dataMiddellware), JsonResponse::HTTP_UNAUTHORIZED);
            }
            $user = $dataMiddellware;
            $artist = $user->getArtist();

            if ($artist) {
                $fullname = $request->request->get('fullname');
                $labelId = $request->request->get('label');
                $description = $request->request->has('description') ? $request->request->get('description') : null;
                $avatar = $request->request->has('avatar') ? $request->request->get('avatar') : null;

                if ($labelId !== null) {
                    $label = $this->entityManager->getRepository(Label::class)->findOneBy(['idLabel' => $labelId]);
                    if (!$label) {
                        return new JsonResponse([
                            'error' => true,
                            'message' => "Le label n'existe pas.",
                        ], JsonResponse::HTTP_NOT_FOUND);
                    }
                    $artist->setLabel($label);
                }

                $additionalParams = array_diff(array_keys($request->request->all()), ['fullname', 'label', 'description', 'avatar']);
                if (!empty($additionalParams)) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => 'Les paramètres fournis sont invalides. Veuillez vérifier les données soumises',
                    ], JsonResponse::HTTP_BAD_REQUEST);
                }





                if ($avatar !== null) {
                    $parameter = $request->getContent();
                    parse_str($parameter, $data);

                    $avatarData = $data['avatar'];
                    $explodeData = explode(',', $avatarData);
                    if (count($explodeData) != 2) {
                        return new JsonResponse([
                            'error' => true,
                            'message' => 'Le serveur ne peut pas décoder le contenu base64 en fichier binaire.',
                        ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    $file = base64_decode($explodeData[1]);
                    $fileSize = strlen($file);
                    $minFileSize = 1 * 1024 * 1024;
                    $maxFileSize = 7 * 1024 * 1024;

                    if ($fileSize < $minFileSize || $fileSize > $maxFileSize) {
                        return new JsonResponse([
                            'error' => true,
                            'message' => 'Le fichier envoyé est trop ou pas assez volumineux. Vous devez respecter la taille entre 1Mb et 7Mb.',
                        ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->buffer($file);

                    if (!in_array($mimeType, ['image/jpeg', 'image/png'])) {
                        return new JsonResponse([
                            'error' => true,
                            'message' => 'Erreur sur le format du fichier qui n\'est pas pris en compte.',
                        ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    $fullname = $artist->getFullname();

                    $artistDirectory = $this->getParameter('avatar_directory') . '/' . $fullname;

                    if (!$this->filesystem->exists($artistDirectory)) {
                        $this->filesystem->mkdir($artistDirectory);
                    }

                    $extension = $mimeType === 'image/jpeg' ? 'jpg' : 'png';

                    $avatarFileName = $fullname . '.' . $extension;
                    $avatarFilePath = $artistDirectory . '/' . $avatarFileName;
                    file_put_contents($avatarFilePath, $file);
                }



                if ($fullname !== null) {

                    $existingArtist = $this->entityManager->getRepository(Artist::class)->findOneBy(['fullname' => $fullname]);
                    if ($existingArtist) {
                        return new JsonResponse([
                            'error' => true,
                            'message' => 'Le nom d\'artiste est déjà utilisé. Veuillez en choisir un autre. nom',
                        ],
                            JsonResponse::HTTP_CONFLICT
                        );
                    }
                    $oldArtistDirectory = $this->getParameter('avatar_directory') . '/' . $artist->getFullname();
                    $newArtistDirectory = $this->getParameter('avatar_directory') . '/' . $fullname;

                    if (!$this->filesystem->exists($newArtistDirectory)) {
                        $this->filesystem->rename($oldArtistDirectory, $newArtistDirectory);

                        $avatarFileExtensions = ['jpg', 'jpeg', 'png'];

                        $files = scandir($newArtistDirectory);
                        foreach ($files as $file) {
                            if ($file !== '.' && $file !== '..') {
                                foreach ($avatarFileExtensions as $ext) {
                                    if (pathinfo($file, PATHINFO_EXTENSION) === $ext) {
                                        $oldAvatarFile = $newArtistDirectory . '/' . $file;
                                        $newAvatarFile = $newArtistDirectory . '/' . $fullname . '.' . $ext;
                                        $this->filesystem->rename($oldAvatarFile, $newAvatarFile);
                                    }
                                }
                            }
                        }
                    }
                    $artist->setFullname($fullname);
                }

                if ($description !== null) {
                    $artist->setDescription($description);
                }

                $this->entityManager->persist($artist);
                $this->entityManager->flush();

                return new JsonResponse([
                    'success' => true,
                    'message' => 'Les informations de l\'artiste ont été mises à jour avec succès.',
                ]);
            } else {
                $fullname = $request->request->get('fullname');
                $labelId = $request->request->get('label');
                $description = $request->request->has('description') ? $request->request->get('description') : null;
                $User_idUser = $request->request->get('id');

                if ($fullname === null || $labelId === null) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "L'id du label et le fullname sont obligatoires.",
                    ], JsonResponse::HTTP_BAD_REQUEST);
                }

                $userBirthdate = $user->getBirth();
                $age = $userBirthdate->diff(new \DateTime())->y;
                if ($age < 16) {
                    return new JsonResponse(
                        [
                            'error' => true,
                            'message' => 'Vous devez au moins avoir 16 ans pour être artiste.'
                        ], Response::HTTP_FORBIDDEN);
                }

                $artist = $this->artistRepository->findOneBy(['fullname' => $fullname]);
                if ($artist) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => 'Ce nom d\'artiste est déjà pris. Veuillez en choisir un autre.'
                    ], Response::HTTP_CONFLICT);
                }
                $label = $this->entityManager->getRepository(Label::class)->findOneBy(['idLabel' => $labelId]);
                if (!preg_match('/^\d+$/', $labelId)) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "Le format de l'id du label est invalides.",
                    ], JsonResponse::HTTP_BAD_REQUEST);
                }


                if (!$label instanceof Label) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "Le label correspondant à l'id n'a pas été trouvé.",
                    ], JsonResponse::HTTP_NOT_FOUND);
                }

                if ($request->request->has('avatar')) {
                    $parameter = $request->getContent();
                    parse_str($parameter, $data);

                    $avatarData = $data['avatar'];
                    $explodeData = explode(',', $avatarData);
                    if (count($explodeData) != 2) {
                        return new JsonResponse([
                            'error' => true,
                            'message' => 'Le serveur ne peut pas décoder le contenu base64 en fichier binaire.',
                        ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    $file = base64_decode($explodeData[1]);
                    $fileSize = strlen($file);
                    $minFileSize = 1 * 1024 * 1024;
                    $maxFileSize = 7 * 1024 * 1024;

                    if ($fileSize < $minFileSize || $fileSize > $maxFileSize) {
                        return new JsonResponse([
                            'error' => true,
                            'message' => 'Le fichier envoyé est trop ou pas assez volumineux. Vous devez respecter la taille entre 1Mb et 7Mb.',
                        ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->buffer($file);

                    if (!in_array($mimeType, ['image/jpeg', 'image/png'])) {
                        return new JsonResponse([
                            'error' => true,
                            'message' => 'Erreur sur le format du fichier qui n\'est pas pris en compte.',
                        ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    $artistDirectory = $this->getParameter('avatar_directory') . '/' . $fullname;

                    if (!$this->filesystem->exists($artistDirectory)) {
                        $this->filesystem->mkdir($artistDirectory);
                    }

                    $extension = $mimeType === 'image/jpeg' ? 'jpg' : 'png';

                    $avatarFileName = $fullname . '.' . $extension;
                    $avatarFilePath = $artistDirectory . '/' . $avatarFileName;
                    file_put_contents($avatarFilePath, $file);
                }
                $artist = new Artist();
                $artist->setUserIdUser($user);
                $artist->setFullname($fullname);
                $artist->setLabel($label);
                $artist->setCreateAt(new \DateTimeImmutable());
                if (empty($description)) {
                    $artist->setDescription("");
                } else {
                    $artist->setDescription($description);
                }

                $this->entityManager->persist($artist);
                $this->entityManager->flush();

                return new JsonResponse([
                    'success' => true,
                    'message' => 'Votre compte d\'artiste a été créé avec succès. Bienvenue dans notre communauté d\'artistes !',
                    'artist_id' => $artist->getId(),
                ], Response::HTTP_CREATED);
            }
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }
    }


    #[Route('/artist', name: 'all_artist', methods: ['GET'])]
    public function getAllArtist(Request $request): JsonResponse
    {
        $dataMiddellware = $this->tokenVerifier->checkToken($request);
        if (gettype($dataMiddellware) == 'boolean') {
            return $this->json($this->tokenVerifier->sendJsonErrorToken($dataMiddellware), JsonResponse::HTTP_UNAUTHORIZED);
        }
        $user = $dataMiddellware;

        $page = $request->query->get('currentPage', 1);
        $limit = $request->query->get('limit', 5);

        $artist = $user->getArtist();

        if (!is_numeric($page) || $page < 1 && (!is_numeric($limit) || $limit === 5)) {
            return new JsonResponse([
                'error' => true,
                'message' => "Le paramètre de pagination est invalide. Veuillez fournir un numéro de page valide.",
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!is_numeric($limit) || $limit < 1) {
            return new JsonResponse([
                    'error' => true,
                    'message' => "Le paramètre de limite est invalide. Veuillez fournir une limite valide.",
                ], JsonResponse::HTTP_BAD_REQUEST);
        }



        $offset = ($page - 1) * $limit;

        $totalArtists = $this->artistRepository->count([]);

        $artists = $this->artistRepository->findBy([], null, $limit, $offset);

        $artistsArray = [];



        $avatarDirectory = $this->getParameter('avatar_directory');

        foreach ($artists as $artist) {
            $user = $artist->getUserIdUser();

            $avatarPath = null;

            $avatarFilename = $avatarDirectory . '/' . $artist->getFullname() . '/';
            $avatarFileExtensions = ['jpg', 'jpeg', 'png'];

            foreach ($avatarFileExtensions as $extension) {
                $avatarFile = $avatarFilename . $artist->getFullname() . '.' . $extension;
                if (file_exists($avatarFile)) {
                    $avatarPath = $avatarFile;
                    break;
                }
            }

            $artistData = [
                'firstname' => $user->getFirstname(),
                'lastname' => $user->getLastname(),
                'fullname' => $artist->getFullname(),
                'avatar' => $avatarPath,
                'sexe' => $user->getSexe(),
                'datebirth' => $user->getBirth()->format('d-m-Y'),
                'Artist.createdAt' => $artist->getCreateAt()->format('Y-m-d'),
            ];

            $albums = $artist->getAlbums();
            $albumsArray = [];
            foreach ($albums as $album) {
                $albumData = [
                    'id' => $album->getId(),
                    'nom' => $album->getTitle(),
                    'categ' => $album->getCategorie(),
                    'label' => $artist->getLabel(),
                    'cover' => $album->getCover(),
                    'year' => $album->getYear(),
                    'createdAt' => $album->getCreateAt()->format('Y-m-d'),
                ];
                $albumsArray[] = $albumData;
            }

            $songs = $artist->getSongs();
            $songsArray = [];
            foreach ($songs as $song) {
                $songData = [
                    'id' => $song->getId(),
                    'title' => $song->getTitle(),
                    'cover' => $song->getCover(),
                    'createdAt' => $song->getCreateAt()->format('Y-m-d'),
                ];
                $songsArray[] = $songData;
            }

            $artistData['albums'] = $albumsArray;
            $artistData['songs'] = $songsArray;

            $artistsArray[] = $artistData;
        }

        if (empty($artistsArray)) {
            return new JsonResponse([
                'error' => true,
                'message' => "Aucun artiste trouvé dans la page demandée.",
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json([
            'error' => false,
            'artists' => $artistsArray,
            'message' => "Informations des artistes récupérées avec succès.",
            'pagination' => [
                'currentPage' => intval($page),
                'totalPages' => ceil($totalArtists / $limit),
                'totalArtists' => $totalArtists,
            ],
        ]);
    }


    #[Route('/artist/{fullname}', name: 'get_artist', methods: ['GET'])]
    public function getArtist(Request $request, #[Assert\NotBlank] string $fullname): JsonResponse
    {
        try {

            $fullname = $request->query->get('fullname');

            if (!$fullname) {
                return new JsonResponse(
                        [
                            'error' => true,
                            'message' => "Le nom d'artiste est obligatoire pour cette requête.",
                        ],
                        JsonResponse::HTTP_BAD_REQUEST
                    );
            }


            $dataMiddellware = $this->tokenVerifier->checkToken($request);
            if (gettype($dataMiddellware) == 'boolean') {
                return $this->json($this->tokenVerifier->sendJsonErrorToken($dataMiddellware), JsonResponse::HTTP_UNAUTHORIZED);
            }
            $user = $dataMiddellware;

            $artist = $this->artistRepository->findOneBy(['fullname' => $fullname]);

            if (!$artist) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Aucun artiste trouvé correspondant au nom fourni.",
                ],
                    JsonResponse::HTTP_NOT_FOUND
                );
            }



            $user = $artist->getUserIdUser();
            $followersCount = $artist->getfollower()->count();

            $avatarDirectory = $this->getParameter('avatar_directory');
            $avatarPath = null;
            $avatarFilename = $avatarDirectory . '/' . $artist->getFullname() . '/';
            $avatarFileExtensions = ['jpg', 'jpeg', 'png'];

            foreach ($avatarFileExtensions as $extension) {
                $avatarFile = $avatarFilename . $artist->getFullname() . '.' . $extension;
                if (file_exists($avatarFile)) {
                    $avatarPath = $avatarFile;
                    break;
                }
            }

            $featuringArray = [];
            $songs = $artist->getSongs();
            foreach ($songs as $song) {
                $collabArtists = $song->getCollabSong();
                foreach ($collabArtists as $collabArtist) {
                    $collabSongInfo = [
                        'id' => $song->getId(),
                        'title' => $song->getTitle(),
                        'cover' => $song->getCover(),
                        'createdAt' => $song->getCreateAt()->format('Y-m-d'),
                        'artist' => $artist->getFullname(),
                    ];

                    $featuringArray[] = [
                        'id' => $collabArtist->getId(),
                        'fullname' => $collabArtist->getFullname(),
                        'collabSong' => $collabSongInfo,
                    ];
                }
            }

            $albums = $artist->getAlbums();
            $albumsArray = [];
            foreach ($albums as $album) {
                $albumsArray[] = [
                    'id' => $album->getId(),
                    'nom' => $album->getTitle(),
                    'categ' => $album->getCategorie(),
                    'label' => $artist->getLabel(),
                    'cover' => $album->getCover(),
                    'year' => $album->getYear(),
                    'createdAt' => $album->getCreateAt()->format('Y-m-d'),
                ];
            }

            $songs = $artist->getSongs();
            $songsArray = [];
            foreach ($songs as $song) {
                $songsArray[] = [
                    'id' => $song->getId(),
                    'title' => $song->getTitle(),
                    'cover' => $song->getCover(),
                    'createdAt' => $song->getCreateAt()->format('Y-m-d'),
                ];
            }

            $artistArray = [
                'firstname' => $user->getFirstname(),
                'lastname' => $user->getLastname(),
                'fullname' => $artist->getFullname(),
                'avatar' => $avatarPath,
                'follower' => $followersCount,
                'sexe' => $user->getSexe(),
                'datebirth' => $user->getBirth()->format('d-m-Y'),
                'Artist.createdAt' => $artist->getCreateAt()->format('Y-m-d'),
                'featuring' => $featuringArray,
                'albums' => $albumsArray,
                'songs' => $songsArray,
            ];

            return $this->json([
                'error' => false,
                'artist' => $artistArray,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }


    #[Route('/artist', name: 'desactivate_artist', methods: 'DELETE')]
    public function desactivateArtist(Request $request): JsonResponse
    {
        try {
            $dataMiddellware = $this->tokenVerifier->checkToken($request);
            if (gettype($dataMiddellware) == 'boolean') {
                return $this->json($this->tokenVerifier->sendJsonErrorToken($dataMiddellware), JsonResponse::HTTP_UNAUTHORIZED);
            }
            $user = $dataMiddellware;
            $artist = $user->getArtist();

            if (!$artist) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Compte artiste non trouvé. Vérifiez les informations fournies et réessayez.",
                ], JsonResponse::HTTP_NOT_FOUND);
            }

            if ($artist->getIsActive() === false) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Ce compte artiste est déjà désactivé.",
                ], JsonResponse::HTTP_GONE);
            }

            $artist->setIsActive(false);

            $this->entityManager->persist($artist);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Le compte artiste a été désactivé avec succès.',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }
}
