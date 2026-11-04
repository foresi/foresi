<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Messenger;

use App\Invoicing\Application\IssueRecurringTemplate;
use App\Invoicing\Application\RecurringIssueQueue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Puts a template on the default queue. The template is saved by then: if the
 * queue cannot take it, the next day's run issues what is due, late dates for
 * review, and the request that saved it goes on.
 */
final readonly class MessengerRecurringIssueQueue implements RecurringIssueQueue
{
    public function __construct(private MessageBusInterface $bus, private LoggerInterface $logger)
    {
    }

    public function issue(string $templateId): void
    {
        try {
            $this->bus->dispatch(new IssueRecurringTemplate($templateId));
        } catch (\Throwable $failure) {
            $this->logger->warning('The recurring invoice {template} was saved but not handed to the worker: {reason}', ['template' => $templateId, 'reason' => $failure::class]);
        }
    }
}
