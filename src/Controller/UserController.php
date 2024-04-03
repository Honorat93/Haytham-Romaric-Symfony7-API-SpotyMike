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
            try{
                $idUser = $request->request->get('idUser');
                $firstName = $request->request->get('firstName');
                $lastName = $request->request->get('lastName');
                $email = $request->request->get('email');
                $password = $request->request->get('password');
                $tel = $request->request->get('tel');
                $birth = $request->request->get('birth');
                $sexe = $request->request->get('sexe');

                if ($firstName === null || $lastName === null || $email === null || $password === null || $birth === null) {
                    return new JsonResponse([
                            'error' => true,
                            'message' => 'Une ou plusieurs données obligatoires sont manquantes',
                        ], JsonResponse::HTTP_BAD_REQUEST);
                }

                if (strlen($idUser) > 90 || strlen($firstName) > 55 || strlen($lastName) > 55 || strlen($email) > 80 || strlen($password) > 90 || strlen($tel) > 15 || strlen($sexe) > 30) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => 'Une ou plusieurs données sont éronnées (Trop longues)',
                        'data' => [
                            'idUser' => $idUser,
                            'firstName' => $firstName,
                            'lastName' => $lastName,
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
                        'message' => 'Une ou plusieurs données sont éronnées',
                        'data' => [
                            'email' => $email,
                        ],
                    ], JsonResponse::HTTP_CONFLICT);
                }

                $phoneRegex = '/^\d{10}$/';
                if (!preg_match($phoneRegex, $tel)) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => 'Une ou plusieurs données sont éronnées',
                        'data' => [
                            'tel' => $tel,
                        ],
                    ], JsonResponse::HTTP_CONFLICT);
                }

                $date = new \DateTime($birth);
                $now = new \DateTime();
                $age = $now->diff($date)->y;
                if ($age < 12) {
                    return new JsonResponse([
                        'error' => true,
                        'message' => "L'âge de l'utilisateur ne permet pas (12 ans)",
                    ], JsonResponse::HTTP_NOT_ACCEPTABLE);
                }

                $existingUser = $this->userRepository->findOneBy(['email' => $email]);
                if ($existingUser) {
                return new JsonResponse([
                        'error' => true,
                        'message' => 'Un compte utilisant cet adresse mail est déjà enregistré',
                    ], JsonResponse::HTTP_CONFLICT);
                }


                $user = new User();
                $user->setIdUser($idUser);
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
                
                $serealizedUser = $this->serializer->serialize($user, 'json' , ['ignored_attributes' => ['id','password', 'idUser', 'artist']]);
                $userArray = json_decode($serealizedUser, true);
                $userArray['birth'] = $user->getBirth()->format('Y-m-d');
                $user = $this->userRepository->findOneBy(['idUser' => $idUser]);
                
                return $this->json([
                    'error' => 'false',
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
                    'message' => 'Email/password manquants',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }
            $emailRegex = '/^\S+@\S+\.\S+$/';
            if (!preg_match($emailRegex, $email)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Email/password incorrect',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $cacheKeyAttempts = 'login_attempts_' . md5($email);
            $cacheKeyCooldown = 'login_cooldown_' . md5($email);

            $loginAttempts = $cache->getItem($cacheKeyAttempts);
            $loginAttemptsValue = $loginAttempts->get() ?? 0;

            $cooldownCacheItem = $cache->getItem($cacheKeyCooldown);
            $cooldownActive = $cooldownCacheItem->isHit();

            if ($loginAttemptsValue >= 5 && $cooldownActive) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Trop de tentatives sur l\'email ' . $email . ' (5 max) - Veuillez patienter (2min)',
                ], JsonResponse::HTTP_TOO_MANY_REQUESTS);
            } elseif ($loginAttemptsValue >= 5 && !$cooldownActive) {
                $cache->deleteItem($cacheKeyAttempts);
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
                $loginAttempts->set($loginAttemptsValue);
                $cache->save($loginAttempts);

                if ($loginAttemptsValue >= 5) {
                    $cooldownCacheItem->set(true);
                    $cooldownCacheItem->expiresAfter(120);
                    $cache->save($cooldownCacheItem);
                }

                return new JsonResponse([
                    'error' => true,
                    'message' => 'Email/password incorrect',
                ], JsonResponse::HTTP_UNAUTHORIZED);
            }

            $cache->deleteItem($cacheKeyAttempts);

            $serializedUser = $this->serializer->serialize($user, 'json' , ['ignored_attributes' => ['id','password', 'idUser', 'roles','salt','username','userIdentifier','updateAt']]);
            $userArray = json_decode($serializedUser, true);
            $userArray['birth'] = $user->getBirth()->format('d-m-Y');

            $token = $jwtManager->create($user);

            return $this->json([
                'error' => 'false',
                'message' => "L'utilisateur a été authentifié succès",
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



