<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Messenger;

use Foresi\Invoicing\Application\IssueRecurringInvoices;
use Foresi\Invoicing\Application\IssueRecurringTemplate;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one template as the daily run does.
 */
#[AsMessageHandler]
final readonly class IssueRecurringTemplateHandler
{
    public function __construct(private IssueRecurringInvoices $run)
    {
    }

    public function __invoke(IssueRecurringTemplate $message): void
    {
        $this->run->runOne($message->templateId);
    }
}
