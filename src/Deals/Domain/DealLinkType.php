<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Domain;

/**
 * How one deal is linked to another. A plain string in its column, so a new
 * type needs no data migration.
 */
enum DealLinkType: string
{
    /** The deal came after the other, such as a monthly service after a one-off audit. */
    case FollowsFrom = 'follows_from';
}
