<?php

declare(strict_types=1);

use App\Tests\Architecture\ArchitectureRules;

require_once __DIR__.'/ArchitectureRules.php';

return static function (): int {
    $cases = [
        ['Service/Booking/Creator.php', '<?php class Creator {}', false],
        ['Booking/Creator.php', '<?php class Creator {}', true],
        ['Service/Creator.php', '<?php class Creator {}', true],
        ['Command/NewBusiness.php', '<?php class NewBusiness {}', true],
        ['Controller/Demo.php', '<?php $db->beginTransaction();', true],
        ['Controller/Demo.php', '<?php $db ?-> wrapInTransaction(fn () => null);', true],
        ['Controller/Demo.php', '<?php $em->flush ();', true],
        ['Controller/Demo.php', '<?php $db->executeStatement("DELETE FROM booking");', true],
        ['Controller/Demo.php', '<?php use Stripe\StripeClient as Provider;', true],
        ['Controller/Demo.php', '<?php use Symfony\Component\Messenger\MessageBusInterface as Bus;', true],
        ['Controller/Demo.php', '<?php $booking->setStatus("paid");', true],
        ['Controller/Demo.php', '<?php $response->headers->setCookie($cookie);', false],
        ['Controller/Demo.php', '<?php $service->create($payload);', false],
        ['Controller/Demo.php', '<?php /* $db->commit(); */ $error = "->flush()";', false],
        ['Entity/Booking.php', '<?php use App\Service\Booking\BookingRules;', true],
        ['Entity/Booking.php', '<?php use Symfony\Component\HttpFoundation\Request;', true],
        ['Entity/User/AdminUser.php', '<?php use App\Service\Security\TeamRole;', false],
        ['Entity/Booking.php', '<?php use App\Repository\BookingRepository; #[ORM\Entity(repositoryClass: BookingRepository::class)] class Booking {}', false],
        ['Tenant/Adapter.php', '<?php class Adapter {}', false],
    ];
    foreach ($cases as $index => [$path, $source, $rejected]) {
        $errors = ArchitectureRules::violations($path, $source, ['Tenant/Adapter.php' => 'Test technical adapter']);
        if (($errors !== []) !== $rejected) {
            throw new RuntimeException('Architecture fixture '.($index + 1).' failed: '.implode('; ', $errors));
        }
    }

    return count($cases);
};
