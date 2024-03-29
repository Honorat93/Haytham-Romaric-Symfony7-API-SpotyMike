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

class UserController extends AbstractController
{

    private $userRepository;
    private $passwordEncoder;
    private $entityManager;
    private $serializer;

    public function __construct(
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        SerializerInterface $serializer,
        UserPasswordHasherInterface $passwordEncoder,
    ) {
        $this->userRepository = $userRepository;
        $this->entityManager = $entityManager;
        $this->serializer = $serializer;
        $this->passwordEncoder = $passwordEncoder;
    }

    #[Route('/user/add', name: 'create_user', methods: 'POST')]
    public function createUser(Request $request): JsonResponse
    {
        try{
            $idUser = $request->request->get('idUser');
            $name = $request->request->get('name');
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $tel = $request->request->get('tel');

            if ($idUser === null || $name === null || $email === null || $password === null) {
                throw new \Exception('Manque des données');
            }

            $emailRegex = '/^\S+@\S+\.\S+$/';
            if (!preg_match($emailRegex, $email)) {
                throw new \Exception('Format mail pas bon');
            }
            $phoneRegex = '/^\d{10}$/';
            if (!preg_match($phoneRegex, $tel)) {
                throw new \Exception('Format tel pas bon');
            }

            if (!$email || !$password) {
                throw new \Exception('Manque des données');
            }

            $existingUser = $this->userRepository->findOneBy(['email' => $email]);
            if ($existingUser) {
                throw new \Exception('User existe déjà');
            }

            $existingUser = $this->userRepository->findOneBy(['idUser' => $idUser]);
            if ($existingUser) {
                throw new \Exception('Id existe déjà');
            }

            if (strlen($idUser) > 90) {
                throw new \Exception('id trop long');
            }

            if (strlen($name) > 55) {
                throw new \Exception('nom trop long');
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

            $user = new User();
            $user->setIdUser($idUser);
            $user->setName($name);
            $user->setEmail($email);
            $user->setPassword($this->passwordEncoder->hashPassword($user, $password));
            $user->setTel($tel);
            $user->setCreateAt(new \DateTimeImmutable());
            $user->setUpdateAt(new \DateTimeImmutable());
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            
            $serealizedUser = $this->serializer->serialize($user, 'json');
            $user = $this->userRepository->findOneBy(['idUser' => $idUser]);
            return $this->json([
                'message' => 'User ajouté',
                'user' => json_decode($serealizedUser, true),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }

    #[Route('/user/edit', name: 'edit_user', methods: 'PUT')]
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
    }

    #[Route('/user/{idUser}', name: 'get_user', methods: ['GET'])]
    public function showUser(string $idUser): JsonResponse
    {
        try {
            $user = $this->userRepository->findOneBy(['idUser' => $idUser]);

            if (!$user) {
                throw new \Exception('User pas trouvé');
            }

            $serializedUser = $this->serializer->serialize($user, 'json');

            return $this->json([
                'user' => json_decode($serializedUser, true),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Erreur: ' . $e->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }
    }
}

