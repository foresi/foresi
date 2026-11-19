<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\DocumentParty;
use Foresi\Invoicing\Domain\DocumentParties;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;

/**
 * Decides which copy of the customer a document holds. A new document or a new
 * customer copies the contact, unless a new document was given its own copy. On
 * an edit of the same customer a copy from the form replaces the stored one;
 * without it, the stored one stays, whatever became of the contact.
 */
final readonly class CustomerCopies
{
    public function __construct(private PartyDetails $details)
    {
    }

    /**
     * @return DocumentParty|null null when the contact has no billing address to copy
     */
    public function for(InvoiceInput $input, Party $party, ?Invoice $existing): ?DocumentParty
    {
        if ($existing instanceof Invoice) {
            return $party->id() === $existing->customerParty()->id() ? $input->customerOnDocument ?? $existing->customer() : $this->ofContact($party);
        }

        return $input->customerOnDocument ?? $this->ofContact($party);
    }

    private function ofContact(Party $party): ?DocumentParty
    {
        $billing = $this->details->billingAddress($party);
        if (!$billing instanceof PartyAddress) {
            return null;
        }
        $email = $this->details->invoicingEmails($party)[0] ?? $this->details->generalEmail($party);

        return DocumentParties::ofCustomer($party, $billing->address(), $email?->address());
    }
}
