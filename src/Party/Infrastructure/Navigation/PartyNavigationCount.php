<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Navigation;

use App\Party\Application\PartyQuery;
use App\Ui\Navigation\NavigationCountProvider;

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
