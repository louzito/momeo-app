<?php

declare(strict_types=1);

namespace App\Service\Customer;

use App\Entity\Booking;
use App\Entity\ClientProfile;
use App\Entity\Customer\Customer;
use Doctrine\ORM\EntityManagerInterface;

final class ClientProfileService
{
    private const CONSENT_TYPES = ['marketing', 'dataProcessing', 'medicalData'];

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /** The source email remains the stable matching key when the displayed email changes. */
    public function update(Booking|Customer $client, array $data, string $actor): array
    {
        $email = mb_strtolower(trim($client instanceof Booking ? $client->getCustomerEmail() : (string) $client->getEmail()));
        $sourceFirstName = $client instanceof Booking ? $client->getCustomerFirstName() : $client->getFirstName();
        $sourceLastName = $client instanceof Booking ? $client->getCustomerLastName() : $client->getLastName();
        $sourcePhone = $client instanceof Booking ? $client->getCustomerPhone() : $client->getPhoneNumber();
        $profile = $this->entityManager->getRepository(ClientProfile::class)->findOneBy(['bookingEmail' => $email]) ?? new ClientProfile($email);
        $firstName = trim((string) ($data['firstName'] ?? $sourceFirstName));
        $lastName = trim((string) ($data['lastName'] ?? $sourceLastName));
        $newEmail = mb_strtolower(trim((string) ($data['email'] ?? $email)));
        if ($firstName === '' || $lastName === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidClientProfile('Nom, prénom et email valide sont obligatoires.');
        }

        $profile->setFirstName($firstName);
        $profile->setLastName($lastName);
        $profile->setEmail($newEmail);
        $profile->setPhone(isset($data['phone']) ? (string) $data['phone'] : $sourcePhone);
        $profile->setVisibleNotes(isset($data['visibleNotes']) ? (string) $data['visibleNotes'] : $profile->getVisibleNotes());
        $profile->setInternalNotes(isset($data['internalNotes']) ? (string) $data['internalNotes'] : $profile->getInternalNotes());
        $profile->setAllergies(isset($data['allergies']) ? (string) $data['allergies'] : $profile->getAllergies());
        $profile->setContraindications(isset($data['contraindications']) ? (string) $data['contraindications'] : $profile->getContraindications());
        if (isset($data['tags']) && is_array($data['tags'])) {
            $profile->setTags(array_slice(array_map('strval', $data['tags']), 0, 30));
        }
        if (isset($data['consents']) && is_array($data['consents'])) {
            foreach (self::CONSENT_TYPES as $type) {
                if (array_key_exists($type, $data['consents']) && (!array_key_exists($type, $profile->getConsents()) || $profile->getConsents()[$type] !== (bool) $data['consents'][$type])) {
                    $profile->recordConsent($type, (bool) $data['consents'][$type], $actor);
                }
            }
        }

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $this->normalize($profile);
    }

    public function normalize(ClientProfile $profile): array
    {
        return [
            'firstName' => $profile->getFirstName(), 'lastName' => $profile->getLastName(),
            'email' => $profile->getEmail(), 'phone' => $profile->getPhone(),
            'visibleNotes' => $profile->getVisibleNotes(), 'internalNotes' => $profile->getInternalNotes(),
            'tags' => $profile->getTags(), 'allergies' => $profile->getAllergies(),
            'contraindications' => $profile->getContraindications(), 'consents' => $profile->getConsents(),
            'consentHistory' => $profile->getConsentHistory(),
        ];
    }
}
