<?php

namespace App\Agent\Http;

use App\Entity\AgentRun;
use App\Entity\User;
use App\Repository\AgentRunRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Resolves a run from its UUID and enforces ownership.
 *
 * Runs are private to their creator; administrators can inspect every run.
 */
final class AgentRunAccess
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function find(string $uuid, ?User $user): AgentRun
    {
        $run = $this->runs->findOneByUuid($uuid);

        if (null === $run) {
            throw new NotFoundHttpException(sprintf('Run "%s" not found.', $uuid));
        }

        $owner = $run->getUser();
        if (null !== $owner && null !== $user && $owner->getId() === $user->getId()) {
            return $run;
        }

        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return $run;
        }

        throw new AccessDeniedHttpException('This run belongs to another user.');
    }
}
