<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Sales\Domain\BillingEntryKind;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Shared\Domain\Money;

/**
 * Reads the billing plan of a deal (BillingPlanView). An entry is invoiced
 * once a document fulfils it, overdue when its day is before today and
 * nothing does, planned otherwise.
 */
final readonly class BillingPlans
{
    public function __construct(
        private SalesStore $store,
        private BillingDocuments $documents,
        private InstanceClock $clock,
    ) {
    }

    /**
     * @param list<DealLine> $lines the deal's lines
     */
    public function of(Deal $deal, array $lines, DealValue $value): BillingPlanView
    {
        $byId = [];
        foreach ($lines as $line) {
            $byId[$line->id()] = $line;
        }
        $today = $this->clock->today()->format('Y-m-d');
        $views = [];
        $notPlanned = $value->oneOff;
        foreach ($this->store->billingPlanOf($deal->id()) as $entry) {
            $line = null === $entry->lineId() ? null : ($byId[$entry->lineId()] ?? null);
            $amount = BillingEntryKind::OneOff === $entry->kind() ? $entry->amount() : ($line instanceof DealLine ? BillingAmounts::ofLine($line) : null);
            $type = $entry->fulfilledType();
            $documentId = $entry->fulfilledId();
            $document = null === $type || null === $documentId ? null : $this->documents->find($type, $documentId);
            $state = match (true) {
                $entry->isFulfilled() => BillingEntryView::INVOICED,
                null !== $entry->expectedOn() && $entry->expectedOn()->format('Y-m-d') < $today => BillingEntryView::OVERDUE,
                default => BillingEntryView::PLANNED,
            };
            $views[] = new BillingEntryView($entry, $amount, $line, $document, $state);
            if (BillingEntryKind::OneOff === $entry->kind() && $amount instanceof Money && isset($notPlanned[$amount->currency()->value])) {
                $notPlanned[$amount->currency()->value] = $notPlanned[$amount->currency()->value]->minus($amount);
            }
        }

        return new BillingPlanView($views, array_filter($notPlanned, static fn (Money $left): bool => !$left->isZero() && !$left->isNegative()));
    }
}
