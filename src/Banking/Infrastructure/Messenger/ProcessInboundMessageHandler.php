<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Messenger;

use App\Banking\Application\ProcessInboundMessage;
use App\Banking\Application\ProcessMessage;
use App\Banking\Application\UnknownInboundMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processes a stored message in the tenant the tenant middleware made active. A
 * delivery for a message processed already does nothing; one for a message not
 * of the tenant is dropped. Anything unexpected is thrown, so the queue retries.
 */
#[AsMessageHandler]
final readonly class ProcessInboundMessageHandler
{
    public function __construct(private ProcessMessage $process, private LoggerInterface $logger)
    {
    }

    public function __invoke(ProcessInboundMessage $message): void
    {
        try {
            $this->process->process($message->messageId);
        } catch (UnknownInboundMessage) {
            $this->logger->warning('An inbound bank message to process is not in the tenant.', ['message' => $message->messageId]);
        }
    }
}
