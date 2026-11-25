<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Navigation;

use Foresi\Inbox\Application\InboxRefused;
use Foresi\Inbox\Application\ItemReads;
use Foresi\Ui\Contract\Navigation\NavigationCountProvider;

/**
 * The new items of the person's inbox, read up to one more than the number the
 * navigation shows as "99+"; none shows no number.
 */
final readonly class InboxNavigationCount implements NavigationCountProvider
{
    public function __construct(private ItemReads $reads)
    {
    }

    public function count(): ?int
    {
        try {
            $count = $this->reads->newCount(InboxNavigation::CAP + 1);
        } catch (InboxRefused) {
            return null;
        }

        return 0 === $count ? null : $count;
    }
}
