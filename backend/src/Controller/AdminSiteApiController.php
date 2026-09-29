<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SitePage;
use App\Service\Site\SiteManagementService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/site')]
final class AdminSiteApiController
{
    public function __construct(private readonly SiteManagementService $management, private readonly ?\App\Service\Site\SiteLegacyImportService $legacy = null) {}

    #[Route('/import', methods: ['POST'])]
    public function import(): JsonResponse
    {
        return $this->write(fn (): array => $this->legacy?->import() ?? throw new \InvalidArgumentException('Reprise indisponible.'));
    }
    #[Route('/publish', methods: ['POST'])]
    public function publish(Request $request): JsonResponse
    {
        return $this->write(function () use ($request): array {
            $data = $this->payload($request);
            (new \App\Service\Site\SiteDocumentValidator())->keys($data, ['pages', 'menus']);
            $revisions = []; $pages = []; $menus = [];
            foreach (['pages', 'menus'] as $kind) {
                if (!is_array($data[$kind]) || !array_is_list($data[$kind]) || count($data[$kind]) > 100) throw new \InvalidArgumentException('Sélection invalide.');
                foreach ($data[$kind] as $item) {
                    if (!is_array($item) || count($item) !== 2 || !isset($item['id'], $item['revision']) || !is_string($item['id']) || !is_int($item['revision']) || $item['revision'] < 1) throw new \InvalidArgumentException('Rechargez la sélection avant de publier.');
                    if ($kind === 'pages') $pages[] = $item['id']; else $menus[] = $item['id'];
                    $revisions[$item['id']] = $item['revision'];
                }
            }
            if (!$revisions) throw new \InvalidArgumentException('Choisissez au moins une page ou un menu.');
            $this->management->publishBatch($pages, $menus, $revisions);
            return ['ok' => true];
        });
    }
    #[Route('/pages/{id}/preview', methods: ['GET'])]
    public function preview(string $id, ?Request $request = null): JsonResponse
    {
        return $this->write(function () use ($id, $request): array {
            $page = $this->management->render($this->find($id), true) ?? throw new NotFoundHttpException('Page introuvable.');
            $menus = $request?->query->getString('menus', '') ?? '';
            $page['navigation'] = (object) $this->management->previewNavigation($menus === '' ? [] : explode(',', $menus));
            return $page;
        });
    }
    #[Route('/pages', methods: ['GET'])]
    public function index(): JsonResponse { return $this->response(['member' => array_map($this->normalize(...), $this->management->pages())]); }

    #[Route('/pages', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->write(fn () => $this->normalize($this->management->create($this->payload($request))), 201);
    }
    #[Route('/pages/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse { return $this->response($this->normalize($this->find($id))); }

    #[Route('/pages/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->write(function () use ($id, $request): array {
            $page = $this->find($id);
            $data = $this->payload($request);
            $revision = $data['revision'] ?? null;
            if (!is_int($revision) || $revision < 1) throw new \InvalidArgumentException('Rechargez la page avant de l’enregistrer.');
            unset($data['revision']);
            if ($revision !== $page->getRevision()) throw OptimisticLockException::lockFailed($page);
            $this->management->update($page, $data);
            return $this->normalize($page);
        });
    }
    #[Route('/pages/{id}/duplicate', methods: ['POST'])]
    public function duplicate(string $id, Request $request): JsonResponse
    {
        return $this->write(fn () => $this->normalize($this->management->duplicate($this->find($id), $this->payload($request))), 201);
    }
    #[Route('/pages/{id}/restore', methods: ['POST'])]
    public function restore(string $id, Request $request): JsonResponse
    {
        return $this->write(function () use ($id, $request): array { $page = $this->find($id); if (($this->payload($request)['revision'] ?? null) !== $page->getRevision()) throw OptimisticLockException::lockFailed($page); $this->management->restore($page); return $this->normalize($page); });
    }
    #[Route('/pages/{id}', methods: ['DELETE'])]
    public function archive(string $id): JsonResponse
    {
        return $this->write(function () use ($id): array { $this->management->archive($this->find($id)); return ['ok' => true]; });
    }
    #[Route('/menus/{location}', methods: ['GET'])]
    public function menu(string $location): JsonResponse
    {
        return $this->write(function () use ($location): array {
            $menu = $this->management->menu($location);
            return ['location' => $location, 'revision' => $menu?->getRevision(), 'items' => $menu ? $this->management->menuItems($menu) : [], 'primaryLink' => $menu?->getPrimaryLink(), 'published' => $menu?->getPublished()];
        });
    }
    #[Route('/menus/{location}', methods: ['PUT'])]
    public function saveMenu(string $location, Request $request): JsonResponse
    {
        return $this->write(function () use ($location, $request): array {
            $menu = $this->management->saveMenu($location, $this->payload($request));
            return ['location' => $location, 'revision' => $menu?->getRevision(), 'items' => $this->management->menuItems($menu), 'primaryLink' => $menu->getPrimaryLink(), 'published' => $menu->getPublished()];
        });
    }
    #[Route('/menus/{location}/restore', methods: ['POST'])]
    public function restoreMenu(string $location, Request $request): JsonResponse
    {
        return $this->write(function () use ($location, $request): array {
            $revision = $this->payload($request)['revision'] ?? null;
            if (!is_int($revision)) throw new \InvalidArgumentException('Rechargez le menu avant de le restaurer.');
            $this->management->restoreMenu($location, $revision);
            return ['ok' => true];
        });
    }
    #[Route('/menus/{location}', methods: ['DELETE'])]
    public function deleteMenu(string $location): JsonResponse
    {
        return $this->write(function () use ($location): array { $this->management->deleteMenu($location); return ['ok' => true]; });
    }
    private function find(string $id): SitePage { return $this->management->page($id) ?? throw new NotFoundHttpException('Page introuvable.'); }
    private function normalize(SitePage $page): array
    {
        return ['id' => $page->getId(), 'role' => $page->getRole(), 'archived' => $page->isArchived(), 'revision' => $page->getRevision(), 'draft' => $page->getDraft(), 'published' => $page->getPublished(), 'restorable' => $page->getPublished() !== null || $page->getLegacyPublished() !== null];
    }
    private function payload(Request $request): array
    {
        if (strlen($request->getContent()) > 250000) throw new \InvalidArgumentException('Le contenu est trop volumineux.');
        $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) throw new \InvalidArgumentException('Données invalides.');
        return $data;
    }
    private function write(callable $operation, int $status = 200): JsonResponse
    {
        try { return $this->response($operation(), $status); }
        catch (\InvalidArgumentException|\JsonException $error) { return $this->response(['error' => $error instanceof \JsonException ? 'Données invalides.' : $error->getMessage()], 422); }
        catch (ForeignKeyConstraintViolationException) { return $this->response(['error' => 'Une image vient d’être supprimée. Rechargez les images et choisissez-en une autre.'], 409); }
        catch (UniqueConstraintViolationException|OptimisticLockException|\Doctrine\DBAL\Exception\RetryableException) { return $this->response(['error' => 'Cette page ou ce menu a changé, ou cette adresse est déjà utilisée. Rechargez la liste.'], 409); }
    }
    private function response(array $data, int $status = 200): JsonResponse { return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer']); }
}
