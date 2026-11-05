<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The paid advance invoices that still need a tax document: the payments without
 * one on which VAT arose, as the rules tell now, with the days left until the
 * deadline of each, soonest deadline first. A document a rule refused at payment
 * waits here until a person issues it.
 */
final readonly class MissingTaxDocuments
{
    public function __construct(
        private AdvanceDocuments $documents,
        private InvoiceStore $store,
        private InvoicePayments $payments,
        private AdvanceTaxation $taxation,
    ) {
    }

    /**
     * @param \DateTimeImmutable $today the day the days left are counted from
     *
     * @return list<MissingTaxDocument>
     */
    public function on(\DateTimeImmutable $today): array
    {
        $day = new \DateTimeImmutable($today->format('Y-m-d'));
        $missing = [];
        foreach ($this->documents->paidWithoutTaxDocument() as $row) {
            $advance = $this->store->get($row['advanceId']);
            $taxation = $this->taxation->of($advance);
            if (!$taxation->arises) {
                continue;
            }
            foreach ($this->payments->linesOf($advance) as $line) {
                if ($line->paymentId === $row['paymentId']) {
                    $deadline = $taxation->deadline($line->receivedOn);
                    $missing[] = new MissingTaxDocument(
                        $advance->id(),
                        $advance->number(),
                        $advance->issuer()->name,
                        $advance->customer()->name,
                        $line->paymentId,
                        $line->receivedOn,
                        $line->allocated,
                        $deadline,
                        (int) $day->diff($deadline)->format('%r%a'),
                    );
                }
            }
        }
        usort($missing, static fn (MissingTaxDocument $a, MissingTaxDocument $b): int => [$a->deadline, $a->receivedOn, $a->advanceNumber] <=> [$b->deadline, $b->receivedOn, $b->advanceNumber]);

        return $missing;
    }
}
