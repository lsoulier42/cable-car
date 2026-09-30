<?php

namespace App\Repository;

use App\Entity\AgentRun;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractRepository<AgentRun>
 */
class AgentRunRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentRun::class);
    }

    public function findOneByUuid(string $uuid): ?AgentRun
    {
        /** @var AgentRun|null $run */
        $run = $this->findOneBy(['uuid' => $uuid]);

        return $run;
    }

    /**
     * @return list<AgentRun>
     */
    public function findRecentForUser(?User $user, int $limit = 50): array
    {
        $builder = $this->createQueryBuilder('run')
            ->orderBy('run.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $user) {
            $builder->andWhere('run.user = :user')->setParameter('user', $user);
        }

        /** @var list<AgentRun> $runs */
        $runs = $builder->getQuery()->getResult();

        return $runs;
    }
}
