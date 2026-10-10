<?php

declare(strict_types=1);
namespace App\Controller;

use App\Service\Site\SiteAppearanceService;
use App\Service\Site\SiteLogoUploadService;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/site/appearance')]
final class AdminSiteAppearanceController
{
    public function __construct(private readonly SiteAppearanceService $appearance) {}
    #[Route('/logo', methods: ['POST'])]
    public function uploadLogo(Request $request, SiteLogoUploadService $logos): JsonResponse
    {
        try {
            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile) throw new \InvalidArgumentException('Choisissez une image JPEG, PNG ou WebP de 5 Mio maximum.');
            return new JsonResponse($logos->upload($file), 201, ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException $error) {
            return new JsonResponse(['error' => $error->getMessage()], 422, ['Cache-Control' => 'private, no-store']);
        } catch (\RuntimeException) {
            return new JsonResponse(['error' => 'Impossible de téléverser ce logo pour le moment. Réessayez.'], 503, ['Cache-Control' => 'private, no-store']);
        }
    }
    #[Route('', methods: ['GET', 'PUT'])]
    public function handle(Request $request): JsonResponse
    {
        try {
            if ($request->isMethod('GET')) $result = $this->appearance->state();
            else {
                if (strlen($request->getContent()) > 10000) throw new \InvalidArgumentException('Contenu trop volumineux.');
                $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($data)) throw new \InvalidArgumentException('Apparence invalide.');
                $result = $this->appearance->save($data);
            }
            return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException|\JsonException|\DomainException $error) {
            return new JsonResponse(['error' => $error instanceof \JsonException ? 'Apparence invalide.' : $error->getMessage()], $error instanceof \DomainException ? 409 : 422, ['Cache-Control' => 'private, no-store']);
        }
    }
}
