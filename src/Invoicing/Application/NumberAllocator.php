<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\NumberKey;
use Foresi\Invoicing\Domain\Series;
use Foresi\Party\Application\InstanceFirm;

/**
 * Gives a document its number inside the transaction that stores it. The period
 * is the one of the issue date, never of the moment of saving. The counter comes
 * before any read here, as NumberCounter asks; on an edit the lock of the invoice
 * came before it and holds the write already. A failure here must end the
 * transaction, which returns what was taken.
 */
final readonly class NumberAllocator
{
    /** How many numbers a hand-typed or imported number may stand in the way of. */
    private const int ATTEMPTS = 50;

    public function __construct(private NumberCounter $counter, private InvoiceStore $store, private InvoicingCountries $countries, private InstanceFirm $instanceFirm)
    {
    }

    /**
     * @param Invoice|null $except the invoice being renumbered
     *
     * @throws IssueRefused
     */
    public function allocate(Series $series, \DateTimeImmutable $issueDate, ?string $typedNumber, ?Invoice $except): string
    {
        try {
            return null === $typedNumber
                ? $this->next($series, $issueDate)
                : $this->typed($series, $issueDate, trim($typedNumber), $except);
        } catch (SequenceOutOfRange) {
            throw new IssueRefused([new IssueProblem('number', 'invoice.problem.number_out_of_range')]);
        }
    }

    private function next(Series $series, \DateTimeImmutable $issueDate): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            $number = $this->counter->next($series, $issueDate);
            // A series may be older than the rule, or stored by something else than the form.
            if (!$this->accepted($number)) {
                throw new IssueRefused([new IssueProblem('number', 'invoice.problem.number_not_accepted')]);
            }
            if (!$this->store->numberTaken(NumberKey::of($number), null)) {
                return $number;
            }
        }

        throw new IssueRefused([new IssueProblem('number', 'invoice.problem.numbers_exhausted')]);
    }

    private function accepted(string $number): bool
    {
        try {
            return $this->countries->for($this->instanceFirm->get()->address()->countryCode)->acceptsNumber($number);
        } catch (UnsupportedCountry) {
            return true;
        }
    }

    private function typed(Series $series, \DateTimeImmutable $issueDate, string $number, ?Invoice $except): string
    {
        $sequence = $series->format()->sequenceIn($number, $issueDate);
        if (null !== $sequence) {
            $this->counter->advanceTo($series, $issueDate, $sequence);
        }
        // Again here: IssueRules looked before the transaction, and someone may have been faster.
        if ($this->store->numberTaken(NumberKey::of($number), $except)) {
            throw new IssueRefused([new IssueProblem('number', 'invoice.problem.number_taken')]);
        }

        return $number;
    }
}
