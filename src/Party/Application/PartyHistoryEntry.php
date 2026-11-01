<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * One change in the history of a party or of something that belongs to it.
 */
final readonly class PartyHistoryEntry
{
    /**
     * @param string       $subject party, address, email, bank_account, contact or firm_settings
     * @param string       $action  created, updated or deleted
     * @param list<string> $fields  names of the fields that changed
     */
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public string $subject,
        public string $action,
        public array $fields,
    ) {
    }
}
