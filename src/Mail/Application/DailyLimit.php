<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Instance\Application\InstanceClock;

/**
 * How many mails an address takes in one instance day (MAIL_INBOUND_DAILY_LIMIT),
 * so a loop at the sender or a flood cannot fill the disk. The day is the
 * instance's, from midnight in its time zone, and only stored mails count: a
 * mail that was dropped or was a duplicate takes no place.
 */
final readonly class DailyLimit
{
    public function __construct(
        private MailMessages $messages,
        private InstanceClock $clock,
        private int $limit,
    ) {
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * Whether the address stored as many mails today as it may.
     */
    public function reached(string $addressId): bool
    {
        // received_at is stored in UTC, so the start of the instance day is too.
        $start = $this->clock->today()->setTimezone(new \DateTimeZone('UTC'));

        return $this->messages->countSince($addressId, $start) >= $this->limit;
    }
}
