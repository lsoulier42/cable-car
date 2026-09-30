<?php

namespace App\Repository;

use App\Entity\AgentRun;
use App\Entity\AgentStep;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends AbstractRepository<AgentStep>
 */
class AgentStepRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentStep::class);
    }

    /**
     * @return list<AgentStep>
     */
    public function findForRun(AgentRun $run): array
    {
        /** @var list<AgentStep> $steps */
        $steps = $this->createQueryBuilder('step')
            ->andWhere('step.run = :run')
            ->setParameter('run', $run)
            ->orderBy('step.sequence', 'ASC')
            ->getQuery()
            ->getResult();

        return $steps;
    }
}
