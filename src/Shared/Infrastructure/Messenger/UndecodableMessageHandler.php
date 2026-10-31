<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/** Fails without a retry, which sends the message to the failed transport. */
#[AsMessageHandler]
final readonly class UndecodableMessageHandler
{
    public function __invoke(UndecodableMessage $message): never
    {
        throw new UnrecoverableMessageHandlingException($message->reason);
    }
}
