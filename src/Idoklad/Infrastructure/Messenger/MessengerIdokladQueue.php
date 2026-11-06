<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Messenger;

use App\Idoklad\Application\IdokladQueue;
use App\Idoklad\Application\Message\ProcessIdokladJob;
use Symfony\Component\Messenger\MessageBusInterface;

/** Sends jobs through Messenger; the tenant middleware stamps the active tenant. */
final readonly class MessengerIdokladQueue implements IdokladQueue
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function dispatch(ProcessIdokladJob $message): void
    {
        $this->bus->dispatch($message);
    }
}
