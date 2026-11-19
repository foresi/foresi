<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\View;

use Foresi\Party\Application\Query\ContactList;
use Foresi\Party\Application\View\ContactLists;
use Foresi\Party\Infrastructure\Api\ContactApiController;
use Foresi\Shared\Application\View\ListRecordCounter;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * How many contacts a view of the address book holds, compiled as the list compiles it.
 */
final readonly class ContactRecordCounter implements ListRecordCounter
{
    public function __construct(private ContactList $list)
    {
    }

    public function lists(): array
    {
        return [ContactLists::KEY];
    }

    public function scope(): string
    {
        return ContactApiController::READ_SCOPE;
    }

    public function count(string $listKey, ViewFilter $filter): int
    {
        if (ContactLists::KEY !== $listKey) {
            throw new \InvalidArgumentException(\sprintf('The list "%s" is not the address book.', $listKey));
        }

        return $this->list->inView('', $filter, [], 'name', 1)->total;
    }
}
