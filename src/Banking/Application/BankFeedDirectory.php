<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

/**
 * Finds the feed of an inbound address by the hash of its token alone.
 */
interface BankFeedDirectory
{
    /**
     * @param string $tokenHash see InboundToken::hash()
     */
    public function route(string $tokenHash): ?FeedRoute;
}
