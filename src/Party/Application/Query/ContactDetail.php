<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

/**
 * One contact with what belongs to it. The private note and the history stay
 * on the screen.
 */
final readonly class ContactDetail
{
    /**
     * @param list<ContactAddress>     $addresses    the billing address first
     * @param list<ContactEmail>       $emails       the shared addresses; a firm's own invoicing addresses are not among them
     * @param list<ContactBankAccount> $bankAccounts the primary account first
     * @param list<ContactPersonLink>  $contactLinks where the contact is the company, or the person
     */
    public function __construct(
        public ContactRow $contact,
        public string $phone,
        public string $mobile,
        public string $website,
        public bool $unreliablePayer,
        public array $addresses,
        public array $emails,
        public array $bankAccounts,
        public array $contactLinks,
    ) {
    }
}
