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
use Foresi\Invoicing\Infrastructure\Form\AmountText;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the lists of issued documents: invoices, advances, tax documents on payment and
 * corrective documents, one list key per kind (LISTS). Each cell renders one InvoiceRow.
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
final readonly class IssuedDocumentColumns implements ListColumns
{
    /** One list per kind of document, as each has its own route and its own default views; the columns are the same. */
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

    public function columns(string $list): iterable
    {
        if (!\in_array($list, self::LISTS, true)) {
            return [];
        }
        $trash = static fn (array $context): bool => true === ($context['trash'] ?? false);
        $notTrash = static fn (array $context): bool => true !== ($context['trash'] ?? false);
        $label = static fn (string $key): string => 'invoice.list.column.'.$key;

        return [
            new Column('number', $label('number'), static fn (InvoiceRow $row): string => $row->number, defaultPosition: 10, sortKey: 'number', locked: true),
            new Column('firm', $label('firm'), static fn (InvoiceRow $row): string => $row->firmName, defaultPosition: 20, phone: false, truncate: true, available: static fn (array $context): bool => true === ($context['severalFirms'] ?? false)),
            new Column('customer', $label('customer'), static fn (InvoiceRow $row): string => $row->customerName, defaultPosition: 30, sortKey: 'customer', phone: false, truncate: true),
            new Column('deletedBy', $label('deletedBy'), fn (InvoiceRow $row, array $context): string => $this->who($row->deletedBy, $context), defaultPosition: 40, phone: false, truncate: true, available: $trash),
            new Column('deletedAt', $label('deletedAt'), fn (InvoiceRow $row): string => $row->deletedAt?->format($this->translator->trans('format.datetime')) ?? '', defaultPosition: 50, phone: false, available: $trash),
            new Column('reason', $label('reason'), static fn (InvoiceRow $row): ?string => $row->deletedReason, defaultPosition: 60, phone: false, truncate: true, available: $trash),
            new Column('issueDate', $label('issueDate'), fn (InvoiceRow $row): string => $row->issueDate->format($this->translator->trans('format.date')), defaultPosition: 40, sortKey: 'issueDate', phone: false, wide: true, available: $notTrash),
            new Column('dueDate', $label('dueDate'), $this->dueDate(...), defaultPosition: 50, sortKey: 'dueDate', phone: false, available: $notTrash),
            new Column('payable', $label('payable'), fn (InvoiceRow $row): string => $this->amounts->shown($row->payable), defaultPosition: 70, align: 'right', sortKey: 'payable'),
            new Column('status', $label('status'), $this->status(...), defaultPosition: 80, phone: false),
            new Column('sent', $label('sent'), $this->sent(...), defaultPosition: 90, phone: false, wide: true, available: $notTrash),
            new Column('export', $label('export'), $this->export(...), defaultPosition: 100, phone: false, wide: true, available: $notTrash),
        ];
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
        $status = match (true) {
            DocumentKind::CorrectionDocument === $row->kind => InvoiceStatus::ofCorrection($row->trashed),
            $row->settledByAdvances && !$row->trashed => InvoiceStatus::SettledByAdvances,
            default => InvoiceStatus::of($row->delivered, $row->overdue, $row->trashed, $row->payment),
        };
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
