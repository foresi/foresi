<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\MailTemplateKind;
use App\Shared\Domain\Money;

/**
 * The variables a mail template may hold, and their values for one document. All
 * values come from the frozen copies on the document, so editing a contact or the
 * firm after issue changes no mail about it. Amounts and dates read exactly as on
 * its PDF. {amount_total} is what the customer pays, after rounding. A reminder
 * has three more: the days past the due date, which reminder it is, and what is
 * still to be paid; an invoice template does not know them.
 */
final readonly class MailVariables
{
    /** The link to the document, filled in only when the mail leaves. */
    public const string LINK = 'link';

    private const array NAMES = [
        'number', 'order_number', 'variable_symbol', 'description', 'issue_date', 'due_date', 'currency', 'document_type',
        'customer_name', 'amount_vat', 'amount_total', 'amount_net', 'firm_name', 'iban', 'firm_email', 'firm_phone',
        'registration_number', 'vat_id', 'sender_name', self::LINK,
    ];

    private const array REMINDER_NAMES = ['days_overdue', 'reminder_step', 'amount_due'];

    public function __construct(private DocumentFormat $format, private DocumentTitles $titles)
    {
    }

    /**
     * @return list<string> the link last
     */
    public function names(MailTemplateKind $kind = MailTemplateKind::Invoice): array
    {
        return match ($kind) {
            MailTemplateKind::Invoice, MailTemplateKind::Advance, MailTemplateKind::PaymentTaxDocument => self::NAMES,
            MailTemplateKind::Reminder => [...array_values(array_diff(self::NAMES, [self::LINK])), ...self::REMINDER_NAMES, self::LINK],
        };
    }

    /**
     * The tokens of a text that are not variables of its kind, each once, in order.
     *
     * @return list<string>
     */
    public function unknownIn(string $text, MailTemplateKind $kind = MailTemplateKind::Invoice): array
    {
        preg_match_all(MailText::TOKEN, $text, $found);

        return array_values(array_unique(array_diff($found[1], $this->names($kind))));
    }

    /**
     * Every variable of a reminder except the link.
     *
     * @param int                $step  which reminder of the invoice, from 1
     * @param Money              $due   what is still to be paid
     * @param \DateTimeImmutable $today the firm's day the reminder leaves
     *
     * @return array<string, string>
     */
    public function valuesForReminder(IssuedDocument $document, string $senderName, int $step, Money $due, \DateTimeImmutable $today): array
    {
        $overdue = (int) new \DateTimeImmutable($document->dueDate->format('Y-m-d'))->diff(new \DateTimeImmutable($today->format('Y-m-d')))->format('%r%a');

        return $this->valuesFor($document, $senderName) + [
            'days_overdue' => (string) max(0, $overdue),
            'reminder_step' => (string) $step,
            'amount_due' => $this->format->money($due, $document->language),
        ];
    }

    /**
     * @return array<string, string> every variable except the link
     */
    public function valuesFor(IssuedDocument $document, string $senderName): array
    {
        $language = $document->language;
        $issuer = $document->issuer;

        return [
            'number' => $document->number,
            'order_number' => $document->orderNumber,
            'variable_symbol' => $document->variableSymbol,
            'description' => $document->description,
            'issue_date' => $this->format->date($document->issueDate, $language),
            'due_date' => $this->format->date($document->dueDate, $language),
            'currency' => $document->currency->value,
            'document_type' => $this->titles->of($document),
            'customer_name' => $document->customer->name,
            'amount_vat' => $this->format->money($document->vat, $language),
            'amount_total' => $this->format->money($document->payable, $language),
            'amount_net' => $this->format->money($document->net, $language),
            'firm_name' => $issuer->name,
            'iban' => $document->bankAccount ?? '',
            'firm_email' => $issuer->email ?? '',
            'firm_phone' => $issuer->phone,
            'registration_number' => $issuer->registrationNumber ?? '',
            'vat_id' => $issuer->vatId ?? '',
            'sender_name' => $senderName,
        ];
    }
}
