<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Navigation;

use App\Identity\Application\OwnerCheck;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;

/**
 * The address book in the sidebar, with the number of active contacts, and for
 * the owner the firms of the organisation.
 */
final readonly class PartyNavigation implements NavigationItemProvider
{
    public function __construct(private PartyNavigationCount $count, private OwnerCheck $owner)
    {
    }

    public function items(): iterable
    {
        yield new NavigationItem('contacts', 'nav.item.contacts', 'users', 'app_contacts', $this->count, weight: 10, routePrefixes: ['app_contact']);
        if ($this->owner->isOwner()) {
            yield new NavigationItem('settings', 'nav.item.firms', 'building-2', 'app_firm_index', weight: 10, routePrefixes: ['app_firm', 'app_series']);
        }
    }
}
