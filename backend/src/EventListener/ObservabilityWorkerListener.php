<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Observability\MetricsRegistry;
use App\Tenant\TenantContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

final readonly class ObservabilityWorkerListener
{
    public function __construct(private MetricsRegistry $metrics, private TenantContext $tenantContext) {}

    #[AsEventListener]
    public function started(WorkerStartedEvent $event): void
    {
        $this->metrics->increment('worker_started', $this->tenant());
    }

    #[AsEventListener]
    public function stopped(WorkerStoppedEvent $event): void
    {
        $this->metrics->increment('worker_stopped', $this->tenant());
    }

    #[AsEventListener]
    public function failed(WorkerMessageFailedEvent $event): void
    {
        $this->metrics->increment('worker_message_failed', $this->tenant());
    }

    private function tenant(): string
    {
        return $this->tenantContext->getExplicitSlug() ?? 'app';
    }
}
