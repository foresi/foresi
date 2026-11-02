<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Where mail for one inbound address belongs: plain ids, found before any
 * tenant is active.
 */
final readonly class FeedRoute
{
    public function __construct(
        public string $tenantId,
        public string $feedId,
        public string $accountId,
        public string $firmId,
    ) {
    }
}
