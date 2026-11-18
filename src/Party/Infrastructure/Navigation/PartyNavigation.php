<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Navigation;

use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Ui\Navigation\CoreNavigationAreas;
use Foresi\Ui\Navigation\NavigationArea;
use Foresi\Ui\Navigation\NavigationItem;
use Foresi\Ui\Navigation\NavigationItemProvider;

/**
 * The address book in the sidebar for the staff, with the number of active
 * contacts, and for the owner the firms of the organisation.
 */
final readonly class PartyNavigation implements NavigationItemProvider
{
    public function __construct(private PartyNavigationCount $count, private StaffCheck $staff, private OwnerCheck $owner)
    {
    }

    public function items(): iterable
    {
        if (!$this->staff->isStaff()) {
            return;
        }
        yield new NavigationItem('contacts', 'nav.item.contacts', 'users', 'app_contacts', $this->count, weight: 10, routePrefixes: ['app_contact'], area: CoreNavigationAreas::CONTACTS);
        if ($this->owner->isOwner()) {
            yield new NavigationItem('settings', 'nav.item.firms', 'building-2', 'app_firm_index', weight: 10, routePrefixes: ['app_firm', 'app_series'], area: NavigationArea::SETTINGS);
        }
    }
}
