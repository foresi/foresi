<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Navigation;

use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;

/**
 * The address book in the sidebar, with the number of active contacts.
 */
final readonly class PartyNavigation implements NavigationItemProvider
{
    public function __construct(private PartyNavigationCount $count)
    {
    }

    public function items(): iterable
    {
        yield new NavigationItem('contacts', 'nav.item.contacts', 'users', 'app_contacts', $this->count, weight: 10);
    }
}
