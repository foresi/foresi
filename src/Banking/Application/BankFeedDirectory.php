<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

/**
 * Finds the feed of a Mail inbound address by its id alone.
 */
interface BankFeedDirectory
{
    /**
     * @return FeedRoute|null null for an address of no feed, and for the feed of an archived account
     */
    public function route(string $addressId): ?FeedRoute;
}
