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


class ArtistController extends AbstractController
{
    private $entityManager;
    private $artistRepository;
    private $serializer;
    private $userRepository;
    private $jwtManager;
    private $filesystem;

    public function __construct(
        EntityManagerInterface $entityManager,
        SerializerInterface $serializer,
        JWTTokenManagerInterface $jwtManager,
        UserRepository $userRepository,
        ArtistRepository $artistRepository,
        Filesystem $filesystem
    ) {
        $this->entityManager = $entityManager;
        $this->artistRepository = $artistRepository;
        $this->serializer = $serializer;
        $this->jwtManager = $jwtManager;
        $this->userRepository = $userRepository;
        $this->filesystem = $filesystem;
    }

    #[Route('/artist', name: 'create_artist', methods: ['POST'])]
    public function createArtist(Request $request): JsonResponse
    {
        try {

            $currentUser = $this->getUser()->getUserIdentifier();
            $user = $this->userRepository->findOneBy(['email' => $currentUser]);

            $fullname = $request->request->get('fullname');
            $idLabel = $request->request->get('label');
            $description = $request->request->has('description') ? $request->request->get('description') : null;
            $User_idUser = $request->request->get('id');


            if ($fullname === null || $idLabel === null) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "L'id du label et le fullname sont obligatoires.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if (!preg_match('/^[0-9]+$/', $idLabel)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le format de l'id du label est invalide.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if (!preg_match('/^[a-zA-Z\s\W]+$/', $fullname)) {
                return new JsonResponse([
                        'error' => true,
                        'message' => "Le format du nom d'artiste est invalide.",
                    ], JsonResponse::HTTP_BAD_REQUEST);
            }
            
            $userBirthdate = $user->getBirth();
            $age = $userBirthdate->diff(new \DateTime())->y;
            if ($age < 16) {
                return $this->json(['error' => 'L\'âge de l\'utilisateur ne permet pas (16 ans)'], Response::HTTP_BAD_REQUEST);
            }
            if ($user->getArtist() !== null) {
                return new JsonResponse(
                    [
                        'error' => "true",
                        'message' => 'Un utilisateur ne peut gérer qu\'un seul compte artiste. Veuillez supprimer le compte existant pour en créer un nouveau.'
                    ],
                    Response::HTTP_FORBIDDEN
                );
            }

            $artist = $this->artistRepository->findOneBy(['fullname' => $fullname]);
            if ($artist) {
                return new JsonResponse(
                    [
                        'error' => "true",
                        'message' => 'Le nom d\'artiste déjà pris. Veuillez en choisir un autre.'
                    ],
                    Response::HTTP_CONFLICT
                );
            }

            $label = $this->entityManager->getRepository(Label::class)->findOneBy(['idLabel' => $idLabel]);

            if (!$label) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le label n'existe pas.",
                ], JsonResponse::HTTP_NOT_FOUND);
            }

            $parameter = $request->getContent();
            parse_str($parameter, $data);

            $avatarData = $data['avatar'];
            $explodeData = explode(',', $avatarData);
            if (count($explodeData) != 2) {
                return new JsonResponse(
                    [
                        'error' => true,
                        'message' => 'Le serveur ne peut pas dècoder le contenu base64 en fichier binaire.',
                    ],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $file = base64_decode($explodeData[1]);
            $fileSize = strlen($file);
            $minFileSize = 1 * 1024 * 1024;
            $maxFileSize = 7 * 1024 * 1024;

            if ($fileSize < $minFileSize || $fileSize > $maxFileSize) {
                return new JsonResponse(
                    [
                        'error' => true,
                        'message' => 'Le fichier envoyé est trop ou pas assez volumineux. Vous devez respecter la taille entre 1Mb et 7Mb.',
                    ],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($file);

            if (!in_array($mimeType, ['image/jpeg', 'image/png'])) {
                return new JsonResponse(
                    [
                        'error' => true,
                        'message' => 'Erreur sur le format du fichier qui n\'est pas pris en compte.',
                    ],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $artistDirectory = $this->getParameter('avatar_directory') . '/' . $fullname;

            if (!$this->filesystem->exists($artistDirectory)) {
                $this->filesystem->mkdir($artistDirectory);
            }

            $extension = $mimeType === 'image/jpeg' ? 'jpg' : 'png';

            $avatarFileName = $fullname . '.' . $extension;
            $avatarFilePath = $artistDirectory . '/' . $avatarFileName;
            file_put_contents($avatarFilePath, $file);

            $artist = new Artist();
            $artist->setUserIdUser($user);
            $artist->setFullname($fullname);
            $artist->setLabel($label);
            $artist->setCreateAt(new \DateTimeImmutable());

            $this->entityManager->persist($artist);
            $this->entityManager->flush();


            return new JsonResponse([
                'success' => true,
                'message' => 'Votre compte d\'artist a été crée avec succès. Bienvenue dans notre communauté d\'artistes!',
                'artist_id' => $artist->getId(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/artist', name: 'all_artist', methods: ['GET'])]
    public function getAllArtist(Request $request): JsonResponse
    {
        $currentUser = $this->getUser()->getUserIdentifier();
        $user = $this->userRepository->findOneBy(['email' => $currentUser]);

        $page = $request->query->get('currentPage', 1);
        $limit = $request->query->get('limit', 5);

        $artist = $user->getArtist();

        if (!is_numeric($page) || $page < 1 && (!is_numeric($limit) || $limit === 5)) {
            return new JsonResponse([
                'error' => true,
                'message' => "Le paramètre de pagination est invalide. Veuillez fournir un numéro de page valide.",
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
                    'nom' => $album->getNom(),
                    'categ' => $album->getCateg(),
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
                'currentPage' => $page,
                'totalPages' => ceil($totalArtists / $limit),
                'totalArtists' => $totalArtists,
            ],
        ]);
    }


    #[Route('/artist/{fullname}', name: 'get_artist', methods: ['GET'])]
    public function getArtist(string $fullname): JsonResponse
    {
        try {
            $currentUser = $this->getUser();
            if (!$currentUser) {
                throw new AuthenticationException('Utilisateur non authentifié.');
            }

            $user = $this->userRepository->findOneBy(['email' => $currentUser->getUserIdentifier()]);

            $artist = $this->artistRepository->findOneBy(['fullname' => $fullname]);

            if (!$artist) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Aucun artiste trouvé correspondant au nom fourni.",
                ], JsonResponse::HTTP_NOT_FOUND);
            }

            if ($artist->getFullname() === null) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le nom d'artiste est obligatoire pour cette requête.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $user = $artist->getUserIdUser();

            $artistArray = [
                'firstname' => $user->getFirstname(),
                'lastname' => $user->getLastname(),
                'sexe' => $user->getSexe(),
                'datebirth' => $user->getBirth()->format('Y-m-d'),
                'Artist.createdAt' => $artist->getCreateAt()->format('Y-m-d'),
            ];

            $albums = $artist->getAlbums();
            $albumsArray = [];
            foreach ($albums as $album) {
                $albumsArray[] = [
                    'id' => $album->getId(),
                    'nom' => $album->getNom(),
                    'categ' => $album->getCateg(),
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

            $artistArray['albums'] = $albumsArray;
            $artistArray['songs'] = $songsArray;

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

    #[Route('/artist', name: 'update_artist', methods: ['POST'])]
    public function updateArtist(Request $request): JsonResponse
    {
        try {
            $currentUser = $this->getUser()->getUserIdentifier();
            $user = $this->userRepository->findOneBy(['email' => $currentUser]);

            $artist = $user->getArtist();



            if (!$artist) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Artiste non trouvé. Veuillez vérifier les informations fournies.",
                ], JsonResponse::HTTP_NOT_FOUND);
            }

            if ($user->getArtist() === null) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Mise à jour non autorisée. Vous n'avez pas les droits requis pour modifier les informations de cet artiste.",
                ], JsonResponse::HTTP_FORBIDDEN);
            }

            if ($artist->getUserIdUser() !== $user) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Vous nêtes pas autorisé à accéder aux informations de cet artiste.",
                ], JsonResponse::HTTP_FORBIDDEN);
            }

            //fullname deja existant
            $fullname = $request->request->get('fullname');
            $artistExist = $this->artistRepository->findOneBy(['fullname' => $fullname]);
            if ($artistExist) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le nom d'artiste est déjà utilisé. Veuillez choisir un autre nom.",
                ], JsonResponse::HTTP_CONFLICT);
            }


            $fullname = $request->request->has('fullname') ? $request->request->get('fullname') : null;
            $description = $request->request->has('description') ? $request->request->get('description') : null;
            $label = $request->request->has('label') ? $request->request->get('label') : null;

            $artist->setFullname($fullname);
            $artist->setDescription($description);
            $artist->setLabel($label);

            $this->entityManager->persist($artist);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Le compte artiste a été mis à jour avec succès.',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }


    #[Route('/artist', name: 'desactivate_artist', methods: 'DELETE')]
    public function desactivateArtist(): JsonResponse
    {
        try {
            $currentUser = $this->getUser()->getUserIdentifier();
            $user = $this->userRepository->findOneBy(['email' => $currentUser]);

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
}
