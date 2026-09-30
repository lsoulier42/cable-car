<?php

namespace App\Controller;

use App\Dto\RegisterInput;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Public registration endpoint: creaates an active user with a single ROLE_USER.
 * Validation errors from #[MapRequestPayload] are returned as 422 with {"violations": [...]}.
 */
final class RegistrationController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterInput $input): JsonResponse
    {
        $user = new User();
        $user->setEmail($input->email);
        $user->setFirstName($input->firstName);
        $user->setLastName($input->lastName);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $json = $this->serializer->serialize($user, 'json', ['groups' => 'user:read']);

        return new JsonResponse($json, Response::HTTP_CREATED, [], true);
    }
}
