<?php

declare(strict_types=1);

namespace App\Service\Availability;

use App\Entity\Taxonomy\Taxon;
use App\Entity\Planning;
use Doctrine\ORM\EntityManagerInterface;

final class PlanningProvider
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return list<array{code: string, days: array<string, list<string>>, openDays: list<int>, times: list<string>, serviceCodes: list<string>, weeklyDays?: array<string, list<array{start: string, end: string}>>, timezone?: string, staffMemberId?: ?int}> */
    public function active(): array
    {
        $taxons = $this->entityManager->getRepository(Taxon::class)->createQueryBuilder('taxon')
            ->andWhere('taxon.code LIKE :prefix')
            ->andWhere('taxon.enabled = :enabled')
            ->setParameter('prefix', 'planning\_%')
            ->setParameter('enabled', true)
            ->orderBy('taxon.code', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        $knownCodes = [];
        foreach ($this->entityManager->getRepository(Planning::class)->findBy([], ['code' => 'ASC']) as $planning) {
            $knownCodes[$planning->getCode()] = true;
            if (!$planning->isActive()) {
                continue;
            }
            $legacy = $planning->getLegacyConfig() ?? [];
            $result[] = [
                'code' => $planning->getCode(),
                'days' => $this->days($legacy['days'] ?? null),
                'openDays' => $this->integers($legacy['openDays'] ?? null),
                'times' => $this->times($legacy['times'] ?? null),
                'weeklyDays' => $legacy === [] ? $planning->getDays() : [],
                'timezone' => $planning->getTimezone(),
                'staffMemberId' => $planning->getStaffMember()?->getId(),
                'serviceCodes' => $planning->getServiceCodes(),
            ];
        }
        foreach ($taxons as $taxon) {
            // A migrated planning is authoritative, including its inactive state.
            if (isset($knownCodes[$taxon->getCode()])) {
                continue;
            }
            $data = json_decode($taxon->getTranslation('en_US')?->getDescription() ?: '{}', true);
            if (!\is_array($data)) {
                continue;
            }
            $result[] = [
                'code' => (string) $taxon->getCode(),
                'days' => $this->days($data['days'] ?? null),
                'openDays' => $this->integers($data['openDays'] ?? null),
                'times' => $this->times($data['times'] ?? null),
                'serviceCodes' => $this->strings($data['jumpCodes'] ?? null),
            ];
        }

        return $result;
    }

    /** @return array<string, list<string>> */
    private function days(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $days = [];
        foreach ($value as $date => $times) {
            if (\is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $days[$date] = $this->times($times);
            }
        }

        return $days;
    }

    /** @return list<string> */
    private function times(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn (mixed $time): string => trim((string) $time), $value), static fn (string $time): bool => (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time))));
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_unique(array_filter(array_map('strval', $value)))) : [];
    }

    /** @return list<int> */
    private function integers(mixed $value): array
    {
        return \is_array($value) ? array_values(array_unique(array_map('intval', $value))) : [];
    }
}
