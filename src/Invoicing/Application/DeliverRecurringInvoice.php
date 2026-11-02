<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;

/**
 * Sends an invoice a recurring template issued, as the send dialog would open:
 * the contact's addresses for invoices of the firm, the firm's text in the
 * document's language, and a copy to the firm's contact address, else to the
 * template's owner. The send is the system's, made on behalf of the owner.
 *
 * A template set to review, or an occurrence more than LATE_DAYS late, is only
 * issued: it waits under "To send" for someone to look at it. A send the rules
 * refuse (no mail server, no recipient, the budget spent) leaves it waiting there
 * too, and the refusal is handed back for the template to record and tell.
 */
final readonly class DeliverRecurringInvoice
{
    public function __construct(
        private SendDraft $drafts,
        private SendInvoice $sending,
    ) {
    }

    /**
     * @param bool $late whether the occurrence is more than IssueRecurringInvoices::LATE_DAYS late
     */
    public function deliver(Invoice $invoice, RecurringInvoice $template, bool $late): RecurringOutcome
    {
        if ($late || RecurringDelivery::Review === $template->delivery()) {
            return RecurringOutcome::review();
        }
        // Read before the draft, whose reading of the document may start the session over.
        $owner = $template->owner();
        $invoiceId = $invoice->id();
        $draft = $this->drafts->for($invoice);
        $to = [];
        $cc = [];
        foreach ($draft->recipients as $recipient) {
            if (!$recipient->checked) {
                continue;
            }
            if (SendRecipient::TO === $recipient->field) {
                $to[] = $recipient->address;
            } else {
                $cc[] = $recipient->address;
            }
        }

        try {
            // No address for invoices refuses with NO_RECIPIENT, which is recorded, never skipped in silence.
            $this->sending->send(new SendRequest($invoiceId, $to, $cc, true, $draft->subject, $draft->body, onBehalfOf: $owner));
        } catch (SendRefused $refused) {
            return RecurringOutcome::refused('send.'.$refused->reason);
        }

        return RecurringOutcome::sent();
    }
}
