<?php

namespace App\Helper;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;

/**
 * Stateless helpers to build Doctrine QueryBuilder instances.
 *
 * Extracted from AbstractRepository so persistence concerns stay separated
 * from query construction.
 */
final class QueryBuilderHelper
{
    private function __construct()
    {
    }

    public static function addFieldLike(
        QueryBuilder $queryBuilder,
        string $alias,
        string $fieldName,
        mixed $fieldValue,
    ): QueryBuilder {
        $orx = new Orx();
        self::formatOrxLike($queryBuilder, $orx, $alias, $fieldName, $fieldValue);

        return $queryBuilder->andWhere($queryBuilder->expr()->orX($orx));
    }

    private static function formatOrxLike(
        QueryBuilder $queryBuilder,
        Orx $orx,
        string $alias,
        string $fieldName,
        mixed $fieldValue,
    ): void {
        $fieldWithAlias = "$alias.$fieldName";
        $likeVersions = ["%$fieldValue%", "$fieldValue%", "%$fieldValue"];

        foreach ($likeVersions as $version) {
            $orx->add(
                $queryBuilder->expr()->like(
                    $fieldWithAlias,
                    $queryBuilder->expr()->literal($version),
                ),
            );
        }
    }

    public static function addFieldAndWhere(
        QueryBuilder $queryBuilder,
        string $alias,
        string $fieldName,
        mixed $fieldValue,
    ): QueryBuilder {
        $parameterName = $fieldName;

        return $queryBuilder
            ->andWhere("$alias.$fieldName = :$parameterName")
            ->setParameter($parameterName, $fieldValue);
    }

    public static function addTableJoin(
        QueryBuilder $queryBuilder,
        string $parentAlias,
        string $relationField,
        string $childAlias,
        string $joinType = Join::LEFT_JOIN,
    ): QueryBuilder {
        if (self::hasAlias($queryBuilder, $childAlias)) {
            return $queryBuilder;
        }

        $relation = "$parentAlias.$relationField";

        if ($joinType === Join::INNER_JOIN) {
            return $queryBuilder->innerJoin($relation, $childAlias);
        }

        return $queryBuilder->leftJoin($relation, $childAlias);
    }

    private static function hasAlias(QueryBuilder $queryBuilder, string $alias): bool
    {
        return in_array($alias, $queryBuilder->getAllAliases(), true);
    }

    /**
     * @return Collection<array-key, mixed>
     */
    public static function getCollectionFromQueryBuilder(QueryBuilder $queryBuilder): Collection
    {
        return new ArrayCollection($queryBuilder->getQuery()->getResult());
    }

    public static function addPeriodWhere(
        QueryBuilder $queryBuilder,
        string $startDate,
        string $endDate,
        string $alias,
        string $fieldName,
    ): QueryBuilder {
        $startDateParameter = 'startDate';
        $endDateParameter = 'endDate';

        return $queryBuilder
            ->andWhere("$alias.$fieldName BETWEEN :$startDateParameter AND :$endDateParameter")
            ->setParameter($startDateParameter, $startDate)
            ->setParameter($endDateParameter, $endDate);
    }

    public static function addRandomElements(
        QueryBuilder $queryBuilder,
        string $alias,
        int $numberElements,
    ): QueryBuilder {
        $numberElements = $numberElements > 0 ? $numberElements : null;
        $queryBuilder->addSelect('RANDOM() as HIDDEN rand');

        return $queryBuilder
            ->setMaxResults($numberElements)
            ->orderBy('rand');
    }
}
