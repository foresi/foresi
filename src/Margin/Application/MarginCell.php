<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostLine;
use Foresi\Margin\Contract\RevenueDocument;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\Recipient;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * What stands behind one cell of the margin table: the revenue documents and
 * the cost shares of one row in one month, each with its label, amount, source
 * and url. Staff only.
 */
final readonly class MarginCell
{
    public function __construct(
        private MarginAccess $access,
        private MarginRanges $ranges,
        private RevenueSources $revenue,
        private CostShareReads $costs,
        private CostSources $sources,
        private PartyDirectory $contacts,
        private Unrebilled $unrebilled,
    ) {
    }

    /**
     * @param string $row   "client:<contact id>", "own" or "unassigned" (the keys of MarginRowView)
     * @param string $month YYYY-MM
     *
     * @throws MarginRefused forbidden, invalid_row or invalid_month
     */
    public function of(string $row, string $month): MarginCellView
    {
        $this->access->denyUnlessStaff();
        $first = $this->ranges->month($month);
        [$clientId, $recipient] = $this->rowOf($row);
        $zero = Money::ofMinor(0, Currency::CZK);

        $revenue = [];
        $revenueTotal = $zero;
        if (null !== $clientId) {
            $documents = array_filter(
                [...$this->revenue->between($first, $first->modify('last day of this month'))],
                static fn (RevenueDocument $document): bool => strtolower($document->customerId) === $clientId && $document->taxPointOn->format('Y-m') === $month,
            );
            usort($documents, static fn (RevenueDocument $one, RevenueDocument $two): int => [$one->taxPointOn, $one->number, $one->id] <=> [$two->taxPointOn, $two->number, $two->id]);
            foreach ($documents as $document) {
                $revenueTotal = $revenueTotal->plus($document->netCzk);
                $revenue[] = new MarginCellItem($document->id, $document->number, null, $document->netCzk, MarginCellItem::REVENUE, $document->kind, $document->url);
            }
        }

        $notRebilled = [];
        if (null !== $clientId) {
            $notRebilled = array_values(array_filter(
                $this->unrebilled->inRange(new MarginRange($first, $first->modify('last day of this month'), [$month])),
                static fn (UnrebilledOccurrence $occurrence): bool => $occurrence->recipientId === $clientId,
            ));
        }

        $costs = [];
        $costTotal = $zero;
        /** @var array<string, ?CostDocument> $loaded one read per document, however many shares it has */
        $loaded = [];
        foreach ($this->costs->sharesOf($first, $recipient) as $share) {
            $costTotal = $costTotal->plus($share->amount());
            $loaded[$share->documentId()] ??= $this->sources->document($share->documentId());
            $costs[] = $this->costItem($share, $loaded[$share->documentId()]);
        }
        usort($costs, static fn (MarginCellItem $one, MarginCellItem $two): int => [$two->amountCzk->minor(), $one->label, (string) $one->text] <=> [$one->amountCzk->minor(), $two->label, (string) $two->text]);

        return new MarginCellView($row, null !== $clientId ? $this->contacts->find($clientId)->displayName ?? $clientId : null, $month, new MarginFigures($revenueTotal, $costTotal, \count($notRebilled)), $revenue, $costs, $notRebilled);
    }

    /**
     * @return array{?string, ?string} the client's contact id, and the recipient the shares are stored with
     *
     * @throws MarginRefused invalid_row
     */
    private function rowOf(string $row): array
    {
        if (MarginRowView::UNASSIGNED === $row) {
            return [null, null];
        }
        if (MarginRowView::OWN === $row) {
            return [null, Recipient::own()->toString()];
        }
        if (str_starts_with($row, MarginRowView::CLIENT_PREFIX)) {
            try {
                $client = Recipient::client(substr($row, \strlen(MarginRowView::CLIENT_PREFIX)));

                return [$client->toString(), $client->toString()];
            } catch (\InvalidArgumentException) {
            }
        }

        throw MarginRefused::invalid(MarginRefused::INVALID_ROW, 'row', 'A row is client:<contact id>, own or unassigned.');
    }

    private function costItem(CostShare $share, ?CostDocument $document): MarginCellItem
    {
        $line = $document instanceof CostDocument ? array_find($document->lines, static fn (CostLine $line): bool => $line->key === $share->lineKey()) : null;

        return new MarginCellItem(
            $share->documentId(),
            $document instanceof CostDocument ? $document->label : $share->documentId(),
            $line instanceof CostLine ? $line->text : null,
            $share->amount(),
            $share->source()->value,
            null,
            $document instanceof CostDocument ? $document->url : null,
            $share->plannedItemId(),
            $share->occurrenceOn()?->format('Y-m-d'),
        );
    }
}
