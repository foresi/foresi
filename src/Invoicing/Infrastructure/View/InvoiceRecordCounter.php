<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\View;

use Foresi\Invoicing\Application\Query\InvoiceList;
use Foresi\Invoicing\Application\Query\InvoiceSliceFilter;
use Foresi\Invoicing\Application\View\InvoiceLists;
use Foresi\Invoicing\Domain\DocumentKind;
use Foresi\Invoicing\Infrastructure\Api\InvoiceApiController;
use Foresi\Shared\Application\View\ListRecordCounter;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * How many documents a view of one of the four lists holds, compiled as the
 * search of the documents compiles it.
 */
final readonly class InvoiceRecordCounter implements ListRecordCounter
{
    public function __construct(private InvoiceList $list)
    {
    }

    public function lists(): array
    {
        return array_values(InvoiceLists::KEYS);
    }

    public function scope(): string
    {
        return InvoiceApiController::READ_SCOPE;
    }

    public function count(string $listKey, ViewFilter $filter): int
    {
        $kind = array_search($listKey, InvoiceLists::KEYS, true);
        if (!\is_string($kind)) {
            throw new \InvalidArgumentException(\sprintf('The list "%s" is not one of the documents.', $listKey));
        }

        return $this->list->count(new InvoiceSliceFilter(DocumentKind::from($kind), view: $filter));
    }
}
