<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Navigation;

use Foresi\Shared\Application\ActorRole;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * The address book in the sidebar for the staff, with the number of active
 * contacts, and for the owner the settings of the instance's firm.
 */
final readonly class PartyNavigation implements NavigationItemProvider
{
    public function __construct(private PartyNavigationCount $count, private ActorRole $role)
    {
    }

    public function items(): iterable
    {
        if (!$this->role->isStaff()) {
            return;
        }
        yield new NavigationItem(CoreNavigationAreas::CONTACTS, 'nav.item.contacts', 'users', 'app_contacts', $this->count, weight: 10, routePrefixes: ['app_contact']);
        if ($this->role->isOwner()) {
            yield new NavigationItem(NavigationArea::SETTINGS, 'nav.item.firm', 'building-2', 'app_firm', weight: 10, routePrefixes: ['app_firm', 'app_series'], section: CoreNavigationAreas::SECTION_FIRM);
        }
    }
}
