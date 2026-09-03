<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Planning> */
final class PlanningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Planning::class);
    }

    /** @return list<Planning> */
    public function findForAdministration(): array
    {
        return $this->createQueryBuilder('planning')
            ->leftJoin('planning.staffMember', 'staff')->addSelect('staff')
            ->orderBy('planning.active', 'DESC')->addOrderBy('planning.name', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Planning> */
    public function findActiveForService(string $serviceCode): array
    {
        return array_values(array_filter(
            $this->findBy(['active' => true], ['name' => 'ASC']),
            static fn (Planning $planning): bool => $planning->getServiceCodes() === [] || \in_array($serviceCode, $planning->getServiceCodes(), true),
        ));
    }
}
