<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Navigation;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Inbox\Infrastructure\Controller\InboxController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * "Inbox" in the Overview, beside the notifications: where the mail the person
 * forwarded waits, with how many items are new. For the staff only, as the
 * inbox is the person's own and a guest has none.
 */
final readonly class InboxNavigation implements NavigationItemProvider
{
    /** The number shown above this reads "99+", as the notifications' does. */
    public const int CAP = 99;

    public function __construct(private StaffCheck $staff, private InboxNavigationCount $count)
    {
    }

    public function items(): iterable
    {
        if (!$this->staff->isStaff()) {
            return;
        }
        yield new NavigationItem(CoreNavigationAreas::OVERVIEW, 'nav.item.inbox', 'mail', InboxController::ROUTE, $this->count, weight: 15, routePrefixes: ['app_inbox'], countCap: self::CAP, marksArea: true);
    }
}
