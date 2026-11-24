<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api;

use Foresi\Margin\Application\DocumentAllocationView;
use Foresi\Margin\Application\KnownGap;
use Foresi\Margin\Application\LineAllocationView;
use Foresi\Margin\Application\MappingView;
use Foresi\Margin\Application\MarginCellItem;
use Foresi\Margin\Application\MarginCellView;
use Foresi\Margin\Application\MarginFigures;
use Foresi\Margin\Application\MarginRowView;
use Foresi\Margin\Application\MarginTableView;
use Foresi\Margin\Application\PlannedAllocationsView;
use Foresi\Margin\Application\PlannedAllocationVersionView;
use Foresi\Margin\Application\ShareView;
use Foresi\Margin\Application\SupplierDefaultsView;
use Foresi\Margin\Application\SupplierDefaultVersionView;
use Foresi\Margin\Application\TermView;
use Foresi\Shared\Infrastructure\Api\JsonValues;

/**
 * The margin as REST and MCP write it: snake_case keys, money in minor units
 * with its currency.
 */
final class MarginJson
{
    /**
     * @return array<string, mixed>
     */
    public static function document(DocumentAllocationView $view): array
    {
        return [
            'document_id' => $view->documentId,
            'label' => $view->label,
            'url' => $view->url,
            'trashed' => $view->trashed,
            'document_allocation' => null === $view->documentRecipient ? null : ['recipient' => $view->documentRecipient, 'recipient_name' => $view->documentRecipientName],
            'lines' => array_map(self::line(...), $view->lines),
            'known_gaps' => $view->knownGaps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(LineAllocationView $line): array
    {
        return [
            'key' => $line->key,
            'text' => $line->text,
            'base_czk' => $line->baseCzk instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($line->baseCzk) : null,
            'allocation' => $line->mapping instanceof MappingView ? self::mapping($line->mapping) : null,
            'shares' => array_map(static fn (ShareView $share): array => [
                'recipient' => $share->recipient,
                'recipient_name' => $share->recipientName,
                'amount' => JsonValues::money($share->amountCzk),
                'source' => $share->source,
                'planned_item_id' => $share->plannedItemId,
                'occurrence_on' => $share->occurrenceOn,
            ], $line->shares),
        ];
    }

    /**
     * The margin table: a cell for every month of the range in every row, so a
     * reader never has to fill a gap.
     *
     * @return array<string, mixed>
     */
    public static function table(MarginTableView $view): array
    {
        return [
            'from' => $view->from,
            'to' => $view->to,
            'months' => $view->months,
            'rows' => array_map(static fn (MarginRowView $row): array => [
                'row' => $row->key,
                'kind' => $row->kind,
                'client_id' => $row->clientId,
                'label' => $row->label(),
                'cells' => array_map(static fn (string $month): array => self::cell($month, $row->cells[$month] ?? MarginFigures::none()), $view->months),
                'total' => self::figures($row->total),
            ], $view->rows),
            'month_totals' => array_map(static fn (string $month): array => self::cell($month, $view->monthTotals[$month] ?? MarginFigures::none()), $view->months),
            'total' => self::figures($view->total),
            'known_gaps' => array_map(static fn (KnownGap $gap): array => ['code' => $gap->code, 'document_ids' => $gap->documentIds], $view->knownGaps),
        ];
    }

    /**
     * The documents and the cost shares behind one cell of the table.
     *
     * @return array<string, mixed>
     */
    public static function cellDetail(MarginCellView $view): array
    {
        return [
            'row' => $view->row,
            'label' => $view->label(),
            'month' => $view->month,
        ] + self::figures($view->figures) + [
            'revenue_items' => array_map(self::item(...), $view->revenue),
            'cost_items' => array_map(self::item(...), $view->costs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cell(string $month, MarginFigures $figures): array
    {
        return ['month' => $month] + self::figures($figures);
    }

    /**
     * @return array{revenue: array{amount_minor: int, currency: string}, costs: array{amount_minor: int, currency: string}, margin: array{amount_minor: int, currency: string}}
     */
    private static function figures(MarginFigures $figures): array
    {
        return ['revenue' => JsonValues::money($figures->revenue), 'costs' => JsonValues::money($figures->costs), 'margin' => JsonValues::money($figures->margin)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(MarginCellItem $item): array
    {
        return [
            'document_id' => $item->documentId,
            'label' => $item->label,
            'text' => $item->text,
            'amount' => JsonValues::money($item->amountCzk),
            'source' => $item->source,
            'kind' => $item->kind,
            'url' => $item->url,
            'planned_item_id' => $item->plannedItemId,
            'occurrence_on' => $item->occurrenceOn,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function planned(PlannedAllocationsView $view): array
    {
        return [
            'planned_item_id' => $view->plannedItemId,
            'name' => $view->name,
            'versions' => array_map(static fn (PlannedAllocationVersionView $version): array => [
                'from_month' => $version->fromMonth,
                'allocation' => $version->mapping instanceof MappingView ? self::mapping($version->mapping) : null,
                'current' => $version->current,
                'created_at' => $version->createdAt->format(\DATE_ATOM),
                'created_by' => $version->createdBy,
            ], $view->versions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function supplierDefault(SupplierDefaultsView $view): array
    {
        return [
            'supplier_id' => $view->supplierId,
            'supplier_name' => $view->supplierName,
            'versions' => array_map(static fn (SupplierDefaultVersionView $version): array => [
                'from_month' => $version->fromMonth,
                'recipient' => $version->recipient,
                'recipient_name' => $version->recipientName,
                'current' => $version->current,
            ], $view->versions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapping(MappingView $mapping): array
    {
        return [
            'kind' => $mapping->kind,
            'shares' => array_map(static fn (TermView $term): array => [
                'recipient' => $term->recipient,
                'recipient_name' => $term->recipientName,
                'percent' => $term->percent,
                'amount' => $term->amountCzk instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($term->amountCzk) : null,
                'rest' => $term->rest,
            ], $mapping->terms),
        ];
    }
}
