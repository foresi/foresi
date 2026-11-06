<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Application\Candidate;
use App\Banking\Application\Candidates;
use App\Shared\Domain\Currency;
use App\Tests\Invoicing\Application\InvoiceTestCase;

/**
 * Pairing offers what is left after the corrections: a credit note lowers it, a
 * debit note raises it, and a correction is never offered itself.
 */
final class CorrectedCandidatesTest extends InvoiceTestCase
{
    public function testCandidatesFollowTheCorrectionsAndLeaveOutAFullyCreditedInvoice(): void
    {
        $credited = $this->issue->issue($this->input());
        $this->correctionOf($credited, '-1000.00');
        $partly = $this->issue->issue($this->input());
        $this->correctionOf($partly, '-400.00');
        $debited = $this->issue->issue($this->input());
        $this->correctionOf($debited, '200.00');
        $this->em->clear();

        $open = self::getContainer()->get(Candidates::class)->open($this->firm->id(), Currency::CZK, '', [], 50);
        $left = array_combine(array_map(static fn (Candidate $candidate): string => $candidate->invoiceId, $open), array_map(static fn (Candidate $candidate): int => $candidate->remaining->minor(), $open));

        self::assertSame([$partly->id() => 72600, $debited->id() => 145200], $left);
    }

    public function testASymbolDoesNotOfferAFullyCreditedInvoice(): void
    {
        $credited = $this->issue->issue($this->input());
        $this->correctionOf($credited, '-1000.00');
        $this->em->clear();

        self::assertSame([], self::getContainer()->get(Candidates::class)->withSymbol($this->firm->id(), ltrim($credited->variableSymbol(), '0')));
    }
}
