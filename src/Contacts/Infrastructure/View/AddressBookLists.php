<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Contacts\Infrastructure\View;

use Foresi\Party\Application\View\ContactLists;
use Foresi\Shared\Application\View\ListDefinitionSource;

/**
 * The contact list and its views, registered with the address book: its fields
 * are the attributes of the address book's data source (ContactSource), so an
 * instance without the contacts module has no such list.
 */
final readonly class AddressBookLists implements ListDefinitionSource
{
    public function __construct(private ContactLists $lists)
    {
    }

    public function lists(): iterable
    {
        yield $this->lists->list();
    }
}
