<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\UserRepository;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\MailTemplateKind;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\EmailPurpose;

/**
 * What the send dialog of an invoice opens with. The recipients are the contact's
 * invoicing addresses for the invoice's firm, its own or the shared ones: those for
 * invoices go to To, those for a copy to Cc, both ticked. A contact without one
 * for invoices offers the address the document copied, in To. The text is the firm's template in the
 * document's language, filled in as SendInvoice would fill it, so what the dialog
 * shows is what the customer gets. A reminder takes the firm's reminder template
 * instead, with the days overdue, the step and what is still owed filled in.
 */
final readonly class SendDraft
{
    public function __construct(
        private MailServers $servers,
        private ContactSettingsForFirm $contacts,
        private MailTemplates $templates,
        private MailVariables $variables,
        private IssuedDocumentFactory $documents,
        private InvoiceSends $sends,
        private FirmInvoicingSettings $settings,
        private UserRepository $users,
        private AuditActor $actor,
        private InvoiceBalances $balances,
    ) {
    }

    public function for(Invoice $invoice): SendDraftValues
    {
        return $this->draft($invoice, MailTemplateKind::Invoice, fn (IssuedDocument $document, string $sender): array => $this->variables->valuesFor($document, $sender));
    }

    /**
     * @param int                $step  which reminder of the invoice, from 1
     * @param \DateTimeImmutable $today the firm's day the reminder leaves, for the days overdue
     */
    public function forReminder(Invoice $invoice, int $step, \DateTimeImmutable $today): SendDraftValues
    {
        $due = $this->balances->outstanding([$invoice->id()])[$invoice->id()];

        return $this->draft($invoice, MailTemplateKind::Reminder, fn (IssuedDocument $document, string $sender): array => $this->variables->valuesForReminder($document, $sender, $step, $due, $today));
    }

    /**
     * @param callable(IssuedDocument, string): array<string, string> $values
     */
    private function draft(Invoice $invoice, MailTemplateKind $kind, callable $values): SendDraftValues
    {
        $firm = $invoice->firm();
        $server = $this->servers->for($firm);
        $recipients = $this->recipients($invoice);
        $fromDocument = false;
        $copied = trim($invoice->customer()->email ?? '');
        $hasTo = array_any($recipients, static fn (SendRecipient $recipient): bool => SendRecipient::TO === $recipient->field);
        if (!$hasTo && '' !== $copied) {
            // Without an address for invoices the mail goes to the one the document holds; a copy address it holds moves to To.
            $others = array_filter($recipients, static fn (SendRecipient $recipient): bool => mb_strtolower($recipient->address) !== mb_strtolower($copied));
            $recipients = [new SendRecipient($copied, null, SendRecipient::TO, true), ...$others];
            $fromDocument = true;
        }

        $userId = $this->actor->userId();
        $user = null === $userId ? null : $this->users->findById($userId);
        $contact = trim($this->settings->for($firm)->contactEmail());
        $copyAddress = '' !== $contact ? $contact : $user?->email();

        $template = $this->templates->textFor($firm, $kind, $invoice->language());
        // Read before the document, whose reading may start the entity manager afresh.
        $sender = $user?->displayName() ?? $firm->name();
        $values = $values($this->documents->of($invoice->id()), $sender);
        $text = new MailText();

        return new SendDraftValues(
            $server instanceof FirmMailServer,
            $server?->fromName(),
            $server?->fromAddress(),
            $recipients,
            $fromDocument,
            $copyAddress,
            $text->render($template->subject, $values, true),
            $text->render($template->body, $values, false),
            $invoice->language(),
            $this->lastSentAt($invoice),
        );
    }

    /**
     * Each address once, in the order the contact lists them.
     *
     * @return list<SendRecipient>
     */
    private function recipients(Invoice $invoice): array
    {
        $recipients = [];
        foreach ($this->contacts->emailsFor($invoice->customerParty(), $invoice->firm()) as $email) {
            $key = mb_strtolower($email->address());
            if (isset($recipients[$key])) {
                continue;
            }
            $purpose = $email->purpose();
            $recipients[$key] = new SendRecipient($email->address(), $purpose, EmailPurpose::Copy === $purpose ? SendRecipient::CC : SendRecipient::TO, EmailPurpose::General !== $purpose);
        }

        return array_values($recipients);
    }

    private function lastSentAt(Invoice $invoice): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($this->sends->ofInvoice($invoice) as $send) {
            // Only a send that went out has a moment it was sent, and it keeps it.
            $at = $send->sentAt();
            if ($at instanceof \DateTimeImmutable && (!$last instanceof \DateTimeImmutable || $at > $last)) {
                $last = $at;
            }
        }

        return $last;
    }
}
