<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\DocumentPairer;
use Foresi\CashFlow\Contract\Event\DocumentPairingChanged;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\CashFlow\Contract\PairableDocumentSource;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\PairingCandidate;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Shared\Application\Event\EventRecorder;
use Psr\Clock\ClockInterface;

/**
 * Decides whether a received document belongs to a planned occurrence.
 *
 * The candidates are the recurring, included planned items of the document's
 * direction and counterparty with an occurrence whose period holds the
 * document's day. The document pairs on its own (auto) when there is exactly
 * one candidate, no other document of the counterparty and direction sits in
 * that period (a declined one does not count), the currency is the item's and
 * the amount is within the item's tolerance of what the forecast expects for
 * the occurrence. Otherwise a person decides: the document is proposed with
 * its candidates. With no candidate it has no row.
 *
 * Only a person changes a confirmed or declined row. Every change of a row
 * records DocumentPairingChanged, and nothing is recorded when nothing changed.
 * The writes run in one unit of work, which nests in the caller's.
 */
final readonly class Pairing implements DocumentPairer
{
    /**
     * @param iterable<PairableDocumentSource> $sources
     */
    public function __construct(
        private CashFlowStore $store,
        private PlannedItemVatRule $vat,
        private PlannedOccurrences $occurrences,
        private InstanceClock $instanceClock,
        private TransactionalWork $transaction,
        private EventRecorder $events,
        private ClockInterface $clock,
        private iterable $sources,
    ) {
    }

    public function consider(PairableDocument $document): void
    {
        $this->transaction->run(function () use ($document): void {
            $touched = $this->settle($document, null);
            $this->reconsiderSiblings($document->subject, null, $touched);
        });
    }

    public function forget(FlowSubject $subject): void
    {
        $this->transaction->run(function () use ($subject): void {
            $row = $this->store->pairing($subject->type, $subject->id);
            if (!$row instanceof DocumentPairing) {
                return;
            }
            $touched = $this->touched($row);
            $this->remove($row);
            $this->reconsiderSiblings($subject, $subject, $touched);
        });
    }

    /**
     * Decides one document and writes its row. A change of the candidate list alone, with the same state, item
     * and occurrence day, is stored but records no event.
     *
     * @param FlowSubject|null $ignored a document that is gone or going, which no longer shares a period
     *
     * @return list<array{?string, PairingCandidate}> the occurrences, with the counterparty, the document was or is in
     */
    private function settle(PairableDocument $document, ?FlowSubject $ignored): array
    {
        $row = $this->store->pairing($document->subject->type, $document->subject->id);
        if ($row instanceof DocumentPairing && $row->isDecided()) {
            return [];
        }
        $touched = $row instanceof DocumentPairing ? $this->touched($row) : [];
        $found = $this->candidatesOf($document);
        if ([] === $found) {
            if ($row instanceof DocumentPairing) {
                $this->remove($row);
            }

            return $touched;
        }
        [$item, $on] = $found[0];
        $auto = 1 === \count($found) && $this->fits($item, $on, $document) && !$this->isShared($document, $item, $on, $ignored);
        $candidates = array_map(static fn (array $found): PairingCandidate => new PairingCandidate($found[0]->id(), $found[1]), $found);
        $counterparty = $item->counterparty();
        $now = $this->clock->now();
        $before = $row instanceof DocumentPairing ? $this->facts($row) : null;
        if ($row instanceof DocumentPairing) {
            $auto ? $row->becomeAuto($item, $on, $counterparty, $now) : $row->becomeProposed($candidates, 1 === \count($found) ? $item : null, $counterparty, $now);
        } else {
            $row = $auto
                ? DocumentPairing::auto($document->subject->type, $document->subject->id, $counterparty, $item, $on, $now)
                : DocumentPairing::propose($document->subject->type, $document->subject->id, $counterparty, $candidates, 1 === \count($found) ? $item : null, $now);
            $this->store->add($row);
        }
        $after = $this->facts($row);
        if ($before !== $after) {
            $this->events->record(new DocumentPairingChanged($row->subjectType(), $row->subjectId(), $after[0], $after[1], $after[2], $before[1] ?? null));
        }

        return [...$touched, ...array_map(static fn (PairingCandidate $candidate): array => [$document->counterpartyId, $candidate], $candidates)];
    }

    /**
     * The other documents in the periods a document touched get another look, so a document that was alone
     * can pair again and one that is no longer alone becomes a proposal.
     *
     * @param FlowSubject|null                       $gone    the document that was forgotten, which no longer shares a period
     * @param list<array{?string, PairingCandidate}> $touched
     */
    private function reconsiderSiblings(FlowSubject $subject, ?FlowSubject $gone, array $touched): void
    {
        $seen = [$subject->type.':'.$subject->id => true];
        foreach ($touched as [$counterpartyId, $candidate]) {
            $item = $this->store->plannedItem($candidate->plannedItemId);
            if (null === $counterpartyId || !$item instanceof PlannedItem) {
                continue;
            }
            [$first, $last] = PlannedFulfilment::period($item, $candidate->occurrenceOn->setTimezone($this->instanceClock->timezone()));
            foreach ($this->documentsOf($counterpartyId, $first, $last) as $other) {
                $key = $other->subject->type.':'.$other->subject->id;
                $row = isset($seen[$key]) ? null : $this->store->pairing($other->subject->type, $other->subject->id);
                $seen[$key] = true;
                if ($row instanceof DocumentPairing && !$row->isDecided()) {
                    $this->settle($other, $gone);
                }
            }
        }
    }

    /**
     * @return list<array{PlannedItem, \DateTimeImmutable}> the items and the occurrence days that hold the document's day
     */
    private function candidatesOf(PairableDocument $document): array
    {
        if (null === $document->counterpartyId) {
            return [];
        }
        $found = [];
        foreach ($this->store->plannedItems() as $item) {
            if (!$item->isIncluded() || !$item->isRecurring() || $item->direction() !== $document->direction || $item->counterparty()?->id() !== $document->counterpartyId) {
                continue;
            }
            $on = $this->occurrences->holding($item, $document->on);
            if ($on instanceof \DateTimeImmutable) {
                $found[] = [$item, $on];
            }
        }

        return $found;
    }

    /**
     * Whether the currency is the item's and the amount is within its tolerance of what the forecast expects.
     */
    private function fits(PlannedItem $item, \DateTimeImmutable $on, PairableDocument $document): bool
    {
        $expected = $this->vat->amountOn($item, $on);

        return $document->amount->currency() === $expected->currency()
            && abs($document->amount->minor() - $expected->minor()) <= ($item->matchRule()->toleranceMinor() ?? 0);
    }

    /**
     * Whether another document of the counterparty and direction sits in the period of the occurrence.
     */
    private function isShared(PairableDocument $document, PlannedItem $item, \DateTimeImmutable $on, ?FlowSubject $ignored): bool
    {
        [$first, $last] = PlannedFulfilment::period($item, $on);
        foreach ($this->documentsOf((string) $document->counterpartyId, $first, $last) as $other) {
            if ($other->direction !== $document->direction || $this->same($other->subject, $document->subject) || ($ignored instanceof FlowSubject && $this->same($other->subject, $ignored))) {
                continue;
            }
            if (PairingState::Declined !== $this->store->pairing($other->subject->type, $other->subject->id)?->state()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<PairableDocument>
     */
    private function documentsOf(string $counterpartyId, \DateTimeImmutable $first, \DateTimeImmutable $last): iterable
    {
        foreach ($this->sources as $source) {
            yield from $source->of($counterpartyId, $first, $last);
        }
    }

    private function remove(DocumentPairing $row): void
    {
        $this->events->record(new DocumentPairingChanged($row->subjectType(), $row->subjectId(), null, null, null, $row->plannedItemId()));
        $this->store->remove($row);
    }

    /**
     * @return array{string, ?string, ?string} state, planned item and occurrence day
     */
    private function facts(DocumentPairing $row): array
    {
        return [$row->state()->value, $row->plannedItemId(), $row->occurrenceOn()?->format('Y-m-d')];
    }

    /**
     * @return list<array{?string, PairingCandidate}>
     */
    private function touched(DocumentPairing $row): array
    {
        return array_map(static fn (PairingCandidate $candidate): array => [$row->counterpartyId(), $candidate], $row->candidates());
    }

    private function same(FlowSubject $a, FlowSubject $b): bool
    {
        return $a->type === $b->type && $a->id === $b->id;
    }
}
