<?php

declare(strict_types=1);

namespace App\Reminder;

use App\Configuration\SiteConfigDocument;
use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ReminderConfiguration
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(string:TODATEMPO_REMINDER_EMAIL_HOURS)%')] private readonly string $defaultEmailHours,
        #[Autowire('%env(string:TODATEMPO_REMINDER_SMS_HOURS)%')] private readonly string $defaultSmsHours,
        #[Autowire('%env(bool:TODATEMPO_SMS_ENABLED)%')] private readonly bool $smsEnabled,
    ) {
    }

    /** @return array{email: list<int>, sms: list<int>} */
    public function channels(): array
    {
        $config = [];
        try {
            $taxon = $this->entityManager->getRepository(Taxon::class)->findOneBy(['code' => 'todatempo_config'])
                ?? $this->entityManager->getRepository(Taxon::class)->findOneBy(['code' => 'skybook_config']);
            $decoded = SiteConfigDocument::published(json_decode($taxon?->getTranslation('en_US')?->getDescription() ?: '{}', true));
            $config = \is_array($decoded['reminders'] ?? null) ? $decoded['reminders'] : [];
        } catch (\Throwable) {
        }

        $email = \is_array($config['email'] ?? null) ? $config['email'] : [];
        $sms = \is_array($config['sms'] ?? null) ? $config['sms'] : [];

        return [
            'email' => ($email['enabled'] ?? true) ? $this->hours($email['hours'] ?? $this->defaultEmailHours) : [],
            'sms' => $this->smsEnabled && ($sms['enabled'] ?? false) ? $this->hours($sms['hours'] ?? $this->defaultSmsHours) : [],
        ];
    }

    /** @return list<int> */
    private function hours(mixed $value): array
    {
        $values = \is_array($value) ? $value : explode(',', (string) $value);
        $hours = array_values(array_unique(array_filter(array_map(static fn (mixed $hour): int => (int) $hour, $values), static fn (int $hour): bool => $hour > 0)));
        sort($hours);

        return $hours;
    }
}
