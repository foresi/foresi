<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain\View;

/**
 * Where a view shows: as a tab, or in the "More views" menu (spec D7).
 */
enum Placement: string
{
    case Primary = 'primary';
    case Overflow = 'overflow';
}
