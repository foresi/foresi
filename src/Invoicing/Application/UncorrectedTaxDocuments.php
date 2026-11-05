<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The delivered tax documents on payment that need a corrective tax document and
 * have none yet, with the days left until the deadline of each, 15 days from the
 * day of the fact, soonest deadline first: those a final invoice deducts, and those
 * a rule refused when their payment was cancelled or refunded.
 */
final readonly class UncorrectedTaxDocuments
{
    public function __construct(
        private AdvanceDocuments $documents,
        private InvoiceStore $store,
        private CorrectPaymentTaxDocument $corrections,
    ) {
    }

    /**
     * @param \DateTimeImmutable $today the day the days left are counted from
     *
     * @return list<UncorrectedTaxDocument>
     */
    public function on(\DateTimeImmutable $today): array
    {
        $day = new \DateTimeImmutable($today->format('Y-m-d'));
        $waiting = [];
        foreach ($this->documents->correctionCandidates() as $id) {
            $document = $this->store->get($id);
            $due = $this->corrections->due($document);
            if (!$due instanceof CorrectionDue) {
                continue;
            }
            $deadline = $due->deadline();
            $waiting[] = new UncorrectedTaxDocument(
                $document->id(),
                $document->number(),
                $document->customer()->name,
                $due->amount,
                $due->refunded,
                null !== $document->deductedByInvoiceId(),
                $due->decisiveDate,
                $deadline,
                (int) $day->diff($deadline)->format('%r%a'),
            );
        }
        usort($waiting, static fn (UncorrectedTaxDocument $a, UncorrectedTaxDocument $b): int => [$a->deadline, $a->number] <=> [$b->deadline, $b->number]);

        return $waiting;
    }
}
