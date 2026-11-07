<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

use App\Banking\Application\Candidate;
use App\Banking\Application\Candidates;
use App\Shared\Domain\Currency;

/**
 * What a pairing read and locked, in order: "read:" with the ids of the candidates
 * it was given, "lock:" with the invoice it locked and "row:" with the invoice whose
 * row it locked inside a transaction that went on. LoggingInvoiceStore writes
 * the locks; as Candidates it writes the reads, and lets a test change what the
 * database holds after a read, as another transaction committing would.
 */
final class LockLog implements Candidates
{
    /** @var list<string> */
    public array $entries = [];

    private int $reads = 0;

    /**
     * @param \Closure(int, list<Candidate>): list<Candidate>|null $afterRead given the number of the read, from 1, and its candidates; gives what the read returns
     */
    public function __construct(private readonly Candidates $candidates, private readonly ?\Closure $afterRead = null)
    {
    }

    public function withSymbol(string $firmId, string $variableSymbol): array
    {
        $found = $this->candidates->withSymbol($firmId, $variableSymbol);
        if ($this->afterRead instanceof \Closure) {
            $found = ($this->afterRead)(++$this->reads, $found);
        }
        $this->entries[] = 'read:'.implode(',', array_map(static fn (Candidate $candidate): string => $candidate->invoiceId, $found));

        return $found;
    }

    public function open(string $firmId, Currency $currency, string $text, array $ids, int $limit): array
    {
        return $this->candidates->open($firmId, $currency, $text, $ids, $limit);
    }

    public function refunds(string $firmId, ?string $variableSymbol): array
    {
        return $this->candidates->refunds($firmId, $variableSymbol);
    }

    public function paidFrom(string $firmId, ?string $counterIban, ?string $counterAccount): array
    {
        return $this->candidates->paidFrom($firmId, $counterIban, $counterAccount);
    }

    public function locked(string $invoiceId): void
    {
        $this->entries[] = 'lock:'.$invoiceId;
    }

    public function lockedRow(string $invoiceId): void
    {
        $this->entries[] = 'row:'.$invoiceId;
    }
}
