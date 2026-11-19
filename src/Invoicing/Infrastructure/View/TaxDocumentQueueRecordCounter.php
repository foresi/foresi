<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\View;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\View\TaxDocumentQueueLists;
use Foresi\Invoicing\Application\View\TaxDocumentQueues;
use Foresi\Invoicing\Infrastructure\Api\InvoiceApiController;
use Foresi\Shared\Application\View\ListRecordCounter;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * How many rows a view of a list a tax document waits in holds, worked out as the
 * list works them out. They are documents, so the invoices' read scope counts them.
 */
final readonly class TaxDocumentQueueRecordCounter implements ListRecordCounter
{
    public function __construct(
        private TaxDocumentQueues $queues,
        private InstanceClock $clock,
    ) {
    }

    public function lists(): array
    {
        return [TaxDocumentQueueLists::MISSING, TaxDocumentQueueLists::UNCORRECTED];
    }

    public function scope(): string
    {
        return InvoiceApiController::READ_SCOPE;
    }

    public function count(string $listKey, ViewFilter $filter): int
    {
        $today = $this->clock->today();

        return \count(TaxDocumentQueues::matching($this->queues->rows($listKey, $today), $listKey, $filter, $today));
    }
}
