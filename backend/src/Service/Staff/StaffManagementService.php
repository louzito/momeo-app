<?php

declare(strict_types=1);

namespace App\Service\Staff;

use App\Entity\StaffMember;
use Doctrine\ORM\EntityManagerInterface;

/** Validates a detached draft before changing the managed member or its account. */
final class StaffManagementService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StaffAccountService $accounts,
    ) {}

    /** @param array<string, mixed> $payload */
    public function save(StaffMember $member, array $payload): void
    {
        $draft = clone $member;
        if (($error = $this->hydrate($draft, $payload)) !== null) {
            throw new InvalidStaffInput($error);
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $changed = false;
        try {
            // Account validation also completes before applying the draft.
            $syncAccount = $this->accounts->prepare($member, $payload);
            $changed = true;
            foreach (['FirstName', 'LastName', 'Email', 'Phone', 'JobTitle', 'Bio', 'Color',
                'ServiceCodes', 'WorkingHours', 'Position'] as $field) {
                $member->{'set'.$field}($draft->{'get'.$field}());
            }
            $member->setActive($draft->isActive());
            $member->setBookable($draft->isBookable());
            if ($member->getId() === null) $this->entityManager->persist($member);
            $syncAccount();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            // A rollback does not discard scheduled Doctrine writes. Reload before retrying.
            if ($changed && $this->entityManager->isOpen()) $this->entityManager->clear();
            throw $exception;
        }
    }

    public function archive(StaffMember $member): void
    {
        $member->setActive(false);
        $member->setBookable(false);
        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $payload */
    private function hydrate(StaffMember $member, array $payload): ?string
    {
        $firstName = trim((string) ($payload['firstName'] ?? ''));
        $lastName = trim((string) ($payload['lastName'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            return 'Le prénom et le nom sont obligatoires.';
        }

        $email = trim((string) ($payload['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'L’adresse email est invalide.';
        }

        $color = trim((string) ($payload['color'] ?? '#1f5c57'));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return 'La couleur doit être au format #RRGGBB.';
        }

        $member->setFirstName(mb_substr($firstName, 0, 100));
        $member->setLastName(mb_substr($lastName, 0, 100));
        $member->setEmail($email !== '' ? mb_substr($email, 0, 180) : null);
        $member->setPhone($this->nullableText($payload['phone'] ?? null, 40));
        $member->setJobTitle($this->nullableText($payload['jobTitle'] ?? null, 120));
        $member->setBio($this->nullableText($payload['bio'] ?? null));
        $member->setColor(strtolower($color));
        $member->setActive((bool) ($payload['active'] ?? true));
        $member->setBookable((bool) ($payload['bookable'] ?? true));
        $member->setPosition(max(0, (int) ($payload['position'] ?? 0)));

        $serviceCodes = \is_array($payload['serviceCodes'] ?? null) ? $payload['serviceCodes'] : [];
        $member->setServiceCodes(array_values(array_filter(array_map(
            static fn (mixed $code): string => mb_substr(trim((string) $code), 0, 255),
            $serviceCodes,
        ))));
        try {
            $member->setWorkingHours(WorkingHours::normalize($payload['workingHours'] ?? $member->getWorkingHours()));
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function nullableText(mixed $value, ?int $length = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $length === null ? $value : mb_substr($value, 0, $length);
    }
}
