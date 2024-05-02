<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\UserRepository;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\ItemInterface;
use App\Service\EmailService;
use DateTime;

class UserController extends AbstractController
{

    private $userRepository;
    private $passwordEncoder;
    private $entityManager;
    private $serializer;
    private $jwtManager;
    private $tokenVerifier;

    public function __construct(
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        SerializerInterface $serializer,
        UserPasswordHasherInterface $passwordEncoder,
        JWTTokenManagerInterface $jwtManager,
        TokenManagementController $tokenVerifier,
    ) {
        $this->userRepository = $userRepository;
        $this->entityManager = $entityManager;
        $this->serializer = $serializer;
        $this->passwordEncoder = $passwordEncoder;
        $this->jwtManager = $jwtManager;
        $this->tokenVerifier = $tokenVerifier;
    }

    #[Route('/register', name: 'create_user', methods: 'POST')]
    public function createUser(Request $request): JsonResponse
    {
        try {
            //$idUser = $request->request->get('idUser');
            $firstname = $request->request->get('firstname');
            $lastName = $request->request->get('lastname');
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $dateBirth = $request->request->get('dateBirth');
            $tel = $request->request->has('tel') ? $request->request->get('tel') : null;
            $sexe = $request->request->has('sexe') ? $request->request->get('sexe') : null;

            if ($firstname === null || $lastName === null || $email === null || $password === null || $dateBirth === null) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Des champs obligatoires sont manquants.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if ( //strlen($idUser) > 90 || 
                strlen($firstname) > 55 || strlen($lastName) > 55 || strlen($email) > 80 || strlen($password) > 90 || (strlen($tel) > 0 && strlen($tel) > 15) || (strlen($sexe) > 0 && strlen($sexe) > 30)
            ) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Une ou plusieurs données sont éronnées (Trop longues)',
                    'data' => [
                        //'idUser' => $idUser,
                        'firstname' => $firstname,
                        'lastname' => $lastName,
                        'email' => $email,
                        'password' => $password,
                        'tel' => $tel,
                        'sexe' => $sexe,
                    ],
                ], JsonResponse::HTTP_CONFLICT);
            }

            $emailRegex = '/^\S+@\S+\.\S+$/';
            if (!preg_match($emailRegex, $email)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Le format de l\'email est invalide.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^\w\s]).{8,}$/';
            if (!preg_match($passwordRegex, $password)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre, un caractère spécial et 8 caractères minimum.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if ($tel == null) {
                $tel = '';
            } else {
                $phoneRegex = '/^(?!(\d)\1{9}$)[0-9]{10}$/';
                if (!preg_match($phoneRegex, $tel)) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => 'Le format du numéro de téléphone est invalide.',
                    ], JsonResponse::HTTP_BAD_REQUEST);
                }
            }

            $birthRegex = '/^(0[1-9]|[12][0-9]|3[01])\/(0[1-9]|1[0-2])\/\d{4}$/';
            if (!preg_match($birthRegex, $dateBirth)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Le format de la date de naissance est invalide. Le format attendu est JJ/MM/AAAA.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $dateBirth = str_replace('/', '-', $dateBirth);

            if ($sexe == null) {
                $sexe = 1;
            } else {
                if ($sexe !== null && !in_array($sexe, ['0', '1'])) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => 'La valeur du champ sexe est invalide. Les valeurs autorisées sont 0 pour Femme, 1 pour Homme.',
                    ], JsonResponse::HTTP_BAD_REQUEST);
                }
            }

            $sexe = ($sexe === '1');

            $date = new \DateTime($dateBirth);
            $now = new \DateTime();
            $age = $now->diff($date)->y;
            if ($age < 12) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "L'utilisateur doit avoir au moins 12 ans.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $existingUser = $this->userRepository->findOneBy(['email' => $email]);
            if ($existingUser) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Cet email est déjà utilisé par un autre compte.',
                ], JsonResponse::HTTP_CONFLICT);
            }

            $user = new User();
            //$user->setIdUser($idUser);
            $user->setFirstName($firstname);
            $user->setLastName($lastName);
            $user->setEmail($email);
            $user->setPassword($this->passwordEncoder->hashPassword($user, $password));
            $user->setTel($tel);
            $user->setSexe($sexe);
            $user->setBirth(new \DateTime($dateBirth));
            $user->setCreateAt(new \DateTimeImmutable());
            $user->setUpdateAt(new \DateTimeImmutable());
            $user->setIsActive(true);

            $sexeLabel = $sexe ? 'Homme' : 'Femme';

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $serializedUser = $this->serializer->serialize($user, 'json', ['ignored_attributes' => ['id', 'password', 'idUser', 'artist', 'salt', 'username', 'userIdentifier', 'roles', 'resetToken', 'resetTokenExpired', 'resetTokenExpiration', 'isActive', 'followedArtist', 'birth']]);
            $userArray = json_decode($serializedUser, true);
            $user = $this->userRepository->findOneBy(['email' => $email]);

            return $this->json([
                'error' => false,
                'message' => "L'utilisateur a bien été créé avec succès.",
                'user' => [
                    'firstname' => $user->getFirstName(),
                    'lastname' => $user->getLastName(),
                    'email' => $user->getEmail(),
                    'tel' => $user->getTel(),
                    'sexe' => $sexeLabel,
                    'dateBirth' => $user->getBirth()->format('Y-m-d'),
                    'createAt' => $user->getCreateAt()->format('Y-m-d\TH:i:sP'),
                    'updateAt' => $user->getUpdateAt()->format('Y-m-d\TH:i:sP'),
                ],
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }


    #[Route('/login', name: 'login_user', methods: 'POST')]
    public function login(Request $request, CacheItemPoolInterface $cache, JWTTokenManagerInterface $jwtManager): JsonResponse
    {
        try {
            $email = $request->request->get('email');
            $password = $request->request->get('password');

            if ($email === null || $password === null) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Email/password manquants.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
            $emailRegex = '/^\S+@\S+\.\S+$/';
            if (!preg_match($emailRegex, $email)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le format de l'email est invalide.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^\w\s]).{8,}$/';
            if (!preg_match($passwordRegex, $password)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre, un caractère spécial et avoir 8 caractères minimum.",
                ], JsonResponse::HTTP_FORBIDDEN);
            }

            $user = $this->userRepository->findOneBy(['email' => $email]);
            if ($user && !$user->getIsActive()) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le compte n'est plus actif ou est suspendu.",
                ], JsonResponse::HTTP_FORBIDDEN);
            }


            $cacheKeyAttempts = 'login_attempts_' . md5($email);
            $cacheKeyCooldown = 'login_cooldown_' . md5($email);

            $loginAttemptsItem = $cache->getItem($cacheKeyAttempts);
            $loginAttemptsData = $loginAttemptsItem->get();
            $loginAttemptsValue = $loginAttemptsData['value'] ?? 0;

            $cooldownCacheItem = $cache->getItem($cacheKeyCooldown);
            $cooldownActive = $cooldownCacheItem->isHit();

            if ($loginAttemptsValue >= 5 && $cooldownActive) {
                $metadata = $loginAttemptsData['metadata'] ?? [];
                if (isset($metadata['expiry'])) {
                    $expiryTimestamp = $metadata['expiry'];
                    $remainingMinutes = ceil(($expiryTimestamp - time()) / 60);

                    return new JsonResponse([
                        'error' => true,
                        'message' => "Trop de tentatives de connexion (5 max). Veuillez réessayer ultérieurement - " . $remainingMinutes . " min d'attente.",
                    ], JsonResponse::HTTP_TOO_MANY_REQUESTS);
                }
            }

            $user = $this->userRepository->findOneBy(['email' => $email]);
            if (!$user) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Email/password incorrect',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if (!$this->passwordEncoder->isPasswordValid($user, $password)) {
                $loginAttemptsValue++;
                $loginAttemptsItem->set([
                    'value' => $loginAttemptsValue,
                    'metadata' => [
                        'expiry' => time() + 300,
                    ]
                ]);
                $cache->save($loginAttemptsItem);

                if ($loginAttemptsValue >= 5) {
                    $cooldownCacheItem->set(true);
                    $cooldownCacheItem->expiresAfter(300);
                    $cache->save($cooldownCacheItem);
                }

                return new JsonResponse([
                    'error' => true,
                    'message' => 'Email/password incorrect',
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }

            $cache->deleteItem($cacheKeyAttempts);
            $artist = $user->getArtist();

            $labelName = null;
            if ($artist) {
                $label = $artist->getLabel();
                if ($label) {
                    $labelName = $label->getName();
                }
            }

            $albumsArray = [];
            $songsArray = [];

            $artistArray = [];

            $artist = $user->getArtist();

            $sexeLabel = $user->getSexe() ? 'Homme' : 'Femme';

            if ($artist !== null) {

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

                $albums = $artist->getAlbums();
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
                foreach ($songs as $song) {
                    $songsArray[] = [
                        'id' => $song->getId(),
                        'title' => $song->getTitle(),
                        'cover' => $song->getCover(),
                        'createdAt' => $song->getCreateAt()->format('Y-m-d'),
                    ];
                }

                /*$artistArray = [
                    'id' => $artist->getId(),
                    'fullname' => $artist->getFullname(),
                    'description' => $artist->getDescription(),
                    'label' => $labelName,
                    'createdAt' => $artist->getCreateAt()->format('Y-m-d'),
                    'avatar' => $avatarPath,
                    'follower' => $followersCount,
                    'albums' => $albumsArray,
                    'songs' => $songsArray,
                ];*/
            } else {
                $artistArray = [];
            }

            $userArray = [
                'firstname' => $user->getFirstName(),
                'lastname' => $user->getLastName(),
                'email' => $user->getEmail(),
                'tel' => $user->getTel(),
                'sexe' => $sexeLabel,
                'birth' => $user->getBirth()->format('d-m-Y'),
                'createAt' => $user->getCreateAt()->format('Y-m-d\TH:i:sP'),
                'artist' => $artistArray,
            ];

            $token = $jwtManager->create($user);

            return $this->json([
                'error' => false,
                'message' => "L'utilisateur a été authentifié avec succès",
                'user' => $userArray,
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }

    #[Route('/user', name: 'update_user', methods: 'POST')]
    public function updateUser(Request $request): JsonResponse
    {
        try {

            $dataMiddellware = $this->tokenVerifier->checkToken($request);
            if (gettype($dataMiddellware) == 'boolean') {
                return $this->json($this->tokenVerifier->sendJsonErrorToken($dataMiddellware), JsonResponse::HTTP_UNAUTHORIZED);
            }
            $user = $dataMiddellware;

            $firstName = $request->request->get('firstname');
            $lastName = $request->request->get('lastname');
            $tel = $request->request->get('tel');
            $sexe = $request->request->get('sexe');

            if ($sexe !== null && !in_array($sexe, ['0', '1'])) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'La valeur du champ sexe est invalide. Les valeurs autorisées sont 0 pour Femme, 1 pour Homme.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if (isset($tel)) {
                $phoneRegex = '/^(?!(\d)\1{9}$)[0-9]{10}$/';
                if (!preg_match($phoneRegex, $tel)) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => 'Le format du numéro de téléphone est invalide.',
                    ], JsonResponse::HTTP_BAD_REQUEST);
                }
            }

            if (isset($firstName)) {
                if (strlen($firstName) < 1 || strlen($firstName) > 60) {
                    return new JsonResponse(
                        [
                            'error' => true,
                            'message' => 'Erreur de validation des données.',
                        ],
                        JsonResponse::HTTP_UNPROCESSABLE_ENTITY
                    );
                }
            }

            if (isset($lastName)) {
                if (strlen($lastName) < 1 || strlen($lastName) > 60) {
                    return new JsonResponse(
                        [
                            'error' => true,
                            'message' => 'Erreur de validation des données.',
                        ],
                        JsonResponse::HTTP_UNPROCESSABLE_ENTITY
                    );
                }
            }

            $keys = array_keys($request->request->all());
            $allowedKeys = ['firstname', 'lastname', 'tel', 'sexe'];
            $diff = array_diff($keys, $allowedKeys);
            if (count($diff) > 0) {
                return new JsonResponse(
                    [
                        'error' => true,
                        'message' => 'Erreur de validation des données.',
                    ],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY
                );
            }



            $existingUser = $this->userRepository->findOneBy(['tel' => $tel]);
            if ($existingUser && $tel !== null) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Conflit de données. Le numéro de téléphone est déjà utilisé par un autre utilisateur.',
                ], JsonResponse::HTTP_CONFLICT);
            }

            if ($firstName !== null) {
                $user->setFirstName($firstName);
            }

            if ($lastName !== null) {
                $user->setLastName($lastName);
            }

            if ($tel !== null) {
                $user->setTel($tel);
            }

            if ($sexe !== null) {
                $user->setSexe($sexe);
            }

            if (empty($firstName) && empty($lastName) && empty($sexe) && empty($tel)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Les données fournies sont invalides ou incomplètes.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return $this->json([
                'error' => false,
                'message' => 'Votre inscription a bien été prise en compte',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }

    #[Route('/password-lost', name: 'password_lost', methods: ['POST'])]
    public function passwordLost(Request $request, CacheItemPoolInterface $cache): JsonResponse
    {
        try {
            $email = $request->request->get('email');

            if (empty($email)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Email manquant. Veuillez fournir votre email pour la récupération du mot de passe.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $emailRegex = '/^\S+@\S+\.\S+$/';
            if (!preg_match($emailRegex, $email)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le format de l'e-mail est invalide. Veuillez entrer un e-mail valide.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $user = $this->userRepository->findOneBy(['email' => $email]);
            if (!$user) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Aucun compte n'est associé à cet email. Veuillez vérifier et réessayer.",
                ], JsonResponse::HTTP_NOT_FOUND);
            }




            $cacheKeyAttempts = 'reset_password_attempts_' . md5($email);
            $cacheKeyCooldown = 'reset_password_cooldown_' . md5($email);

            $resetPasswordAttemptsItem = $cache->getItem($cacheKeyAttempts);
            $resetPasswordAttemptsData = $resetPasswordAttemptsItem->get();
            $resetPasswordAttemptsValue = $resetPasswordAttemptsData['value'] ?? 0;

            $cooldownCacheItem = $cache->getItem($cacheKeyCooldown);
            $cooldownActive = $cooldownCacheItem->isHit();

            if ($resetPasswordAttemptsValue >= 3 && $cooldownActive) {
                $metadata = $resetPasswordAttemptsData['metadata'] ?? [];
                if (isset($metadata['expiry'])) {
                    $metadata = $resetPasswordAttemptsData['metadata'] ?? [];
                    $expiryTimestamp = $metadata['expiry'];
                    $remainingMinutes = ceil(($expiryTimestamp - time()) / 60);

                    return new JsonResponse([
                        'error' => true,
                        'message' => "Trop de demandes de réinitialisation de mot de passe ( 3 max ). Veuillez attendre avant de réessayer ( Dans $remainingMinutes min).",
                    ], JsonResponse::HTTP_TOO_MANY_REQUESTS);
                }
            }

            if ($user) {

                $resetToken = bin2hex(random_bytes(32));
                $user->setResetToken($resetToken);
                $user->setResetTokenExpiration(new \DateTimeImmutable('+2 minutes'));

                $resetPasswordAttemptsValue++;
                $resetPasswordAttemptsItem->set([
                    'value' => $resetPasswordAttemptsValue,
                    'metadata' => [
                        'expiry' => time() + 300, // 5 minutes
                    ]
                ]);
                $cache->save($resetPasswordAttemptsItem);

                if ($resetPasswordAttemptsValue >= 3) {
                    $cooldownCacheItem->set(true);
                    $cooldownCacheItem->expiresAfter(300); // 5 minutes
                    $cache->save($cooldownCacheItem);
                }

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                return new JsonResponse([
                    'success' => true,
                    'message' => "Un email de réinitialisation de mot de passe a été envoyé à votre adresse email. Veuillez suivre les instructions contenues dans l'email pour réinitialiser votre mot de passe.",
                    'token' => $resetToken,
                ]);
            }
            $cache->deleteItem($cacheKeyAttempts);
            $cache->deleteItem($cacheKeyCooldown);
            return new JsonResponse([
                'success' => true,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/reset-password/{token}', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $token = $request->attributes->get('token');
            $user = $this->userRepository->findOneBy(['resetToken' => $token]);
            $password = $request->request->get('password');

            if (!$user || empty($token)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Token de réinitialisation manquant ou invalide. Veuillez utiliser le lien fourni dans l'email de réinitialisation de mot de passe.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^\w\s]).{8,}$/';
            if (empty($password)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Veuillez fournir un nouveau mot de passe.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            } else if (!preg_match($passwordRegex, $password)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le nouveau mot de passe ne respecte pas les critères requis. Il doit contenir au moins une majuscule, une minuscule, un chiffre, un caractère spécial et être composé d'au moins 8 caractères.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }




            if ($user->isResetTokenExpired()) {
                $expirationTime = $user->getResetTokenExpiration();
                $currentTime = new DateTime();

                if ($currentTime >= $expirationTime) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "Votre token de réinitialisation de mot de passe a expiré. Veuillez refaire une demande de réinitialisation de mot de passe.",
                    ], JsonResponse::HTTP_GONE);
                }
            }

            $user->setPassword($this->passwordEncoder->hashPassword($user, $password));
            $user->setResetToken(null);
            $user->setResetTokenExpiration(null);
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => "Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.",
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }

    #[Route('/account-deactivation', name: 'desactivate_user', methods: 'DELETE')]
    public function desactivateUser(Request $request): JsonResponse
    {
        try {
            $dataMiddellware = $this->tokenVerifier->checkToken($request);
            if (gettype($dataMiddellware) == 'boolean') {
                return $this->json($this->tokenVerifier->sendJsonErrorToken($dataMiddellware), JsonResponse::HTTP_UNAUTHORIZED);
            }
            $user = $dataMiddellware;

            if (!$user) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Authentification requise. Vous devez être connecter pour effectuer cette action.',
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }

            if (!$user->getIsActive()) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Le compte est déjà désactivé.',
                ], JsonResponse::HTTP_GONE);
            }

            $user->setIsActive(false);
            $this->entityManager->flush();

            return $this->json([
                'error' => 'false',
                'message' => 'Votre compte a été désactivé avec succès. Nous sommes désolés de vous voir partir.',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Erreur: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }
}
