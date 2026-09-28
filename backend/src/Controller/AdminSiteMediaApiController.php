<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SiteMedia;
use App\Service\Site\{SiteDocumentValidator, SiteMediaService};
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/site/media')]
final class AdminSiteMediaApiController
{
    public function __construct(private readonly SiteMediaService $media, private readonly SiteDocumentValidator $validator) {}
    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse { return $this->response(['member' => array_map($this->media->describe(...), $this->media->all())]); }
    #[Route('', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        return $this->write(function () use ($request): array {
            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile) throw new \InvalidArgumentException('Choisissez une image JPEG, PNG ou WebP de 5 Mio maximum.');
            return $this->media->describe($this->media->upload($file, $request->request->get('alt', '')));
        }, 201);
    }
    #[Route('/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->write(function () use ($id, $request): array {
            $media = $this->find($id);
            if (strlen($request->getContent()) > 4000) throw new \InvalidArgumentException('Le texte est trop long.');
            $data = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new \InvalidArgumentException('Données invalides.');
            $this->validator->keys($data, ['alt']);
            $this->media->update($media, $data['alt']);
            return $this->media->describe($media);
        });
    }
    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse { return $this->write(function () use ($id): array { $this->media->delete($this->find($id)); return ['ok' => true]; }); }
    private function find(string $id): SiteMedia { return $this->media->find($id) ?? throw new NotFoundHttpException('Image introuvable.'); }
    private function write(callable $operation, int $status = 200): JsonResponse
    {
        try { return $this->response($operation(), $status); }
        catch (\InvalidArgumentException|\JsonException $e) { return $this->response(['error' => $e instanceof \JsonException ? 'Données invalides.' : $e->getMessage()], 422); }
        catch (\DomainException|ForeignKeyConstraintViolationException) { return $this->response(['error' => 'Cette image est utilisée dans un brouillon ou une version publiée. Retirez-la des pages concernées et publiez ces changements avant de la supprimer.'], 409); }
        catch (NotFoundHttpException $e) { throw $e; }
        catch (\RuntimeException) { return $this->response(['error' => 'Impossible de traiter cette image pour le moment. Réessayez.'], 503); }
    }
    private function response(array $data, int $status = 200): JsonResponse { return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']); }
}
