<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Column;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Purchasing\Application\Query\ReceivedDocumentRow;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Purchasing\Infrastructure\Form\PurchasingText;
use Foresi\Purchasing\Infrastructure\Form\ReceivedDocumentScreen;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the received documents; each cell renders one ReceivedDocumentRow: the
 * numbers, the supplier and its number, the day that places the document in its month,
 * the total in its currency and the VAT in CZK, the treatment and the state. The firm
 * shows only when there are several. A draft has no number yet: it reads as a draft of
 * the day it came.
 */
final readonly class ReceivedDocumentColumns implements ListColumns
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

    public function columns(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'purchasing.list.column.'.$key;

        return [
            new Column('number', $label('number'), $this->number(...), defaultPosition: 10, locked: true),
            new Column('firm', $label('firm'), static fn (ReceivedDocumentRow $row): string => $row->firmName, defaultPosition: 20, phone: false, truncate: true, available: static fn (array $context): bool => true === ($context['severalFirms'] ?? false)),
            new Column('supplier', $label('supplier'), $this->supplier(...), defaultPosition: 30, phone: false, truncate: true),
            new Column('supplierNumber', $label('supplierNumber'), static fn (ReceivedDocumentRow $row): string => $row->supplierNumber, defaultPosition: 40, phone: false, wide: true),
            new Column('monthDay', $label('monthDay'), fn (ReceivedDocumentRow $row): string => $row->monthDay()?->format($this->translator->trans('format.date')) ?? '', defaultPosition: 50, phone: false),
            new Column('total', $label('total'), $this->total(...), defaultPosition: 60, align: 'right'),
            new Column('vatCzk', $label('vatCzk'), fn (ReceivedDocumentRow $row): string => $row->total instanceof Money && $row->vatCzk instanceof Money ? $this->text->amount($row->vatCzk) : '', defaultPosition: 70, align: 'right', phone: false),
            new Column('treatment', $label('treatment'), fn (ReceivedDocumentRow $row): string => $this->translator->trans('purchasing.treatment.'.$row->treatment->value), defaultPosition: 80, phone: false, wide: true),
            new Column('state', $label('state'), $this->state(...), defaultPosition: 90, phone: false),
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
     * @return array{text: string, tone: string, hint?: string}
     */
    private function state(ReceivedDocumentRow $row): array
    {
        $badge = $this->badge($row);

        return ['text' => $badge['label'], 'tone' => $badge['tone']] + ($row->isTrashed() && null !== $row->deletedReason ? ['hint' => $row->deletedReason] : []);
    }
}
