<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;

/** Tenant-scoped booking rules (Doctrine is connected to the current tenant database). */
final class BookingRules
{
    /** @var array{minimumNoticeHours: int, maximumAdvanceDays: int, cancellationNoticeHours: int, bufferBeforeMinutes: int, bufferAfterMinutes: int, customerPolicy: string}|null */
    private ?array $cached = null;

    public const DEFAULTS = [
        'minimumNoticeHours' => 0,
        'maximumAdvanceDays' => 365,
        'cancellationNoticeHours' => 24,
        'bufferBeforeMinutes' => 0,
        'bufferAfterMinutes' => 0,
        'customerPolicy' => '',
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array{minimumNoticeHours: int, maximumAdvanceDays: int, cancellationNoticeHours: int, bufferBeforeMinutes: int, bufferAfterMinutes: int, customerPolicy: string} */
    public function get(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        foreach (['todatempo_config', 'skybook_config'] as $code) {
            $taxon = $this->entityManager->getRepository(Taxon::class)->findOneBy(['code' => $code]);
            $config = json_decode($taxon?->getTranslation('en_US')?->getDescription() ?: '{}', true);
            if (\is_array($config)) {
                $raw = \is_array($config['bookingRules'] ?? null) ? $config['bookingRules'] : [];
                // Keep the already deployed cancellation setting backwards compatible.
                $raw['cancellationNoticeHours'] ??= $config['bookingChanges']['cancelHours'] ?? self::DEFAULTS['cancellationNoticeHours'];

                return $this->cached = $this->normalize($raw);
            }
        }

        return $this->cached = self::DEFAULTS;
    }

    /** @param array<string, mixed> $raw */
    public function normalize(array $raw): array
    {
        return [
            'minimumNoticeHours' => $this->integer($raw, 'minimumNoticeHours', 0, 8760),
            'maximumAdvanceDays' => $this->integer($raw, 'maximumAdvanceDays', 1, 1095),
            'cancellationNoticeHours' => $this->integer($raw, 'cancellationNoticeHours', 0, 8760),
            'bufferBeforeMinutes' => $this->integer($raw, 'bufferBeforeMinutes', 0, 1440),
            'bufferAfterMinutes' => $this->integer($raw, 'bufferAfterMinutes', 0, 1440),
            'customerPolicy' => mb_substr(trim((string) ($raw['customerPolicy'] ?? '')), 0, 5000),
        ];
    }

    public function assertBookableAt(\DateTimeImmutable $start, ?\DateTimeImmutable $now = null): void
    {
        $rules = $this->get();
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($start < $now->modify(sprintf('+%d hours', $rules['minimumNoticeHours']))) {
            throw new \DomainException('Le délai minimum avant réservation n’est pas respecté.');
        }
        if ($start > $now->modify(sprintf('+%d days', $rules['maximumAdvanceDays']))) {
            throw new \DomainException('Ce créneau dépasse l’horizon maximum de réservation.');
        }
    }

    /** @param array<string, mixed> $raw */
    private function integer(array $raw, string $key, int $min, int $max): int
    {
        $value = $raw[$key] ?? self::DEFAULTS[$key];
        return max($min, min($max, (int) $value));
    }
}
