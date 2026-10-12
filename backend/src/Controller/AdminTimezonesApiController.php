<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_API_ACCESS')]
final class AdminTimezonesApiController
{
    #[Route('/api/v2/admin/timezones', name: 'todatempo_api_admin_timezones', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $timezones = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
        // Certaines installations incluent des entrées techniques non utilisables.
        $timezones = array_values(array_filter($timezones, static function (string $timezone): bool {
            try {
                new \DateTimeZone($timezone);

                return true;
            } catch (\Exception) {
                return false;
            }
        }));
        sort($timezones, SORT_STRING);

        return new JsonResponse($timezones);
    }
}
