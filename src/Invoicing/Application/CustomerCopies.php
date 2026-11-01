<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\PartyDetails;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;

/**
 * Decides which copy of the customer a document holds. A new document or a new
 * customer copies the contact, unless a new document was given its own copy. On
 * an edit of the same customer a copy from the form replaces the stored one;
 * without it, the stored one stays, whatever became of the contact.
 */
final readonly class CustomerCopies
{
    public function __construct(private PartyDetails $details, private ContactSettingsForFirm $settings)
    {
    }

    /**
     * @return DocumentParty|null null when the contact has no billing address to copy
     */
    public function for(InvoiceInput $input, Party $party, LegalEntity $firm, ?Invoice $existing): ?DocumentParty
    {
        if ($existing instanceof Invoice) {
            return $party->id() === $existing->customerParty()->id() ? $input->customerOnDocument ?? $existing->customer() : $this->ofContact($party, $firm);
        }

        return $input->customerOnDocument ?? $this->ofContact($party, $firm);
    }

    private function ofContact(Party $party, LegalEntity $firm): ?DocumentParty
    {
        $billing = $this->details->billingAddress($party);
        if (!$billing instanceof PartyAddress) {
            return null;
        }
        $email = $this->settings->emailsFor($party, $firm)[0] ?? $this->details->generalEmail($party);

        return DocumentParty::ofCustomer($party, $billing->address(), $email?->address());
    }
}
