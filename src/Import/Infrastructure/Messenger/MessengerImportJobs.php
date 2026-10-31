<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Messenger;

use App\Import\Application\ImportJobs;
use Symfony\Component\Messenger\MessageBusInterface;

/** Sends import work through Messenger; the tenant middleware stamps the active tenant. */
final readonly class MessengerImportJobs implements ImportJobs
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function dispatch(object $message): void
    {
        $this->bus->dispatch($message);
    }
}
