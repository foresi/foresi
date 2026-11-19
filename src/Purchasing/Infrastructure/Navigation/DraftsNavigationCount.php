<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Navigation;

use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\Query\ReceivedDocumentList;
use Foresi\Ui\Contract\Navigation\NavigationCountProvider;

/**
 * The drafts that wait to be completed, in one count query; none shows no number.
 */
final readonly class DraftsNavigationCount implements NavigationCountProvider
{
    public function __construct(private ReceivedDocumentList $list)
    {
    }

    public function count(): ?int
    {
        try {
            $count = $this->list->drafts();
        } catch (PurchasingNotAllowed) {
            return null;
        }

        return 0 === $count ? null : $count;
    }
}
