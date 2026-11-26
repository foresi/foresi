<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

/**
 * The invoicing defaults of each party of the address book, which Invoicing
 * keeps beside the party: what a new invoice to it starts with, whether it is
 * reminded and the hourly rate its time is billed at. A party Invoicing keeps
 * nothing for has the standard defaults.
 */
interface PartyInvoicingDefaults
{
    public function of(string $partyId): InvoicingDefaults;

    /**
     * @param list<string> $partyIds
     *
     * @return array<string, InvoicingDefaults> by party id, one for each id asked for
     */
    public function ofParties(array $partyIds): array;

    /**
     * Keeps new defaults for the party; they are stored with the next flush.
     */
    public function change(string $partyId, InvoicingDefaults $defaults): void;
}
