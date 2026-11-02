<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\BankMovement;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\UnmatchedReason;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\RecordPayment;
use App\Shared\Domain\Money;

/**
 * Which invoices an incoming movement pays, by the variable symbol and the amount.
 * It pairs only when the rules leave one answer; anything else stays for a person,
 * with the invoices that may fit as suggestions.
 *
 * The gate comes first: a message nothing trusted vouches for, a firm that pairs by
 * hand and a movement someone unpaired never pair by themselves. Then the symbol
 * picks the open invoices of the firm in the currency of the movement. One of them
 * takes the whole amount, whatever it leaves or overpays. Of several, the one whose
 * remaining amount is the amount takes it; failing that, an amount that is what
 * they all have left pays them all; failing that, a person decides.
 *
 * An invoice issued after the day the money came is never paired by itself, only
 * suggested: a payment that came first was for something else, or for an invoice
 * the firm had not yet written, and a person knows which.
 *
 * A small difference is settled only when the firm wants it, in the currencies
 * where it is a custom, on one invoice, and only up to what RecordPayment accepts.
 */
final readonly class Matcher
{
    public function __construct(private Candidates $candidates)
    {
    }

    /**
     * The open invoices of the movement's firm with its variable symbol, in every
     * currency, oldest due first. A movement without a symbol has none.
     *
     * @return list<Candidate>
     */
    public function candidates(BankMovement $movement): array
    {
        $symbol = self::normalised($movement->variableSymbol());

        return null === $symbol ? [] : $this->candidates->withSymbol($movement->firm()->id(), $symbol);
    }

    /**
     * Pure: the same movement, candidates and rules always give the same decision.
     *
     * @param list<Candidate> $candidates as candidates() gives them
     */
    public function decide(BankMovement $movement, array $candidates, PairingRules $rules): MatchDecision
    {
        $amount = $movement->amount();
        $suggestions = $this->ordered(array_values(array_filter(
            $candidates,
            static fn (Candidate $candidate): bool => $candidate->remaining->currency() === $amount->currency() && 0 < $candidate->remaining->minor(),
        )));

        $gate = $this->gate($movement, $rules);
        if ($gate instanceof UnmatchedReason) {
            return MatchDecision::unmatched($gate, $suggestions);
        }
        if (null === self::normalised($movement->variableSymbol())) {
            return MatchDecision::unmatched(UnmatchedReason::NoSymbol, []);
        }
        if ([] === $suggestions) {
            return MatchDecision::unmatched([] === $candidates ? UnmatchedReason::NoInvoice : UnmatchedReason::CurrencyMismatch, []);
        }

        $booked = $movement->bookedOn()->format('Y-m-d');
        $eligible = array_values(array_filter($suggestions, static fn (Candidate $candidate): bool => $candidate->issueDate->format('Y-m-d') <= $booked));
        if ([] === $eligible) {
            return MatchDecision::unmatched(UnmatchedReason::NoInvoice, $suggestions);
        }
        if (1 === \count($eligible)) {
            return MatchDecision::pair([$this->whole($eligible[0], $amount, $rules)]);
        }

        $equal = array_values(array_filter($eligible, static fn (Candidate $candidate): bool => $candidate->remaining->minor() === $amount->minor()));
        if (1 === \count($equal)) {
            return MatchDecision::pair([$this->whole($equal[0], $amount, $rules)]);
        }
        if ([] === $equal && $this->settles($amount, $rules)) {
            $close = array_values(array_filter($eligible, static fn (Candidate $candidate): bool => abs($candidate->remaining->minor() - $amount->minor()) <= RecordPayment::MAX_SETTLEMENT));
            if (1 === \count($close)) {
                return MatchDecision::pair([$this->whole($close[0], $amount, $rules)]);
            }
        }

        $sum = array_sum(array_map(static fn (Candidate $candidate): int => $candidate->remaining->minor(), $eligible));
        if ($sum === $amount->minor()) {
            return MatchDecision::pair(array_map(static fn (Candidate $candidate): AllocationRequest => new AllocationRequest($candidate->invoiceId, $candidate->remaining), $eligible));
        }

        return MatchDecision::unmatched(UnmatchedReason::Ambiguous, $suggestions);
    }

    /**
     * Digits without leading zeros, or null for a symbol that is none.
     */
    public static function normalised(?string $symbol): ?string
    {
        $digits = ltrim(preg_replace('/\D/', '', $symbol ?? '') ?? '', '0');

        return '' === $digits ? null : $digits;
    }

    private function gate(BankMovement $movement, PairingRules $rules): ?UnmatchedReason
    {
        return match (true) {
            MessageTrust::Failed === $movement->trust() => UnmatchedReason::Rejected,
            MessageTrust::Verified !== $movement->trust() => UnmatchedReason::Unverified,
            $movement->isAutoPairBlocked() => UnmatchedReason::Blocked,
            !$rules->autoPairing => UnmatchedReason::AutoPairingOff,
            default => null,
        };
    }

    /**
     * The whole movement on one invoice, and the small difference settled when allowed.
     */
    private function whole(Candidate $candidate, Money $amount, PairingRules $rules): AllocationRequest
    {
        $difference = $candidate->remaining->minor() - $amount->minor();
        if (0 !== $difference && abs($difference) <= RecordPayment::MAX_SETTLEMENT && $this->settles($amount, $rules)) {
            return new AllocationRequest($candidate->invoiceId, $amount, Money::ofMinor($difference, $amount->currency()));
        }

        return new AllocationRequest($candidate->invoiceId, $amount);
    }

    private function settles(Money $amount, PairingRules $rules): bool
    {
        return $rules->smallDifference && \in_array($amount->currency(), RecordPayment::SETTLEMENT_CURRENCIES, true);
    }

    /**
     * @param list<Candidate> $candidates
     *
     * @return list<Candidate> oldest due first, then by number
     */
    private function ordered(array $candidates): array
    {
        usort($candidates, static fn (Candidate $a, Candidate $b): int => [$a->dueDate->format('Y-m-d'), $a->number, $a->invoiceId] <=> [$b->dueDate->format('Y-m-d'), $b->number, $b->invoiceId]);

        return $candidates;
    }
}
