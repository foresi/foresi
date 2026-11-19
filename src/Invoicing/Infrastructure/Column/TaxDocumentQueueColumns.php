<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Column;

use Foresi\Invoicing\Application\MissingTaxDocument;
use Foresi\Invoicing\Application\UncorrectedTaxDocument;
use Foresi\Invoicing\Application\View\TaxDocumentQueueLists;
use Foresi\Invoicing\Infrastructure\Form\AmountText;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the two lists a tax document waits in, the ones they showed before
 * they had views: the document, the customer, the day the deadline counts from, the
 * amount, the deadline and the days left, red once late and amber in the last three.
 */
final readonly class TaxDocumentQueueColumns implements DataSource
{
    public function __construct(
        private TranslatorInterface $translator,
        private AmountText $amounts,
    ) {
    }

    public function attributes(string $list): iterable
    {
        return match ($list) {
            TaxDocumentQueueLists::MISSING => $this->missing(),
            TaxDocumentQueueLists::UNCORRECTED => $this->uncorrected(),
            default => [],
        };
    }

    /**
     * The days left until a deadline, or the days past it.
     */
    public function daysLeft(int $days): string
    {
        return $days < 0
            ? $this->translator->trans('advance.missing.days_late', ['days' => -$days])
            : $this->translator->trans('advance.missing.days_left', ['days' => $days]);
    }

    /**
     * @return list<Attribute>
     */
    private function missing(): array
    {
        $label = static fn (string $key): string => 'advance.missing.column.'.$key;

        return [
            new Attribute('number', $label('number'), AttributeType::Text, static fn (MissingTaxDocument $row): string => $row->advanceNumber, sort: 'number', defaultVisible: true, defaultPosition: 10, locked: true),
            new Attribute('customer', $label('customer'), AttributeType::Text, static fn (MissingTaxDocument $row): string => $row->customerName, sort: 'customer', defaultVisible: true, defaultPosition: 20, phone: false, truncate: true),
            new Attribute('received_on', $label('receivedOn'), AttributeType::Date, static fn (MissingTaxDocument $row): \DateTimeImmutable => $row->receivedOn, defaultVisible: true, defaultPosition: 30, cell: fn (MissingTaxDocument $row): string => $this->date($row->receivedOn), phone: false),
            new Attribute('received', $label('received'), AttributeType::Money, static fn (MissingTaxDocument $row): Money => $row->received, defaultVisible: true, defaultPosition: 40, cell: fn (MissingTaxDocument $row): string => $this->amounts->shown($row->received), phone: false),
            new Attribute('deadline', $label('deadline'), AttributeType::Date, static fn (MissingTaxDocument $row): \DateTimeImmutable => $row->deadline, sort: 'deadline', defaultVisible: true, defaultPosition: 50, cell: fn (MissingTaxDocument $row): string => $this->date($row->deadline), phone: false),
            new Attribute('days_left', $label('daysLeft'), AttributeType::Number, static fn (MissingTaxDocument $row): int => $row->daysLeft, defaultVisible: true, defaultPosition: 60, cell: fn (MissingTaxDocument $row): array => $this->days($row->daysLeft)),
        ];
    }

    /**
     * @return list<Attribute>
     */
    private function uncorrected(): array
    {
        $label = static fn (string $key): string => 'tax_document.uncorrected.column.'.$key;
        $number = fn (UncorrectedTaxDocument $row): string => $row->deducted ? $row->number.' · '.$this->translator->trans('tax_document.uncorrected.deducted') : $row->number;

        return [
            new Attribute('number', $label('number'), AttributeType::Text, $number, sort: 'number', defaultVisible: true, defaultPosition: 10, locked: true),
            new Attribute('customer', $label('customer'), AttributeType::Text, static fn (UncorrectedTaxDocument $row): string => $row->customerName, sort: 'customer', defaultVisible: true, defaultPosition: 20, phone: false, truncate: true),
            new Attribute('decisive_date', $label('decisiveDate'), AttributeType::Date, static fn (UncorrectedTaxDocument $row): \DateTimeImmutable => $row->decisiveDate, defaultVisible: true, defaultPosition: 30, cell: fn (UncorrectedTaxDocument $row): string => $this->date($row->decisiveDate), phone: false),
            new Attribute('amount', $label('amount'), AttributeType::Money, static fn (UncorrectedTaxDocument $row): Money => $row->amount, defaultVisible: true, defaultPosition: 40, cell: fn (UncorrectedTaxDocument $row): string => $this->amounts->shown($row->amount), phone: false),
            new Attribute('deadline', $label('deadline'), AttributeType::Date, static fn (UncorrectedTaxDocument $row): \DateTimeImmutable => $row->deadline, sort: 'deadline', defaultVisible: true, defaultPosition: 50, cell: fn (UncorrectedTaxDocument $row): string => $this->date($row->deadline), phone: false),
            new Attribute('days_left', $label('daysLeft'), AttributeType::Number, static fn (UncorrectedTaxDocument $row): int => $row->daysLeft, defaultVisible: true, defaultPosition: 60, cell: fn (UncorrectedTaxDocument $row): array => $this->days($row->daysLeft)),
        ];
    }

    private function date(\DateTimeImmutable $day): string
    {
        return $day->format($this->translator->trans('format.date'));
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function days(int $days): array
    {
        return ['text' => $this->daysLeft($days), 'tone' => $days < 0 ? 'red' : ($days <= 3 ? 'amber' : 'gray')];
    }
}
