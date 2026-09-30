<?php

namespace App\Controller;

use App\Dto\MePayload;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Current authenticated user profile (any role): GET /api/me and PATCH /api/me.
 * The route is protected at firewall level (IS_AUTHENTICATED_FULLY).
 */
final class MeController extends AbstractController
{
    #[Route('/api/me', name: 'api_me_show', methods: ['GET'])]
    public function show(#[CurrentUser] ?User $user): JsonResponse
    {
        return $this->json($user, Response::HTTP_OK, [], ['groups' => 'me:read']);
    }

    #[Route('/api/me', name: 'api_me_update', methods: ['PATCH'])]
    public function update(
        #[CurrentUser] ?User $user,
        #[MapRequestPayload] MePayload $payload,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $userRepository,
    ): JsonResponse {
        if (null === $user) {
            return new JsonResponse(null, Response::HTTP_UNAUTHORIZED);
        }

        $existing = $userRepository->findOneByEmail($payload->email ?? '');
        if (null !== $existing && $existing->getId() !== $user->getId()) {
            return $this->json(
                ['violations' => [['propertyPath' => 'email', 'message' => 'register.email.already_used']]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $user->setEmail($payload->email ?? (string) $user->getEmail());
        $user->setFirstName($payload->firstName);
        $user->setLastName($payload->lastName);

        if (null !== $payload->plainPassword) {
            $user->setPassword($passwordHasher->hashPassword($user, $payload->plainPassword));
        }

        $entityManager->flush();

        return $this->json($user, Response::HTTP_OK, [], ['groups' => 'me:read']);
    }
}
