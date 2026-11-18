<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Messenger;

use Foresi\Banking\Application\ProcessInboundMessage;
use Foresi\Banking\Application\ProcessMessage;
use Foresi\Banking\Application\UnknownInboundMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processes a stored message. A delivery for a message processed already does
 * nothing; one for a message not found is dropped. Anything unexpected is
 * thrown, so the queue retries.
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
            $this->logger->warning('An inbound bank message to process is not found.', ['message' => $message->messageId]);
        }
    }
}
