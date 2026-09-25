<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Server-side guard applied before Sylius moves administrator image uploads. */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 48)]
final class ImageUploadValidator
{
    public function __construct(private readonly \App\Service\Security\ImageUploadValidator $validator) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isAdminImageRequest($request)) {
            return;
        }

        foreach ($request->files->all() as $value) {
            foreach ($this->uploads($value) as $upload) {
                $error = $this->validator->validate($upload);
                if ($error !== null) {
                    $event->setResponse(new JsonResponse(['error' => 'invalid_image', 'message' => $error], 422, ['Cache-Control' => 'no-store']));
                    return;
                }
            }
        }
    }

    private function isAdminImageRequest(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/v2/admin/') && $request->files->all() !== [];
    }

    /** @return iterable<UploadedFile> */
    private function uploads(mixed $value): iterable
    {
        if ($value instanceof UploadedFile) {
            yield $value;
        } elseif (\is_array($value)) {
            foreach ($value as $nested) {
                yield from $this->uploads($nested);
            }
        }
    }
}
