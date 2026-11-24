<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\Recipient;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * The margin per client and month: revenue (the documents the revenue sources
 * give, on their tax point, at net CZK) less costs (the stored cost shares,
 * which follow the mappings). Staff only.
 *
 * Every cost share lands in a row: a client's, the firm's own use or
 * "unassigned", which is always there, so the costs of the table are the sum
 * of all shares in the range. A client that has revenue or costs in the range
 * has a row, whether it is still an active contact or not. Without a revenue
 * source the table has costs only.
 */
final readonly class MarginTable
{
    public function __construct(
        private MarginAccess $access,
        private MarginRanges $ranges,
        private RevenueSources $revenue,
        private CostShareReads $costs,
        private CostShareCalculation $calculation,
        private CostSources $sources,
        private KnownGaps $gaps,
        private PartyDirectory $contacts,
        private Unrebilled $unrebilled,
    ) {
    }

    /**
     * @param string|null $fromMonth YYYY-MM; the last three months ending with the current one when both are null
     * @param string|null $toMonth   YYYY-MM, the current month when null
     *
     * @throws MarginRefused forbidden, invalid_month, range_reversed or range_too_long
     */
    public function of(?string $fromMonth = null, ?string $toMonth = null): MarginTableView
    {
        $this->access->denyUnlessStaff();
        $range = $this->ranges->range($fromMonth, $toMonth);
        $zero = Money::ofMinor(0, Currency::CZK);

        /** @var array<string, array<string, Money>> $revenue client id => month => revenue */
        $revenue = [];
        foreach ($this->revenue->between($range->firstDay(), $range->lastDay()) as $document) {
            $month = $document->taxPointOn->format('Y-m');
            if (!$range->contains($month)) {
                continue;
            }
            $client = strtolower($document->customerId);
            $revenue[$client][$month] = ($revenue[$client][$month] ?? $zero)->plus($document->netCzk);
        }
        /** @var array<string, array<string, int>> $unrebilled client id => month => paid costs not rebilled */
        $unrebilled = [];
        foreach ($this->unrebilled->inRange($range) as $occurrence) {
            $unrebilled[$occurrence->recipientId][$occurrence->month] = ($unrebilled[$occurrence->recipientId][$occurrence->month] ?? 0) + 1;
        }
        /** @var array<string, array<string, Money>> $costs row key => month => costs */
        $costs = [MarginRowView::OWN => [], MarginRowView::UNASSIGNED => []];
        foreach ($this->costs->totals($range->firstDay(), $range->lastDay()) as $total) {
            $key = $this->rowOf($total['recipient']);
            $costs[$key][$total['month']] = ($costs[$key][$total['month']] ?? $zero)->plus($total['amount']);
        }

        $clients = array_unique([...array_keys($revenue), ...array_keys($unrebilled), ...array_map(static fn (string $key): string => substr($key, \strlen(MarginRowView::CLIENT_PREFIX)), array_filter(array_keys($costs), static fn (string $key): bool => str_starts_with($key, MarginRowView::CLIENT_PREFIX)))]);
        $rows = array_map(fn (string $id): MarginRowView => $this->row(MarginRowView::CLIENT_PREFIX.$id, 'client', $id, $this->nameOf($id), $range, $revenue[$id] ?? [], $costs[MarginRowView::CLIENT_PREFIX.$id] ?? [], $unrebilled[$id] ?? []), $clients);
        usort($rows, static fn (MarginRowView $one, MarginRowView $two): int => [$two->total->revenue->minor(), mb_strtolower($one->name ?? ''), $one->key] <=> [$one->total->revenue->minor(), mb_strtolower($two->name ?? ''), $two->key]);
        $rows[] = $this->row(MarginRowView::OWN, 'own', null, null, $range, [], $costs[MarginRowView::OWN]);
        $rows[] = $this->row(MarginRowView::UNASSIGNED, 'unassigned', null, null, $range, [], $costs[MarginRowView::UNASSIGNED]);

        $monthTotals = array_fill_keys($range->months, MarginFigures::none());
        $total = MarginFigures::none();
        foreach ($rows as $row) {
            foreach ($row->cells as $month => $cell) {
                $monthTotals[$month] = $monthTotals[$month]->plus($cell);
            }
            $total = $total->plus($row->total);
        }

        return new MarginTableView($range->fromMonth(), $range->toMonth(), $range->months, $rows, $monthTotals, $total, $this->knownGaps($range), $this->costs->unassignedDocuments($range->firstDay(), $range->lastDay()));
    }

    /**
     * @param 'client'|'own'|'unassigned' $kind
     * @param array<string, Money>        $revenue    by month
     * @param array<string, Money>        $costs      by month
     * @param array<string, int>          $unrebilled by month
     */
    private function row(string $key, string $kind, ?string $clientId, ?string $name, MarginRange $range, array $revenue, array $costs, array $unrebilled = []): MarginRowView
    {
        $zero = Money::ofMinor(0, Currency::CZK);
        $cells = [];
        $total = MarginFigures::none();
        foreach ($range->months as $month) {
            $cells[$month] = new MarginFigures($revenue[$month] ?? $zero, $costs[$month] ?? $zero, $unrebilled[$month] ?? 0);
            $total = $total->plus($cells[$month]);
        }

        return new MarginRowView($key, $kind, $clientId, $name, $cells, $total);
    }

    private function rowOf(?string $recipient): string
    {
        if (null === $recipient) {
            return MarginRowView::UNASSIGNED;
        }
        $recipient = Recipient::fromString($recipient);

        return $recipient->isOwn() ? MarginRowView::OWN : MarginRowView::CLIENT_PREFIX.$recipient->toString();
    }

    private function nameOf(string $contactId): string
    {
        return $this->contacts->find($contactId)->displayName ?? $contactId;
    }

    /**
     * @return list<KnownGap>
     */
    private function knownGaps(MarginRange $range): array
    {
        $missing = [];
        foreach ($this->gaps->documentsMissingExchangeRate($this->sources->documentIdsWithoutExchangeRate()) as $id) {
            $month = $this->calculation->costMonthOf($id);
            if ($month instanceof \DateTimeImmutable && $range->contains($month->format('Y-m'))) {
                $missing[] = $id;
            }
        }

        return [] === $missing ? [] : [new KnownGap(KnownGaps::MISSING_EXCHANGE_RATE, $missing)];
    }
}
