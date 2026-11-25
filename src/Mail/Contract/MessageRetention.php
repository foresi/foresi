<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * Lets the consumer of a message decide how long its raw file and row are kept.
 * A message is kept until a consumer releases it, and removed by the daily
 * purge once the release date has passed. Nothing else removes a message.
 */
interface MessageRetention
{
    /**
     * Schedules the removal of a discarded message, its raw file and row, for
     * this moment on. A later call replaces the date. An unknown message, such
     * as one purged already, is ignored: there is nothing left to keep.
     */
    public function release(string $messageId, \DateTimeImmutable $after): void;

    /**
     * Takes back a release, as when a discarded item is restored; the message is
     * kept again. An unknown message is ignored.
     */
    public function keep(string $messageId): void;
}
