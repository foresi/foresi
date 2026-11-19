<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\View;

use Foresi\Invoicing\Application\MissingTaxDocument;
use Foresi\Invoicing\Application\MissingTaxDocuments;
use Foresi\Invoicing\Application\UncorrectedTaxDocument;
use Foresi\Invoicing\Application\UncorrectedTaxDocuments;
use Foresi\Shared\Application\View\RowConditions;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The rows of the two lists a tax document waits in, as a view and a search narrow
 * them: the list and the count of a view ask the same, so they always agree.
 *
 * @phpstan-type QueueRow MissingTaxDocument|UncorrectedTaxDocument
 */
final readonly class TaxDocumentQueues
{
    public function __construct(
        private MissingTaxDocuments $missing,
        private UncorrectedTaxDocuments $uncorrected,
    ) {
    }

    /**
     * Every row of the list on the day, soonest deadline first.
     *
     * @return list<QueueRow>
     */
    public function rows(string $listKey, \DateTimeImmutable $today): array
    {
        return match ($listKey) {
            TaxDocumentQueueLists::MISSING => $this->missing->on($today),
            TaxDocumentQueueLists::UNCORRECTED => $this->uncorrected->on($today),
            default => throw new \InvalidArgumentException(\sprintf('The list "%s" is not one a tax document waits in.', $listKey)),
        };
    }

    /**
     * The rows a view's filter keeps.
     *
     * @param list<QueueRow> $rows
     *
     * @return list<QueueRow>
     */
    public static function matching(array $rows, string $listKey, ViewFilter $filter, \DateTimeImmutable $today): array
    {
        $list = TaxDocumentQueueLists::MISSING === $listKey ? TaxDocumentQueueLists::missing() : TaxDocumentQueueLists::uncorrected();

        return RowConditions::filter($rows, $filter, $list, self::valueOf(...), $today);
    }

    /**
     * The rows whose number or customer holds the text, whatever its case.
     *
     * @param list<QueueRow> $rows
     *
     * @return list<QueueRow>
     */
    public static function searched(array $rows, string $text): array
    {
        // No text holds every row: each holds the empty string.
        $needle = mb_strtolower($text);

        return array_values(array_filter($rows, static fn (MissingTaxDocument|UncorrectedTaxDocument $row): bool => str_contains(mb_strtolower(self::number($row).' '.$row->customerName), $needle)));
    }

    public static function number(MissingTaxDocument|UncorrectedTaxDocument $row): string
    {
        return $row instanceof MissingTaxDocument ? $row->advanceNumber : $row->number;
    }

    /**
     * The value of a field on a row, as RowConditions compares it: an amount in whole units.
     */
    public static function valueOf(MissingTaxDocument|UncorrectedTaxDocument $row, string $field): mixed
    {
        $missing = $row instanceof MissingTaxDocument;

        return match ($field) {
            TaxDocumentQueueLists::CUSTOMER => $row->customerName,
            TaxDocumentQueueLists::AMOUNT => ($missing ? $row->received : $row->amount)->minor() / 100,
            TaxDocumentQueueLists::RECEIVED_ON => $missing ? $row->receivedOn : null,
            TaxDocumentQueueLists::DECISIVE_DATE => $missing ? null : $row->decisiveDate,
            TaxDocumentQueueLists::DEADLINE => $row->deadline,
            TaxDocumentQueueLists::DEDUCTED => !$missing && $row->deducted,
            default => throw new \LogicException('A checked condition names a field of its list.'),
        };
    }
}
