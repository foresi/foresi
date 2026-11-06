<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\BankMovement;
use App\Shared\Domain\Money;

/**
 * The invoices a person may pair a movement with, as iDoklad's Pair offers them:
 * first the ones it fits, those with its variable symbol, then those with what is
 * left to pay equal to its amount, then those of the customer who paid; then the
 * other unpaid invoices of the firm in its currency, or those a search finds. All
 * of them are oldest due first within their kind.
 *
 * What is filled in follows the matcher: the invoices with its symbol, or the one
 * invoice with its amount. One invoice takes the whole movement, whatever it
 * leaves or overpays; several take what each has left, oldest due first, and the
 * last one what remains.
 *
 * An outgoing movement is offered the invoices with something to refund the same
 * way: by its symbol, the invoice's own or its credit note's, then by what is to
 * refund equal to its amount, then the others.
 */
final readonly class PairingChoices
{
    /** How many other unpaid invoices are offered without a search. */
    public const int OTHERS = 10;
    /** How many invoices a search shows. */
    public const int FOUND = 25;
    public const string BY_SYMBOL = 'symbol';
    public const string BY_AMOUNT = 'amount';
    public const string BY_CUSTOMER = 'customer';

    public function __construct(private Matcher $matcher, private Candidates $candidates, private FirmPairingRules $rules)
    {
    }

    /**
     * @param string       $text what was searched for
     * @param list<string> $kept invoices ticked already, which stay offered
     */
    public function of(BankMovement $movement, string $text = '', array $kept = []): PairingOptions
    {
        $amount = $movement->amount();
        $firmId = $movement->firm()->id();
        $incoming = $movement->isIncoming();
        $open = $incoming
            ? $this->candidates->open($firmId, $amount->currency(), '', [], 200)
            : array_filter($this->candidates->refunds($firmId, null), static fn (Candidate $candidate): bool => $candidate->remaining->currency() === $amount->currency());

        $suggested = [];
        foreach ($this->matcher->candidates($movement) as $candidate) {
            if ($candidate->remaining->currency() === $amount->currency()) {
                $suggested[$candidate->invoiceId] = [$candidate, self::BY_SYMBOL];
            }
        }
        foreach ($open as $candidate) {
            if (!isset($suggested[$candidate->invoiceId]) && $candidate->remaining->minor() === $amount->minor()) {
                $suggested[$candidate->invoiceId] = [$candidate, self::BY_AMOUNT];
            }
        }
        foreach ($open as $candidate) {
            if (!isset($suggested[$candidate->invoiceId]) && $this->sameCustomer($movement->counterName(), $candidate->customerName)) {
                $suggested[$candidate->invoiceId] = [$candidate, self::BY_CUSTOMER];
            }
        }

        $text = trim($text);
        $others = !$incoming ? array_filter($open, static fn (Candidate $candidate): bool => '' === $text || str_contains(mb_strtolower($candidate->number.' '.$candidate->customerName), mb_strtolower($text))) : [
            ...('' === $text
                ? \array_slice(array_values(array_filter($open, static fn (Candidate $candidate): bool => !isset($suggested[$candidate->invoiceId]))), 0, self::OTHERS)
                : $this->candidates->open($firmId, $amount->currency(), $text, [], self::FOUND)),
            // What was ticked stays offered, whatever the search finds.
            ...([] === $kept ? [] : $this->candidates->open($firmId, $amount->currency(), '', $kept, \count($kept))),
        ];
        if (!$incoming) {
            $others = [...$others, ...array_filter($open, static fn (Candidate $candidate): bool => \in_array($candidate->invoiceId, $kept, true))];
        }
        $unique = [];
        foreach ($others as $candidate) {
            if (!isset($suggested[$candidate->invoiceId])) {
                $unique[$candidate->invoiceId] ??= $candidate;
            }
        }

        $rules = $this->rules->for($movement->firm());
        $limit = $this->matcher->settlementLimit($movement);

        return new PairingOptions(
            array_values(array_map(static fn (array $pair): PairingChoice => new PairingChoice($pair[0], $pair[1]), $suggested)),
            array_values(array_map(static fn (Candidate $candidate): PairingChoice => new PairingChoice($candidate, null), $unique)),
            $this->prefill($amount, array_values($suggested)),
            $rules->smallDifference ? $limit : null,
        );
    }

    /**
     * @param list<array{Candidate, string}> $suggested
     *
     * @return array<string, Money> by invoice id
     */
    private function prefill(Money $amount, array $suggested): array
    {
        $bySymbol = array_values(array_map(static fn (array $pair): Candidate => $pair[0], array_filter($suggested, static fn (array $pair): bool => self::BY_SYMBOL === $pair[1])));
        $byAmount = array_values(array_map(static fn (array $pair): Candidate => $pair[0], array_filter($suggested, static fn (array $pair): bool => self::BY_AMOUNT === $pair[1])));
        $chosen = [] !== $bySymbol ? $bySymbol : (1 === \count($byAmount) ? $byAmount : []);
        if ([] === $chosen) {
            return [];
        }
        if (1 === \count($chosen)) {
            return [$chosen[0]->invoiceId => $amount];
        }

        $left = $amount->minor();
        $prefill = [];
        foreach ($chosen as $index => $candidate) {
            $part = $index === array_key_last($chosen) ? $left : min($left, $candidate->remaining->minor());
            if ($part <= 0) {
                break;
            }
            $prefill[$candidate->invoiceId] = Money::ofMinor($part, $amount->currency());
            $left -= $part;
        }

        return $prefill;
    }

    private function sameCustomer(?string $counterName, string $customerName): bool
    {
        $counter = mb_strtolower(trim($counterName ?? ''));
        $customer = mb_strtolower(trim($customerName));
        if (mb_strlen($counter) < 3 || '' === $customer) {
            return false;
        }

        return str_contains($customer, $counter) || str_contains($counter, $customer);
    }
}
