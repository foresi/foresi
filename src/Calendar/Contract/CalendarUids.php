<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Contract;

/**
 * The uid of a calendar entry (RFC 5545 3.8.4.7): "<key>-<id>@<host of the
 * instance>", the same on every fetch, so a calendar app updates an entry
 * instead of adding it again, and unique across instances.
 */
interface CalendarUids
{
    /**
     * @param string $key the key of the source
     * @param string $id  the id of the thing in the source
     */
    public function of(string $key, string $id): string;
}
