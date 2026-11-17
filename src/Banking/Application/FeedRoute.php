<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Where mail for one inbound address belongs: plain ids.
 */
final readonly class FeedRoute
{
    public function __construct(
        public string $feedId,
        public string $accountId,
        public string $firmId,
    ) {
    }
}
