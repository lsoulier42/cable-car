<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @template T of object
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
     * @param class-string    $className
     */
    public function __construct(ManagerRegistry $registry, string $className)
    {
        parent::__construct($registry, $className);
    }

    public function createOrUpdate(mixed $entity, bool $flush = true): void
    {
        $entityManager = $this->getEntityManager();

        if ($entity->getId() === null) {
            $entityManager->persist($entity);
        }

        if ($flush) {
            $entityManager->flush();
        }
    }

    public function remove(mixed $entity, bool $flush = true): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->remove($entity);

        if ($flush) {
            $entityManager->flush();
        }
    }

    /**
     * @return T|null
     */
    public function findOneByUuid(string $uuid): ?object
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }
}
