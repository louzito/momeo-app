<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminClientApiController;
use App\Entity\Booking;
use App\Entity\ClientProfile;
use App\Tests\Customer\CustomerReadFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AdminClientApiContractTest extends KernelTestCase
{
    use CustomerReadFixture;

    private function directory(string $query = ''): array
    {
        return json_decode(self::getContainer()->get(AdminClientApiController::class)->index(new Request(['q' => $query]))->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testHistoryAggregationMissingProfileAndFilters(): void
    {
        $before = $this->directory()['stats'];
        $past = $this->bookingFixture(strtoupper($this->email), '-3 days', Booking::STATUS_COMPLETED);
        $future = $this->bookingFixture(' '.$this->email.' ', '+3 days');
        $cancelled = $this->bookingFixture($this->email, '+4 days', Booking::STATUS_CANCELLED);
        $past->setOrderNumber('test-order-'.$this->email);
        $future->setOrderNumber($past->getOrderNumber());
        $future->setTotalAmount(2400);
        $this->em->flush();
        $result = $this->directory('  '.strtoupper($this->email).' ');
        self::assertCount(1, $result['member']);
        $client = $result['member'][0];
        self::assertSame(substr(hash('sha256', $this->email), 0, 16), $client['id']);
        self::assertSame(3, $client['bookingCount']);
        self::assertSame(1, $client['completedCount']);
        self::assertSame(1, $client['cancelledCount']);
        self::assertSame(2400, $client['totalAmount']);
        self::assertSame('Note visible', $client['visibleNotes']);
        self::assertNull($client['internalNotes']);
        self::assertSame([], $client['consents']);
        self::assertArrayNotHasKey('notes', $client);
        self::assertSame([$cancelled->getId(), $future->getId(), $past->getId()], array_column($client['bookings'], 'id'));
        self::assertSame($past->getSlotStart()->format(DATE_ATOM), $client['lastBookingAt']);
        self::assertSame($future->getSlotStart()->format(DATE_ATOM), $client['nextBookingAt']);
        self::assertCount(1, $client['purchases']);
        self::assertSame(2400, $client['purchases'][0]['amount']);
        self::assertSame(['total' => 1, 'newThisMonth' => $before['newThisMonth'] + 1, 'withUpcoming' => $before['withUpcoming'] + 1, 'recurring' => $before['recurring'] + 1], $result['stats']);
        $empty = $this->directory('no-match-'.$this->email);
        self::assertSame([], $empty['member']);
        self::assertSame(0, $empty['stats']['total']);
        self::assertSame($result['stats']['recurring'], $empty['stats']['recurring']);
    }

    public function testProfileEmailDoesNotChangeMatchingKeyAndConsentReplayIsStable(): void
    {
        $booking = $this->bookingFixture($this->email, '+3 days');
        $controller = self::getContainer()->get(AdminClientApiController::class);
        $id = substr(hash('sha256', $this->email), 0, 16);
        $payload = ['email' => 'changed-'.$this->email, 'firstName' => ' Bob ', 'tags' => [' VIP ', 'VIP'], 'consents' => ['marketing' => true, 'unknown' => true]];
        $request = new Request(content: json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $controller->update($id, $request);
        self::assertSame(200, $response->getStatusCode());
        $profile = json_decode($response->getContent(), true);
        self::assertSame(['firstName', 'lastName', 'email', 'phone', 'visibleNotes', 'internalNotes', 'tags', 'allergies', 'contraindications', 'consents', 'consentHistory'], array_keys($profile));
        self::assertSame('Bob', $profile['firstName']);
        self::assertSame(['VIP'], $profile['tags']);
        self::assertSame(['marketing' => true], $profile['consents']);
        $controller->update($id, $request);
        $stored = $this->em->getRepository(ClientProfile::class)->findOneBy(['bookingEmail' => $this->email]);
        self::assertCount(1, $stored->getConsentHistory());
        self::assertSame($this->email, $booking->getCustomerEmail());
        $client = $this->directory('changed-'.$this->email)['member'][0];
        self::assertSame($id, $client['id']);
        self::assertSame('Bob Martin', $client['displayName']);
        self::assertSame(422, $controller->update($id, new Request(content: '{"email":"invalid"}'))->getStatusCode());
        $this->em->flush();
        self::assertSame('changed-'.$this->email, $stored->getEmail());
        self::assertSame(404, $controller->update('absent', new Request(content: '{}'))->getStatusCode());
    }

    public function testUpcomingClientsSortFirstThenNamesAndProfileTagsAreSearchable(): void
    {
        $first = $this->bookingFixture('a-'.$this->email, '+2 days');
        $second = $this->bookingFixture('b-'.$this->email, '+3 days');
        $last = $this->bookingFixture('c-'.$this->email, '-2 days');
        $first->setCustomerLastName('Zulu');
        $second->setCustomerLastName('Beta');
        $last->setCustomerLastName('Alpha');
        $profile = new ClientProfile('a-'.$this->email);
        $profile->setFirstName('Alice');
        $profile->setLastName('Zulu');
        $profile->setTags(['tag-'.$this->email]);
        $this->em->persist($profile);
        $this->em->flush();
        self::assertSame(['b-'.$this->email, 'a-'.$this->email, 'c-'.$this->email], array_column($this->directory($this->email)['member'], 'email'));
        self::assertCount(1, $this->directory('TAG-'.$this->email)['member']);
    }

    public function testProfileWithoutBookingDoesNotCreateDirectoryEntry(): void
    {
        $this->em->persist(new ClientProfile($this->email));
        $this->em->flush();
        self::assertSame([], $this->directory($this->email)['member']);
        $attribute = (new \ReflectionClass(AdminClientApiController::class))->getAttributes(IsGranted::class)[0]->newInstance();
        self::assertSame('ROLE_API_ACCESS', $attribute->attribute);
    }
}
