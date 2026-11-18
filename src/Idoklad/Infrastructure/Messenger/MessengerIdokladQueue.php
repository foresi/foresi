<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Infrastructure\Messenger;

use Foresi\Idoklad\Application\IdokladQueue;
use Foresi\Idoklad\Application\Message\ProcessIdokladJob;
use Symfony\Component\Messenger\MessageBusInterface;

/** Sends jobs through Messenger. */
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
