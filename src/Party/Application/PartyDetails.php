<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\ContactLink;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;

/**
 * What belongs to one party of the active tenant.
 */
interface PartyDetails
{
    /**
     * @return list<PartyAddress> the billing address first
     */
    public function addresses(Party $party): array;

    public function billingAddress(Party $party): ?PartyAddress;

    /**
     * @return list<PartyEmail>
     */
    public function emails(Party $party): array;

    public function generalEmail(Party $party): ?PartyEmail;

    /**
     * @return list<PartyBankAccount> the primary account first
     */
    public function bankAccounts(Party $party): array;

    /**
     * Links where the party is the company, or the person.
     *
     * @return list<ContactLink>
     */
    public function contactLinks(Party $party): array;

    /**
     * The audit entries of the party and of the addresses, e-mails, bank
     * accounts and contact links that belong to it, newest first.
     *
     * @return list<PartyHistoryEntry>
     */
    public function history(Party $party): array;
}
