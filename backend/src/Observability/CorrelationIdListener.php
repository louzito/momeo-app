<?php

declare(strict_types=1);

namespace App\Observability;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CorrelationIdListener implements EventSubscriberInterface
{
    public const ATTRIBUTE = '_todatempo_correlation_id';
    public const HEADER = 'X-Correlation-ID';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 1024], KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $candidate = trim((string) $event->getRequest()->headers->get(self::HEADER));
        $id = preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $candidate) ? $candidate : bin2hex(random_bytes(16));
        $event->getRequest()->attributes->set(self::ATTRIBUTE, $id);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $id = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (\is_string($id)) {
            $event->getResponse()->headers->set(self::HEADER, $id);
        }
    }
}
