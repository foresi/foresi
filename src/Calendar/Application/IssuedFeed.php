<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Application;

use Foresi\Calendar\Domain\CalendarFeed;

/**
 * A feed just created, with the token of its address, which exists only in this answer.
 */
final readonly class IssuedFeed
{
    public function __construct(
        public CalendarFeed $feed,
        #[\SensitiveParameter]
        public string $token,
    ) {
    }
}
