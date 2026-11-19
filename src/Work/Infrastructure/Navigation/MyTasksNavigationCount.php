<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Navigation;

use Foresi\Ui\Contract\Navigation\NavigationCountProvider;
use Foresi\Work\Application\WorkQueries;

/**
 * My unfinished tasks that are overdue or due today, in one count query; none shows no number.
 */
final readonly class MyTasksNavigationCount implements NavigationCountProvider
{
    public function __construct(private WorkQueries $queries)
    {
    }

    public function count(): ?int
    {
        $count = $this->queries->myDueCount();

        return 0 === $count ? null : $count;
    }
}
