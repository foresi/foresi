<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\NumberKey;
use App\Invoicing\Domain\Series;
use App\Party\Domain\LegalEntity;

/**
 * Gives a document its number inside the transaction that stores it. The period
 * is the one of the issue date, never of the moment of saving. Everything here
 * writes before it reads, as NumberCounter asks, and a failure here must end the
 * transaction, which returns what was taken.
 */
final readonly class NumberAllocator
{
    /** How many numbers a hand-typed or imported number may stand in the way of. */
    private const int ATTEMPTS = 50;

    public function __construct(private NumberCounter $counter, private InvoiceStore $store)
    {
    }

    /**
     * @param Invoice|null $except the invoice being renumbered
     *
     * @throws IssueRefused
     */
    public function allocate(Series $series, LegalEntity $firm, \DateTimeImmutable $issueDate, ?string $typedNumber, ?Invoice $except): string
    {
        try {
            return null === $typedNumber
                ? $this->next($series, $firm, $issueDate)
                : $this->typed($series, $firm, $issueDate, trim($typedNumber), $except);
        } catch (SequenceOutOfRange) {
            throw new IssueRefused([new IssueProblem('number', 'invoice.problem.number_out_of_range')]);
        }
    }

    private function next(Series $series, LegalEntity $firm, \DateTimeImmutable $issueDate): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            $number = $this->counter->next($series, $issueDate);
            if (!$this->store->numberTaken($firm, NumberKey::of($number), null)) {
                return $number;
            }
        }

        throw new IssueRefused([new IssueProblem('number', 'invoice.problem.numbers_exhausted')]);
    }

    private function typed(Series $series, LegalEntity $firm, \DateTimeImmutable $issueDate, string $number, ?Invoice $except): string
    {
        $sequence = $series->format()->sequenceIn($number, $issueDate);
        if (null !== $sequence) {
            $this->counter->advanceTo($series, $issueDate, $sequence);
        }
        // Again here: IssueRules looked before the transaction, and someone may have been faster.
        if ($this->store->numberTaken($firm, NumberKey::of($number), $except)) {
            throw new IssueRefused([new IssueProblem('number', 'invoice.problem.number_taken')]);
        }

        return $number;
    }
}
