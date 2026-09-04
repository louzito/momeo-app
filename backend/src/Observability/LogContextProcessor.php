<?php

declare(strict_types=1);

namespace App\Observability;

use App\Tenant\TenantContext;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class LogContextProcessor
{
    public function __construct(private RequestStack $requestStack, private TenantContext $tenantContext) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();
        $correlationId = $request?->attributes->get(CorrelationIdListener::ATTRIBUTE);
        $extra = $record->extra;
        if (\is_string($correlationId)) {
            $extra['correlation_id'] = $correlationId;
        }
        $tenant = $this->tenantContext->getExplicitSlug();
        if ($tenant !== null) {
            $extra['tenant'] = $tenant;
        }

        return $record->with(extra: $extra);
    }
}
