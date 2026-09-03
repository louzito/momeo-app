<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Tenant\TenantWorkerGuard;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Interdit tout demarrage implicite d'un worker sur la base CLI par defaut. */
#[AsEventListener(event: ConsoleEvents::COMMAND, priority: 1024)]
final readonly class TenantMessengerWorkerListener
{
    public function __construct(private TenantWorkerGuard $guard)
    {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        if ($event->getCommand()?->getName() !== 'messenger:consume') {
            return;
        }

        $this->guard->validate();
    }
}
