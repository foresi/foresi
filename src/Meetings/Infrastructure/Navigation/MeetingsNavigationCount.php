<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Navigation;

use Foresi\Meetings\Application\MeetingQueries;
use Foresi\Ui\Contract\Navigation\NavigationCountProvider;

/**
 * The meetings held today that the person sees, in one count query; none shows no number.
 */
final readonly class MeetingsNavigationCount implements NavigationCountProvider
{
    public function __construct(private MeetingQueries $queries)
    {
    }

    public function count(): ?int
    {
        $count = $this->queries->todayCount();

        return 0 === $count ? null : $count;
    }
}
