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

class UserController extends AbstractController
{

    private $userRepository;
    private $passwordEncoder;
    private $entityManager;
    private $serializer;
    private $jwtManager;

    public function __construct(
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        SerializerInterface $serializer,
        UserPasswordHasherInterface $passwordEncoder,
        JWTTokenManagerInterface $jwtManager,
    ) {
        $this->userRepository = $userRepository;
        $this->entityManager = $entityManager;
        $this->serializer = $serializer;
        $this->passwordEncoder = $passwordEncoder;
        $this->jwtManager = $jwtManager;
    }

    #[Route('/register', name: 'create_user', methods: 'POST')]
    public function createUser(Request $request): JsonResponse
    {
        try {
            //$idUser = $request->request->get('idUser');
            $firstName = $request->request->get('firstname');
            $lastName = $request->request->get('lastname');
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $birth = $request->request->get('birth');
            $tel = $request->request->has('tel') ? $request->request->get('tel') : null;
            $sexe = $request->request->has('sexe') ? $request->request->get('sexe') : null;

            if ($firstName === null || $lastName === null || $email === null || $password === null || $birth === null) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Des champs obligatoires sont manquantes.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if ( //strlen($idUser) > 90 || 
                strlen($firstName) > 55 || strlen($lastName) > 55 || strlen($email) > 80 || strlen($password) > 90 || (strlen($tel) > 0 && strlen($tel) > 15) || (strlen($sexe) > 0 && strlen($sexe) > 30)
            ) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Une ou plusieurs données sont éronnées (Trop longues)',
                    'data' => [
                        //'idUser' => $idUser,
                        'firstname' => $firstName,
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
                    'data' => [
                        'email' => $email,
                    ],
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^\w\s]).{8,}$/';
            if (!preg_match($passwordRegex, $password)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => "Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre, un caractère spécial et 8 caractères minimum.",
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if ($tel !== null) {
                $phoneRegex = '/^\d{10}$/';
                if (!preg_match($phoneRegex, $tel)) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => 'Le format du numéro de téléphone est invalide.',
                        'data' => [
                            'tel' => $tel,
                        ],
                    ], JsonResponse::HTTP_BAD_REQUEST);
                }
            }

            $birthRegex = '/^([0-2][0-9]|(3)[0-1])\/(0[1-9]|1[0-2])\/\d{4}$/';
            if (!preg_match($birthRegex, $birth)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Le format de la date de naissance est invalide. Le format attendu est JJ/MM/AAAA.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            if ($sexe !== null && !in_array($sexe, ['0', '1'])) {
                return new JsonResponse([
                        'error' => true,
                        'message' => 'La valeur du champ sexe est invalide. Les valeurs autorisées sont 0 pour Femme, 1 pour Homme.',
                    ], JsonResponse::HTTP_BAD_REQUEST);
            }

            
            $date = new \DateTime($birth);
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
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setEmail($email);
            $user->setPassword($this->passwordEncoder->hashPassword($user, $password));
            $user->setTel($tel);
            $user->setSexe($sexe);
            $user->setBirth(new \DateTime($birth));
            $user->setCreateAt(new \DateTimeImmutable());
            $user->setUpdateAt(new \DateTimeImmutable());

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $serializedUser = $this->serializer->serialize($user, 'json', ['ignored_attributes' => ['id', 'password', 'idUser', 'artist', 'salt', 'username', 'userIdentifier', 'roles']]);
            $userArray = json_decode($serializedUser, true);
            $userArray['birth'] = $user->getBirth()->format('Y-m-d');
            $user = $this->userRepository->findOneBy(['email' => $email]);

            return $this->json([
                'error' => false,
                'message' => "L'utilisateur a bien été créé avec succès.",
                'user' => $userArray,
            ]);
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
                    'message' => "Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre, un caractère spécial et 8 caractères minimum.",
                ], JsonResponse::HTTP_BAD_REQUEST);
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
                    $remainingMinutes = ($expiryTimestamp - time()) / 60;

                    return new JsonResponse([
                        'error' => true,
                        'message' => "Trop de tentatives de connexion (5 max). Veuillez réessayer ultérieurement - " . $remainingMinutes . "min d'attente.",
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

            $userArray = [
                'firstname' => $user->getFirstName(),
                'lastname' => $user->getLastName(),
                'email' => $user->getEmail(),
                'tel' => $user->getTel(),
                'sexe' => $user->getSexe(),
                'birth' => $user->getBirth()->format('d-m-Y'),
                'createAt' => $user->getCreateAt()->format('Y-m-d\TH:i:sP'),
                'artist' => $artist ? [
                    'label' => $artist->getLabel(),
                    'description' => $artist->getDescription(),
                    'fullname' => $artist->getFullname(),
                ] : null,
            ];

            $token = $jwtManager->create($user);

            return $this->json([
                'error' => 'false',
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



    /*     #[Route('/user/edit', name: 'edit_user', methods: 'PUT')]
        public function editUser(Request $request): JsonResponse
        {
            try {
                $idUser = $request->request->get('idUser');
                $name = $request->request->get('name');
                $email = $request->request->get('email');
                $tel = $request->request->get('tel');

                $user = $this->userRepository->findOneBy(['idUser' => $idUser]);
                if (!$user) {
                    throw new \Exception('User pas trouvé');
                }

                if ($name !== null) {
                    $user->setName($name);
                }
                if ($email !== null) {
                    $emailRegex = '/^\S+@\S+\.\S+$/';
                    if (!preg_match($emailRegex, $email)) {
                        throw new \Exception('Format mail pas bon');
                    }
                    $user->setEmail($email);
                }
                if ($tel !== null) {
                    $phoneRegex = '/^\d{10}$/';
                    if (!preg_match($phoneRegex, $tel)) {
                        throw new \Exception('Format tel pas bon');
                    }
                    $user->setTel($tel);
                }

                $user->setName($name);
                $user->setEmail($email);
                $user->setTel($tel);

                $user->setUpdateAt(new \DateTimeImmutable());
                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $serializedUser = $this->serializer->serialize($user, 'json');

                return $this->json([
                    'message' => 'User modifié',
                    'user' => json_decode($serializedUser, true),
                ]);
            } catch (\Exception $e) {
                return new JsonResponse([
                    'error' => 'Error: ' . $e->getMessage(),
                ], Response::HTTP_NOT_FOUND);
            }
        }*/

    #[Route('/user', name: 'delete_user', methods: ['DELETE'])]
    public function deleteUser(): JsonResponse
    {
        try {

            $currentUser = $this->getUser()->getUserIdentifier();

            $user = $this->userRepository->findOneBy(['email' => $currentUser]);

            $this->entityManager->remove($user);
            $this->entityManager->flush();

            return $this->json([
                'error' => 'false',
                'message' => 'Votre compte a été supprimé avec succès',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Erreur: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }
}
