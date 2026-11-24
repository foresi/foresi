<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Column;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Purchasing\Application\Query\ReceivedDocumentRow;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Purchasing\Domain\ReadingState;
use Foresi\Purchasing\Infrastructure\Form\PurchasingText;
use Foresi\Purchasing\Infrastructure\Form\ReceivedDocumentScreen;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the received documents; each cell renders one ReceivedDocumentRow: the
 * numbers, the supplier and its number, the day that places the document in its month,
 * the total in its currency and the VAT in CZK, the treatment, how it came in and the
 * state. A draft has no number yet: it reads as a draft of the day it came; a draft
 * made from a file shows where its reading stands as a second badge.
 */
final readonly class ReceivedDocumentColumns implements DataSource
{
    public const string LIST = ReceivedDocumentLists::KEY;

    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private PurchasingText $text,
        private ReceivedDocumentScreen $screen,
        private InstanceClock $clock,
    ) {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'purchasing.list.column.'.$key;

        return [
            new Attribute('number', $label('number'), AttributeType::Text, $this->number(...), defaultVisible: true, defaultPosition: 10, cell: $this->number(...), locked: true, filterable: true, filter: 'd.evidenceNumber'),
            new Attribute('supplier', $label('supplier'), AttributeType::Reference, static fn (ReceivedDocumentRow $row): string => $row->supplierName, defaultVisible: true, defaultPosition: 30, cell: $this->supplier(...), phone: false, truncate: true),
            new Attribute('supplier_number', $label('supplierNumber'), AttributeType::Text, static fn (ReceivedDocumentRow $row): string => $row->supplierNumber, defaultVisible: true, defaultPosition: 40, cell: static fn (ReceivedDocumentRow $row): string => $row->supplierNumber, aliases: ['supplierNumber'], phone: false, wide: true, filterable: true, filter: 'd.supplierNumber'),
            new Attribute('month_day', $label('monthDay'), AttributeType::Date, static fn (ReceivedDocumentRow $row): ?\DateTimeImmutable => $row->monthDay(), defaultVisible: true, defaultPosition: 50, cell: fn (ReceivedDocumentRow $row): string => $row->monthDay()?->format($this->translator->trans('format.date')) ?? '', aliases: ['monthDay'], phone: false),
            new Attribute('total', $label('total'), AttributeType::Money, static fn (ReceivedDocumentRow $row): ?Money => $row->total, defaultVisible: true, defaultPosition: 60, cell: $this->total(...), filterable: true, filter: 'd.totalMinor'),
            new Attribute('vat_czk', $label('vatCzk'), AttributeType::Money, static fn (ReceivedDocumentRow $row): ?Money => $row->vatCzk, defaultVisible: true, defaultPosition: 70, cell: fn (ReceivedDocumentRow $row): string => $row->total instanceof Money && $row->vatCzk instanceof Money ? $this->text->amount($row->vatCzk) : '', aliases: ['vatCzk'], phone: false, filterable: true, filter: 'd.vatCzkMinor'),
            new Attribute('treatment', $label('treatment'), AttributeType::Text, static fn (ReceivedDocumentRow $row): string => $row->treatment->value, defaultVisible: true, defaultPosition: 80, cell: fn (ReceivedDocumentRow $row): string => $this->translator->trans('purchasing.treatment.'.$row->treatment->value), phone: false, wide: true),
            new Attribute('source', $label('source'), AttributeType::Text, static fn (ReceivedDocumentRow $row): string => $row->source->value, defaultVisible: true, defaultPosition: 85, cell: fn (ReceivedDocumentRow $row): string => $this->translator->trans('purchasing.source.'.$row->source->value), phone: false),
            new Attribute('state', $label('state'), AttributeType::Text, fn (ReceivedDocumentRow $row): string => $this->badge($row)['label'], defaultVisible: true, defaultPosition: 90, cell: $this->state(...), phone: false),
        ];
    }

    /**
     * The evidence number, or for a draft the day it came.
     */
    public function number(ReceivedDocumentRow $row): string
    {
        return $row->evidenceNumber ?? $this->translator->trans('purchasing.list.draft_label', ['date' => $row->createdAt->setTimezone($this->clock->timezone())->format($this->translator->trans('format.date'))]);
    }

    public function total(ReceivedDocumentRow $row): string
    {
        return $row->total instanceof Money ? $this->text->amount($row->total) : '';
    }

    /**
     * @return array{label: string, tone: string}
     */
    public function badge(ReceivedDocumentRow $row): array
    {
        return $this->screen->badge($row->isTrashed(), null !== $row->evidenceNumber);
    }

    /**
     * @return string|array{links: list<array{text: string, href: string}>, title: string}
     */
    private function supplier(ReceivedDocumentRow $row): string|array
    {
        if (null === $row->supplierId || '' === $row->supplierName) {
            return $row->supplierName;
        }

        return ['links' => [['text' => $row->supplierName, 'href' => $this->urls->generate('app_contact_show', ['id' => $row->supplierId])]], 'title' => $row->supplierName];
    }

    /**
     * The state, and for a draft out of the trash where the reading of its file stands.
     *
     * @return array{text: string, tone: string, hint?: string}|array{badges: list<array{label: string, tone: string}>}
     */
    private function state(ReceivedDocumentRow $row): array
    {
        $badge = $this->badge($row);
        if ($row->readingState instanceof ReadingState && null === $row->evidenceNumber && !$row->isTrashed()) {
            return ['badges' => [$badge, [
                'label' => $this->translator->trans('purchasing.reading_state.'.$row->readingState->value),
                'tone' => match ($row->readingState) {
                    ReadingState::Read => 'green',
                    ReadingState::Failed => 'red',
                    default => 'gray',
                },
            ]]];
        }

        return ['text' => $badge['label'], 'tone' => $badge['tone']] + ($row->isTrashed() && null !== $row->deletedReason ? ['hint' => $row->deletedReason] : []);
    }
}
