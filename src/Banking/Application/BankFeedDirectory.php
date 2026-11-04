<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Finds the feed of an inbound address across all tenants, by the hash of its
 * token alone. The only lookup that runs before a tenant is known.
 */
interface BankFeedDirectory
{
    /**
     * @param string $tokenHash see InboundToken::hash()
     */
    public function route(string $tokenHash): ?FeedRoute;
}
