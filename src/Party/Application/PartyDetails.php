<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Party\Domain\PartyEmail;

/**
 * What belongs to one party.
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
     * The addresses invoices go to and are copied to; the general e-mail is not one of them.
     *
     * @return list<PartyEmail>
     */
    public function invoicingEmails(Party $party): array;

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
