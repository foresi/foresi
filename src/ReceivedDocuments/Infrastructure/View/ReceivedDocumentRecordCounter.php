<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ReceivedDocuments\Infrastructure\View;

use Foresi\ReceivedDocuments\Application\Query\ReceivedDocumentList;
use Foresi\ReceivedDocuments\Application\View\ReceivedDocumentLists;
use Foresi\ReceivedDocuments\Infrastructure\Api\ReceivedDocumentApiController;
use Foresi\Shared\Application\View\ListRecordCounter;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * How many received documents a view holds, compiled as the list compiles it.
 */
final readonly class ReceivedDocumentRecordCounter implements ListRecordCounter
{
    public function __construct(private ReceivedDocumentList $list)
    {
    }

    public function lists(): array
    {
        return [ReceivedDocumentLists::KEY];
    }

    public function scope(): string
    {
        return ReceivedDocumentApiController::READ_SCOPE;
    }

    public function count(string $listKey, ViewFilter $filter): int
    {
        if (ReceivedDocumentLists::KEY !== $listKey) {
            throw new \InvalidArgumentException(\sprintf('The list "%s" is not the received documents.', $listKey));
        }

        return $this->list->count($filter);
    }
}
