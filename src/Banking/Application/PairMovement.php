<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Shared\Application\ConflictRetry;
use Foresi\Shared\Application\TransactionalWork;

/**
 * Pays invoices from an incoming movement: as a person split it, or by itself when
 * the matcher has one answer. The payment and the paired movement are stored
 * together or not at all. The payment itself is recorded by the PaymentPairing;
 * without one every movement stays unmatched.
 *
 * An outgoing movement records a refund on invoices with something to refund, as
 * a person chose them or by itself when the matcher has one answer; one on any
 * other invoice is refused as not incoming.
 *
 * The locks go in one order everywhere: the movement first, then its invoices by
 * ascending id, as recording the payment takes them. The candidates and the decision are
 * worked out again under the locks, so of two movements for one invoice the second
 * sees what the first paid, and of a person and the matcher on one movement only
 * the first pairs it.
 */
final readonly class PairMovement
{
    /**
     * How many times auto() locks a wider set of candidates before it leaves the
     * movement to a person.
     */
    private const int LOCK_ROUNDS = 3;

    public function __construct(
        private BankMovements $movements,
        private Matcher $matcher,
        private FirmPairingRules $rules,
        private PaymentPairing $payments,
        private TransactionalWork $work,
        private ConflictRetry $retry,
    ) {
    }

    /**
     * Pays the invoices a person chose. The parts must add up to the movement.
     *
     * A movement the bank did not vouch for, from an unverified message or one
     * recorded anyway, may be forged: it pairs only when the person confirms they
     * found the payment in their internet banking. The rule holds here, for every
     * caller, not only in the dialog that asks.
     *
     * @param non-empty-list<Allocation> $allocations
     * @param bool                       $checkedInBank whether the person confirmed they checked the payment in the bank
     *
     * @return string the payment's id
     *
     * @throws PairingRefused      nothing is stored; the parts do not add up to the movement, say, or an invoice is unknown
     * @throws UnknownBankMovement for an unknown movement
     */
    public function pair(string $movementId, array $allocations, bool $checkedInBank = false): string
    {
        try {
            return $this->retry->run(fn (): string => $this->work->run(function () use ($movementId, $allocations, $checkedInBank): string {
                $movement = $this->movements->lock($movementId);
                $this->refuseUnpairable($movement, $checkedInBank);
                $incoming = $movement->isIncoming();
                foreach ($this->lockDocuments(array_map(static fn (Allocation $allocation): string => $allocation->documentId, $allocations)) as $documentId) {
                    // Read under its lock: an invoice paid meanwhile takes no more, and one refunded meanwhile gives no more back.
                    if ($this->payments->isSettled($documentId, $incoming)) {
                        throw new PairingRefused($incoming ? PairingRefused::INVOICE_SETTLED : PairingRefused::NOT_INCOMING);
                    }
                }

                return $this->paid($movementId, $allocations);
            }));
        } catch (PairingRefused|UnknownBankMovement $refused) {
            throw $refused;
        } catch (\Throwable $failure) {
            // One live payment per movement is a unique index: losing to it means paid already.
            throw $this->payments->isLive($movementId) ? new PairingRefused(PairingRefused::ALREADY_PAIRED) : $failure;
        }
    }

    /**
     * Pairs an unmatched movement, incoming or outgoing, when the rules leave one answer, and
     * otherwise stores why it stays unmatched. Anything else is left as it is, so a
     * second call, or a call after a person paired it, does nothing.
     *
     * @return string|null the payment's id, or null when it was not paired now
     *
     * @throws UnknownBankMovement for an unknown movement
     */
    public function auto(string $movementId): ?string
    {
        try {
            return $this->retry->run(fn (): ?string => $this->work->run(fn (): ?string => $this->autoUnderLocks($movementId)));
        } catch (UnknownBankMovement $unknown) {
            throw $unknown;
        } catch (PairingRefused $refused) {
            $this->stayUnmatchedAfter($movementId, $refused);

            return null;
        } catch (\Throwable $failure) {
            // One live payment per movement is a unique index: losing to it means paid already.
            if ($this->payments->isLive($movementId)) {
                return null;
            }

            throw $failure;
        }
    }

    /**
     * The decision that pays, and any settlement in it, is made from amounts read
     * under the locks of the invoices, the same locks recording the payment then takes in this
     * transaction: never from what was read before them.
     *
     * An invoice issued meanwhile may join the candidates after the first locks, so
     * the candidates are read again under the locks of all invoices read so far until
     * no read brings one that was not locked before it. Should they keep changing, a
     * person decides.
     */
    private function autoUnderLocks(string $movementId): ?string
    {
        $movement = $this->movements->lock($movementId);
        if (MovementState::Unmatched !== $movement->state() || $this->payments->isLive($movementId)) {
            return null;
        }
        $candidates = $this->matcher->candidates($movement);
        $decision = $this->matcher->decide($movement, $candidates, $this->rules->get(), $this->matcher->settlementLimit($movement));
        $locked = [];
        for ($round = 0; $decision->pairs(); ++$round) {
            $read = array_map(static fn (Candidate $candidate): string => $candidate->invoiceId, $candidates);
            if ([] === array_diff($read, $locked)) {
                break;
            }
            if (self::LOCK_ROUNDS === $round) {
                $decision = MatchDecision::unmatched(UnmatchedReason::Ambiguous, $candidates);

                break;
            }
            $locked = $this->lockDocuments([...$locked, ...$read]);
            $movement = $this->movements->get($movementId);
            $candidates = $this->matcher->candidates($movement);
            $decision = $this->matcher->decide($movement, $candidates, $this->rules->get(), $this->matcher->settlementLimit($movement));
        }
        if (!$decision->pairs()) {
            $movement->stayUnmatched($decision->reason ?? UnmatchedReason::NoInvoice);

            return null;
        }

        return $this->paid($movementId, $decision->allocations);
    }

    /**
     * The invoice refused what the matcher chose, so nothing was stored: in a unit of
     * its own the movement stays unmatched, with the reason in words a person acts on.
     * Should a person or another notice have paired it meanwhile, that stands.
     */
    private function stayUnmatchedAfter(string $movementId, PairingRefused $refused): void
    {
        $reason = match ($refused->reason) {
            PairingRefused::CURRENCY_MISMATCH => UnmatchedReason::CurrencyMismatch,
            PairingRefused::DOCUMENT_TRASHED, PairingRefused::UNKNOWN_DOCUMENT => UnmatchedReason::NoInvoice,
            PairingRefused::NO_PAIRING => UnmatchedReason::NoPairing,
            default => UnmatchedReason::Refused,
        };
        $this->retry->run(fn (): mixed => $this->work->run(function () use ($movementId, $reason): null {
            $movement = $this->movements->lock($movementId);
            if (MovementState::Unmatched === $movement->state() && !$this->payments->isLive($movementId)) {
                $movement->stayUnmatched($reason);
            }

            return null;
        }));
    }

    /**
     * @param list<Allocation> $allocations
     */
    private function paid(string $movementId, array $allocations): string
    {
        $paymentId = $this->payments->pay($this->movements->get($movementId), $allocations);
        // Recording started the session over: the movement is read again to be changed.
        $this->movements->get($movementId)->pairWith($paymentId);

        return $paymentId;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> the ids locked, ascending
     */
    private function lockDocuments(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, \SORT_STRING);
        $this->payments->lock($ids);

        return $ids;
    }

    private function refuseUnpairable(BankMovement $movement, bool $checkedInBank): void
    {
        // An outgoing movement refunds only: one on an invoice with nothing to refund is refused under its lock.
        $refusal = match (true) {
            !$this->payments->isAvailable() => PairingRefused::NO_PAIRING,
            MovementState::Paired === $movement->state(), $this->payments->isLive($movement->id()) => PairingRefused::ALREADY_PAIRED,
            MovementState::PossibleDuplicate === $movement->state() => PairingRefused::POSSIBLE_DUPLICATE,
            MovementState::OwnTransfer === $movement->state() => PairingRefused::OWN_TRANSFER,
            // A failed message is never paid, whatever reason the movement stands for now; recorded anyway, it is unverified.
            UnmatchedReason::Rejected === $movement->unmatchedReason(), MovementTrust::Failed === $movement->trust() => PairingRefused::REJECTED,
            MovementTrust::Verified !== $movement->trust() && !$checkedInBank => PairingRefused::NOT_CHECKED_IN_BANK,
            default => null,
        };
        if (null !== $refusal) {
            throw new PairingRefused($refusal);
        }
    }
}
