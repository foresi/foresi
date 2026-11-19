<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\View;

use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\View\RecurringLists;
use Foresi\Invoicing\Infrastructure\Api\InvoiceApiController;
use Foresi\Shared\Application\View\ListRecordCounter;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * How many recurring invoices a view holds, compiled as the list compiles it. They are
 * read with the invoices, so the invoices' read scope counts them.
 */
final readonly class RecurringRecordCounter implements ListRecordCounter
{
    public function __construct(private RecurringInvoices $templates)
    {
    }

    public function lists(): array
    {
        return [RecurringLists::KEY];
    }

    public function scope(): string
    {
        return InvoiceApiController::READ_SCOPE;
    }

    public function count(string $listKey, ViewFilter $filter): int
    {
        if (RecurringLists::KEY !== $listKey) {
            throw new \InvalidArgumentException(\sprintf('The list "%s" is not the recurring invoices.', $listKey));
        }

        return $this->templates->page(new RecurringFilter(null, null, false, 1, $filter))->total;
    }
}
