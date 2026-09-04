<?php

declare(strict_types=1);

namespace App\Gdpr;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RetentionPolicy
{
    public function __construct(
        #[Autowire('%todatempo.gdpr.booking_retention_months%')] private int $bookingRetentionMonths,
        #[Autowire('%todatempo.gdpr.waitlist_retention_days%')] private int $waitlistRetentionDays,
        #[Autowire('%todatempo.gdpr.invoice_retention_years%')] private int $invoiceRetentionYears,
    ) {
        if ($bookingRetentionMonths < 1 || $waitlistRetentionDays < 1 || $invoiceRetentionYears < 1) {
            throw new \InvalidArgumentException('Les durees de conservation RGPD doivent etre strictement positives.');
        }
    }

    public function bookingCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('-%d months', $this->bookingRetentionMonths));
    }

    public function waitlistCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->waitlistRetentionDays));
    }

    public function invoiceCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('-%d years', $this->invoiceRetentionYears));
    }

    /** @return array<string, int> */
    public function describe(): array
    {
        return ['bookingMonths' => $this->bookingRetentionMonths, 'waitlistDays' => $this->waitlistRetentionDays, 'invoiceYears' => $this->invoiceRetentionYears];
    }
}
