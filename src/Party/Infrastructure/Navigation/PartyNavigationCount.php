<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Navigation;

use Foresi\Party\Application\PartyQuery;
use Foresi\Ui\Navigation\NavigationCountProvider;

final readonly class PartyNavigationCount implements NavigationCountProvider
{
    public function __construct(private PartyQuery $parties)
    {
    }

    public function count(): int
    {
        return $this->parties->counts()->all;
    }
}
