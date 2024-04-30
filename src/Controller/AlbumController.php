<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Album;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use App\Repository\ArtistRepository;
use App\Entity\Artist;

class AlbumController extends AbstractController
{
    private $entityManager;
    private $validator;
    private $serializer;
    private $artistRepository;
    private $jwtManager;
    private $filesystem;

    public function __construct(
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        SerializerInterface $serializer,
        ArtistRepository $artistRepository,
        JWTTokenManagerInterface $jwtManager,
        Filesystem $filesystem
    ) {
        $this->entityManager = $entityManager;
        $this->validator = $validator;
        $this->artistRepository = $artistRepository;
        $this->serializer = $serializer;
        $this->jwtManager = $jwtManager;
        $this->filesystem = $filesystem;
    }

    #[Route('/album/{id}', name: 'update_album', methods: ['POST'])]
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
                    'message' => 'Authentification requise. Vous devez être connecté pour effectuer cette action.'
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }

            $album = $this->entityManager->getRepository(Album::class)->find($id);
            if (!$album) {
                return $this->json([
                    'error' => true,
                    'message' => 'Aucun album trouvé correspondant au nom fourni.'
                ], JsonResponse::HTTP_NOT_FOUND);
            }

            if ($album->getArtistUserIdUser() !== $user->getArtist()) {
                return $this->json([
                    'error' => true,
                    'message' => 'Vous n\'avez pas l\'autorisation pour accéder à cet album.'
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
                    'message' => 'Les paramètres fournis sont invalides. Veuillez vérifier les données soumises.'
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if ($year === null) {
                $year = 2024;
            }

            if ($title !== null) {
                if (strlen($title) < 1 || strlen($title) > 90) {
                    return $this->json([
                        'error' => true,
                        'message' => 'Erreur de validation des données.'
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }
                if (!preg_match('/^[a-zA-Z0-9\s\'"!@#$%^&*()_+=\-,.?;:]+$/u', $title)) {
                    return $this->json([
                        'error' => true,
                        'message' => 'Erreur de validation des données.'
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }
                $album->setTitle($title);
            }

            if ($categorie !== null) {
                $categorieArray = json_decode($categorie, true);
                if (!is_array($categorieArray) || empty($categorieArray)) {
                    return $this->json([
                        'error' => true,
                        'message' => 'Erreur de validation des données.'
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }
                $invalidCategories = ['rap', 'r\'n\'b', 'gospel', 'jazz', 'soul country', 'hip hop', 'Mike'];
                foreach ($categorieArray as $cat) {
                    if (in_array($cat, $invalidCategories)) {
                        return $this->json([
                            'error' => true,
                            'message' => 'Les catégories ciblées sont invalides.'
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
                        'message' => 'Le serveur ne peut pas décoder le contenu base64 en fichier binaire.'
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }

                $file = base64_decode($explodeData[1]);
                $fileSize = strlen($file);
                $minFileSize = 1 * 1024 * 1024;
                $maxFileSize = 7 * 1024 * 1024;

                if ($fileSize < $minFileSize || $fileSize > $maxFileSize) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => 'Le fichier envoyé est trop ou pas assez volumineux. Vous devez respecter la taille entre 1Mb et 7Mb.'
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
                }

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($file);

                if (!in_array($mimeType, ['image/jpeg', 'image/png'])) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => 'Erreur sur le format du fichier qui n\'est pas pris en compte.'
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
                        'message' => 'La valeur du champ visibility est invalide. Les valeurs autorisées sont 0 pour invisible, 1 pour visible.'
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
                'message' => 'Album mis à jour avec succès.'
            ], JsonResponse::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => true, 'message' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }    
}
