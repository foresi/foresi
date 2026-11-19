<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Column;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\InvoiceRow;
use Foresi\Invoicing\Application\InvoiceStatus;
use Foresi\Invoicing\Application\PaymentState;
use Foresi\Invoicing\Application\SendMark;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentParty;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\ExportState;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Infrastructure\Form\AmountText;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The data source of the lists of issued documents: invoices, advances, tax documents on payment
 * and corrective documents, one list key per kind (LISTS), with the same attributes (spec DS3).
 * Each value is read from one InvoiceRow and the document it was read from, so a column added to
 * the list costs no query; each sort is a DQL expression over the document "i".
 *
 * The customer's details are the document's own copy, as it was issued ("Customer as invoiced"),
 * not the contact as it is now.
 *
 * The context holds "trash" (the trash tab swaps the dates and the e-mail and export marks for who
 * deleted the document, when and why), "severalFirms" (the firm column), "names" (user names by
 * id, for the trash) and "reminders" ({count, lastAt} by the id of an overdue row).
 *
 * The names and the reason give way to the numbers, dates, states and actions, cut short with
 * their full text in a title, so the table fits its frame with every column shown. A narrower
 * screen leaves out the issue date and the e-mail mark, the columns of least value to a list
 * read for what to collect; the invoice still shows both.
 */
final readonly class IssuedDocumentSource implements DataSource
{
    /** One list per kind of document, as each has its own route and its own default views; the attributes are the same. */
    public const array LISTS = [
        'invoice' => 'invoicing.invoices',
        'advance' => 'invoicing.advances',
        'payment_tax_document' => 'invoicing.tax_documents',
        'correction_document' => 'invoicing.corrections',
    ];

    /** The icon of each send mark: its label says the same, so it is never colour alone. */
    private const array MARK_ICONS = ['queued' => 'clock-3', 'sent' => 'send', 'read' => 'circle-check', 'failed' => 'triangle-alert'];
    private const array MARK_TONES = ['queued' => 'gray', 'sent' => 'gray', 'read' => 'green', 'failed' => 'red'];

    public function __construct(
        private TranslatorInterface $translator,
        private AmountText $amounts,
        private InstanceClock $instanceClock,
    ) {
    }

    public function attributes(string $list): iterable
    {
        if (!\in_array($list, self::LISTS, true)) {
            return [];
        }
        $trash = static fn (array $context): bool => true === ($context['trash'] ?? false);
        $notTrash = static fn (array $context): bool => true !== ($context['trash'] ?? false);
        $label = static fn (string $key): string => 'invoice.list.column.'.$key;
        $document = static fn (\Closure $read): \Closure => static fn (InvoiceRow $row): mixed => $row->document instanceof Invoice ? $read($row->document) : null;
        $customer = static fn (\Closure $read): \Closure => $document(static fn (Invoice $invoice): mixed => $read($invoice->customer()));
        $zoned = fn (\Closure $read): \Closure => $document(fn (Invoice $invoice): ?\DateTimeImmutable => ($at = $read($invoice)) instanceof \DateTimeImmutable ? $at->setTimezone($this->instanceClock->timezone()) : null);

        return [
            // Shown by default, as the list has always shown them.
            new Attribute('number', $label('number'), AttributeType::Text, static fn (InvoiceRow $row): string => $row->number, sort: 'i.numberKey', defaultVisible: true, defaultPosition: 10, locked: true),
            new Attribute('firm', $label('firm'), AttributeType::Reference, static fn (InvoiceRow $row): string => $row->firmName, sort: 'LOWER(i.issuer.name)', defaultVisible: true, defaultPosition: 20, phone: false, truncate: true, available: static fn (array $context): bool => true === ($context['severalFirms'] ?? false)),
            new Attribute('customer', $label('customer'), AttributeType::Reference, static fn (InvoiceRow $row): string => $row->customerName, sort: 'LOWER(i.customer.name)', defaultVisible: true, defaultPosition: 30, phone: false, truncate: true),
            new Attribute('deleted_by', $label('deleted_by'), AttributeType::Reference, fn (InvoiceRow $row, array $context): string => $this->who($row->deletedBy, $context), defaultVisible: true, defaultPosition: 40, aliases: ['deletedBy'], phone: false, truncate: true, available: $trash),
            new Attribute('deleted_at', $label('deleted_at'), AttributeType::DateTime, static fn (InvoiceRow $row): ?\DateTimeImmutable => $row->deletedAt, sort: 'i.deletedAt', defaultVisible: true, defaultPosition: 50, aliases: ['deletedAt'], phone: false, available: $trash),
            new Attribute('deleted_reason', $label('deleted_reason'), AttributeType::Text, static fn (InvoiceRow $row): ?string => $row->deletedReason, defaultVisible: true, defaultPosition: 60, aliases: ['reason'], phone: false, truncate: true, available: $trash),
            new Attribute('issue_date', $label('issue_date'), AttributeType::Date, static fn (InvoiceRow $row): \DateTimeImmutable => $row->issueDate, sort: 'i.issueDate', defaultVisible: true, defaultPosition: 40, aliases: ['issueDate'], phone: false, wide: true, available: $notTrash),
            new Attribute('due_date', $label('due_date'), AttributeType::Date, static fn (InvoiceRow $row): \DateTimeImmutable => $row->dueDate, sort: 'i.dueDate', defaultVisible: true, defaultPosition: 50, cell: $this->dueDate(...), aliases: ['dueDate'], phone: false, available: $notTrash),
            new Attribute('payable', $label('payable'), AttributeType::Money, static fn (InvoiceRow $row): Money => $row->payable, sort: 'i.payableMinor', defaultVisible: true, defaultPosition: 70),
            new Attribute('status', $label('status'), AttributeType::Enum, static fn (InvoiceRow $row): InvoiceStatus => self::statusOf($row), defaultVisible: true, defaultPosition: 80, options: $this->options(InvoiceStatus::cases(), 'invoice.list.status.'), cell: $this->status(...), phone: false),
            new Attribute('sent', $label('sent'), AttributeType::Enum, static fn (InvoiceRow $row): SendMark => $row->sendMark, defaultVisible: true, defaultPosition: 90, options: $this->options(SendMark::cases(), 'invoice.mark.'), cell: $this->sent(...), phone: false, wide: true, available: $notTrash),
            new Attribute('export', $label('export'), AttributeType::Enum, static fn (InvoiceRow $row): ExportState => $row->exportState(), defaultVisible: true, defaultPosition: 100, options: $this->options(ExportState::cases(), 'invoice.export_state.'), cell: $this->export(...), phone: false, wide: true, available: $notTrash),
            // What a person can add (DS3), in the order of the document.
            new Attribute('customer_registration_number', $label('customer_registration_number'), AttributeType::Text, $customer(static fn (DocumentParty $party): ?string => $party->registrationNumber), sort: 'i.customer.registrationNumber', defaultPosition: 110, phone: false),
            new Attribute('customer_vat_id', $label('customer_vat_id'), AttributeType::Text, $customer(static fn (DocumentParty $party): ?string => $party->vatId), sort: 'i.customer.vatId', defaultPosition: 120, phone: false),
            new Attribute('customer_city', $label('customer_city'), AttributeType::Text, $customer(static fn (DocumentParty $party): string => $party->address->city), sort: 'LOWER(i.customer.address.city)', defaultPosition: 130, phone: false, truncate: true),
            new Attribute('customer_country', $label('customer_country'), AttributeType::Country, $customer(static fn (DocumentParty $party): string => $party->address->countryCode), sort: 'i.customer.address.countryCode', defaultPosition: 140, phone: false),
            new Attribute('customer_email', $label('customer_email'), AttributeType::Email, $customer(static fn (DocumentParty $party): ?string => $party->email), sort: 'LOWER(i.customer.email)', defaultPosition: 150, phone: false, truncate: true),
            new Attribute('tax_point_date', $label('tax_point_date'), AttributeType::Date, $document(static fn (Invoice $invoice): ?\DateTimeImmutable => $invoice->taxPointDate()), sort: 'i.taxPointDate', defaultPosition: 160, phone: false),
            new Attribute('currency', $label('currency'), AttributeType::Text, $document(static fn (Invoice $invoice): string => $invoice->currency()->value), sort: 'i.currency', defaultPosition: 170, phone: false),
            new Attribute('net', $label('net'), AttributeType::Money, $document(static fn (Invoice $invoice): Money => $invoice->net()), sort: 'i.netMinor', defaultPosition: 180, phone: false),
            new Attribute('vat', $label('vat'), AttributeType::Money, $document(static fn (Invoice $invoice): Money => $invoice->vat()), sort: 'i.vatMinor', defaultPosition: 190, phone: false),
            new Attribute('paid', $label('paid'), AttributeType::Money, static fn (InvoiceRow $row): Money => $row->paid, defaultPosition: 200, phone: false),
            new Attribute('remaining', $label('remaining'), AttributeType::Money, static fn (InvoiceRow $row): Money => $row->remaining(), defaultPosition: 210, phone: false),
            new Attribute('payment_state', $label('payment_state'), AttributeType::Enum, static fn (InvoiceRow $row): PaymentState => $row->payment, defaultPosition: 220, options: $this->options(PaymentState::cases(), 'invoice.list.payment_state.'), phone: false),
            new Attribute('overdue', $label('overdue'), AttributeType::Boolean, static fn (InvoiceRow $row): bool => $row->overdue, defaultPosition: 230, phone: false),
            new Attribute('payment_method', $label('payment_method'), AttributeType::Enum, $document(static fn (Invoice $invoice): PaymentMethod => $invoice->paymentMethod()), sort: 'i.paymentMethod', defaultPosition: 240, options: $this->options(PaymentMethod::cases(), 'invoice.payment.'), phone: false),
            new Attribute('bank_account', $label('bank_account'), AttributeType::Text, $document(static fn (Invoice $invoice): ?string => $invoice->bankAccount()), sort: 'i.bankAccount', defaultPosition: 250, phone: false),
            new Attribute('variable_symbol', $label('variable_symbol'), AttributeType::Text, $document(static fn (Invoice $invoice): string => $invoice->variableSymbol()), sort: 'i.variableSymbol', defaultPosition: 260, phone: false),
            new Attribute('order_number', $label('order_number'), AttributeType::Text, $document(static fn (Invoice $invoice): string => $invoice->orderNumber()), sort: 'LOWER(i.orderNumber)', defaultPosition: 270, phone: false),
            new Attribute('language', $label('language'), AttributeType::Enum, $document(static fn (Invoice $invoice): DocumentLanguage => $invoice->language()), sort: 'i.language', defaultPosition: 280, options: $this->options(DocumentLanguage::cases(), 'invoice.language.'), phone: false),
            new Attribute('vat_regime', $label('vat_regime'), AttributeType::Enum, $document(static fn (Invoice $invoice): VatRegime => $invoice->vatRegime()), sort: 'i.vatRegime', defaultPosition: 290, options: $this->options(VatRegime::cases(), 'invoice.list.vat_regime.'), phone: false),
            new Attribute('description', $label('description'), AttributeType::Text, $document(static fn (Invoice $invoice): string => $invoice->description()), defaultPosition: 300, phone: false, truncate: true),
            new Attribute('delivered_at', $label('delivered_at'), AttributeType::DateTime, $zoned(static fn (Invoice $invoice): ?\DateTimeImmutable => $invoice->deliveredAt()), sort: 'i.deliveredAt', defaultPosition: 310, phone: false),
            new Attribute('created_at', $label('created_at'), AttributeType::DateTime, $zoned(static fn (Invoice $invoice): \DateTimeImmutable => $invoice->createdAt()), sort: 'i.createdAt', defaultPosition: 320, phone: false),
            new Attribute('recurring', $label('recurring'), AttributeType::Boolean, $document(static fn (Invoice $invoice): bool => null !== $invoice->recurringInvoiceId()), defaultPosition: 330, phone: false),
            new Attribute('import_source', $label('import_source'), AttributeType::Text, $document(static fn (Invoice $invoice): ?string => $invoice->importSource()), sort: 'i.importSource', defaultPosition: 340, phone: false),
        ];
    }

    /**
     * The DQL expression over the document "i" that each sortable attribute sorts by, by its key
     * and by its aliases, so an older sort such as "dueDate" still sorts.
     *
     * @return array<string, string>
     */
    public function sorts(): array
    {
        $sorts = [];
        foreach ($this->attributes(self::LISTS['invoice']) as $attribute) {
            if (null !== $attribute->sort) {
                foreach ([$attribute->key, ...$attribute->aliases] as $key) {
                    $sorts[$key] = $attribute->sort;
                }
            }
        }

        return $sorts;
    }

    /**
     * @return string|array{text: string, tone: string, hint: string}
     */
    private function dueDate(InvoiceRow $row): string|array
    {
        $due = $row->dueDate->format($this->translator->trans('format.date'));

        return $row->overdue ? ['text' => $due, 'tone' => 'red', 'hint' => $this->translator->trans('invoice.list.status.overdue')] : $due;
    }

    /**
     * A part payment says what is left; overdue or not, that is what someone acts on. A refund says how much.
     *
     * @return array{text: string, tone: string, hint?: string}
     */
    private function status(InvoiceRow $row): array
    {
        $status = self::statusOf($row);
        $left = match (true) {
            $row->trashed => null,
            PaymentState::PartlyPaid === $row->payment => $this->translator->trans('invoice.payment.left', ['amount' => $this->amounts->shown($row->remaining())]),
            PaymentState::ToRefund === $row->payment => $this->translator->trans('invoice.payment.to_refund', ['amount' => $this->amounts->shown(Money::ofMinor(-$row->remaining()->minor(), $row->payable->currency()))]),
            default => null,
        };

        return ['text' => $this->translator->trans('invoice.list.status.'.$status->value), 'tone' => $status->tone()] + (null === $left ? [] : ['hint' => $left]);
    }

    /**
     * The e-mail mark. A reminded row says how many reminders went, in the place of the mark: the
     * newest e-mail was one of them. A failure keeps its mark, as that is what to act on.
     *
     * @param array<string, mixed> $context
     *
     * @return array{text: string, icon: string, tone: string, hint: string}|null
     */
    private function sent(InvoiceRow $row, array $context): ?array
    {
        $mark = SendMark::None === $row->sendMark ? null : $this->translator->trans('invoice.mark.'.$row->sendMark->value);
        $reminders = \is_array($context['reminders'] ?? null) ? ($context['reminders'][$row->id] ?? null) : null;
        if (\is_array($reminders) && SendMark::Failed !== $row->sendMark && $reminders['lastAt'] instanceof \DateTimeImmutable && \is_int($reminders['count'])) {
            $last = $this->translator->trans('reminder.list.last', ['date' => $reminders['lastAt']->setTimezone($this->instanceClock->timezone())->format($this->translator->trans('format.date'))]);

            return [
                'text' => $this->translator->trans('reminder.list.count', ['count' => $reminders['count']]),
                'icon' => 'bell-ring',
                'tone' => SendMark::Read === $row->sendMark ? 'green' : 'gray',
                'hint' => implode(' · ', array_filter([$last, $mark], static fn (?string $part): bool => null !== $part && '' !== $part)),
            ];
        }

        return null === $mark ? null : ['text' => $mark, 'icon' => self::MARK_ICONS[$row->sendMark->value], 'tone' => self::MARK_TONES[$row->sendMark->value], 'hint' => $this->translator->trans('invoice.mark.hint.'.$row->sendMark->value)];
    }

    /**
     * What the accountant has of the row, with the moment that says so in the instance's time.
     * A document never exported shows nothing; a change after the export asks for a look.
     *
     * @return array{text: string, icon: string, tone: string, hint: string}|null
     */
    private function export(InvoiceRow $row): ?array
    {
        $moment = $row->changedAfterExportAt ?? $row->exportedAt;
        if (!$moment instanceof \DateTimeImmutable) {
            return null;
        }
        $changed = $row->changedAfterExportAt instanceof \DateTimeImmutable;
        $state = $row->exportState()->value;

        return [
            'text' => $this->translator->trans('invoice.export_state.'.$state),
            'icon' => $changed ? 'file-pen-line' : 'archive',
            'tone' => $changed ? 'amber' : 'gray',
            'hint' => $this->translator->trans('invoice.export_state.hint.'.$state, ['date' => $moment->setTimezone($this->instanceClock->timezone())->format($this->translator->trans('format.datetime'))]),
        ];
    }

    private static function statusOf(InvoiceRow $row): InvoiceStatus
    {
        return match (true) {
            DocumentKind::CorrectionDocument === $row->kind => InvoiceStatus::ofCorrection($row->trashed),
            $row->settledByAdvances && !$row->trashed => InvoiceStatus::SettledByAdvances,
            default => InvoiceStatus::of($row->delivered, $row->overdue, $row->trashed, $row->payment),
        };
    }

    /**
     * The label of each value of an enum, by value.
     *
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, string>
     */
    private function options(array $cases, string $prefix): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[(string) $case->value] = $prefix.$case->value;
        }

        return $options;
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function who(?string $userId, array $context): string
    {
        $names = \is_array($context['names'] ?? null) ? $context['names'] : [];
        $name = $names[$userId ?? ''] ?? null;

        return \is_string($name) ? $name : $this->translator->trans('invoice.history.system');
    }
}
