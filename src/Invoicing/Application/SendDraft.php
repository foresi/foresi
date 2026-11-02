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
use App\Invoicing\Domain\SendStatus;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\EmailPurpose;

/**
 * What the send dialog of an invoice opens with. The recipients are the contact's
 * invoicing addresses for the invoice's firm, its own or the shared ones: those for
 * invoices go to To, those for a copy to Cc, both ticked. A contact without one
 * offers the address the document copied. The text is the firm's template in the
 * document's language, filled in as SendInvoice would fill it, so what the dialog
 * shows is what the customer gets.
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
    ) {
    }

    public function for(Invoice $invoice): SendDraftValues
    {
        $firm = $invoice->firm();
        $server = $this->servers->for($firm);
        $recipients = $this->recipients($invoice);
        $fromDocument = false;
        $copied = $invoice->customer()->email;
        if ([] === $recipients && null !== $copied && '' !== trim($copied)) {
            $recipients = [new SendRecipient(trim($copied), null, SendRecipient::TO, true)];
            $fromDocument = true;
        }

        $userId = $this->actor->userId();
        $user = null === $userId ? null : $this->users->findById($userId);
        $contact = trim($this->settings->for($firm)->contactEmail());
        $copyAddress = '' !== $contact ? $contact : $user?->email();

        $template = $this->templates->textFor($firm, MailTemplateKind::Invoice, $invoice->language());
        $values = $this->variables->valuesFor($this->documents->of($invoice->id()), $user?->displayName() ?? $firm->name());
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
            $at = $send->sentAt();
            if (SendStatus::Sent === $send->status() && $at instanceof \DateTimeImmutable && (!$last instanceof \DateTimeImmutable || $at > $last)) {
                $last = $at;
            }
        }

        return $last;
    }
}
