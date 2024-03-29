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

            if (strlen($firstName) > 55) {
                throw new \Exception('nom trop long');
            }
            if (strlen($lastName) > 55) {
                throw new \Exception('prenom trop long');
            }

            if (strlen($email) > 80) {
                throw new \Exception('mail trop long');
            }

            if (strlen($password) > 90) {
                throw new \Exception('mdp trop long');
            }

            if (strlen($tel) > 15) {
                throw new \Exception('tel trop long');
            }

            if (strlen($sexe) > 30) {
                throw new \Exception('sexe trop long');
            }

            $emailRegex = '/^\S+@\S+\.\S+$/';
            if (!preg_match($emailRegex, $email)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Format mail pas bon',
                ], JsonResponse::HTTP_NOT_ACCEPTABLE);
            }
            $phoneRegex = '/^\d{10}$/';
            if (!preg_match($phoneRegex, $tel)) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Format tel pas bon',
                ], JsonResponse::HTTP_NOT_ACCEPTABLE);
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
                    'message' => 'Un compte utilisant cet adresse mail existe est déjà enregistré',
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
            $user->setCreateAt(new \DateTimeImmutable());
            $user->setUpdateAt(new \DateTimeImmutable());
            $user->setBirth(\DateTime::createFromFormat('Y-m-d', $birth));
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            
            $serealizedUser = $this->serializer->serialize($user, 'json' , ['ignored_attributes' => ['password', 'idUser', 'artist']]);
            $user = $this->userRepository->findOneBy(['idUser' => $idUser]);

            $token = $this->jwtManager->create($user);
            
            return $this->json([
                'error' => 'false',
                'message' => "L'utilisateur a bien été créé avec succès.",
                'user' => json_decode($serealizedUser, true),
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
    }

    #[Route('/user/delete/{idUser}', name: 'delete_user', methods: ['DELETE'])]
    public function deleteUser(string $idUser): JsonResponse
    {
        try {
            $user = $this->userRepository->findOneBy(['idUser' => $idUser]);

            if (!$user) {
                throw new \Exception('User pas trouvé');
            }

            $this->entityManager->remove($user);
            $this->entityManager->flush();

            return $this->json([
                'message' => 'User supprimé',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Erreur: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    } */

    

    
}



