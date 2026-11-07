<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\BankMovement;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\PaymentRefused;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\PaymentMethod;

/**
 * Pays invoices from an incoming movement: as a person split it, or by itself when
 * the matcher has one answer. The payment and the paired movement are stored
 * together or not at all.
 *
 * An outgoing movement records a refund on invoices with something to refund, as
 * a person chose them or by itself when the matcher has one answer; one on any
 * other invoice is refused as not incoming.
 *
 * The locks go in one order everywhere: the movement first, then its invoices by
 * ascending id, as RecordPayment takes them. The candidates and the decision are
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
        private RecordPayment $record,
        private InvoiceStore $store,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private InvoicePayments $payments,
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
     * @param non-empty-list<AllocationRequest> $allocations
     * @param bool                              $checkedInBank whether the person confirmed they checked the payment in the bank
     *
     * @throws PairingRefused      nothing is stored
     * @throws PaymentRefused      nothing is stored; the parts do not add up to the movement, say
     * @throws UnknownBankMovement also for a movement of another tenant
     * @throws UnknownInvoice      also for an invoice of another tenant
     */
    public function pair(string $movementId, array $allocations, bool $checkedInBank = false): Payment
    {
        try {
            return $this->retry->run(fn (): Payment => $this->work->run(function () use ($movementId, $allocations, $checkedInBank): Payment {
                $movement = $this->movements->lock($movementId);
                $this->refuseUnpairable($movement, $checkedInBank);
                $incoming = $movement->isIncoming();
                foreach ($this->lockInvoices(array_map(static fn (AllocationRequest $allocation): string => $allocation->invoiceId, $allocations)) as $invoiceId) {
                    $invoice = $this->store->get($invoiceId);
                    // Read under its lock: an invoice paid meanwhile takes no more, and one refunded meanwhile gives no more back.
                    if ($incoming && $this->payments->remainingOf($invoice)->minor() <= 0) {
                        throw new PairingRefused(PairingRefused::INVOICE_SETTLED);
                    }
                    if (!$incoming && $this->payments->toRefundOf($invoice)->minor() <= 0) {
                        throw new PairingRefused(PairingRefused::NOT_INCOMING);
                    }
                }

                return $this->paid($movementId, $allocations);
            }));
        } catch (PairingRefused|PaymentRefused|UnknownBankMovement|UnknownInvoice $refused) {
            throw $refused;
        } catch (\Throwable $failure) {
            // One live payment per movement is a unique index: losing to it means paid already.
            throw $this->movements->hasLivePayment($movementId) ? new PairingRefused(PairingRefused::ALREADY_PAIRED) : $failure;
        }
    }

    /**
     * Pairs an unmatched movement, incoming or outgoing, when the rules leave one answer, and
     * otherwise stores why it stays unmatched. Anything else is left as it is, so a
     * second call, or a call after a person paired it, does nothing.
     *
     * @return Payment|null the payment, or null when it was not paired now
     *
     * @throws UnknownBankMovement also for a movement of another tenant
     */
    public function auto(string $movementId): ?Payment
    {
        try {
            return $this->retry->run(fn (): ?Payment => $this->work->run(fn (): ?Payment => $this->autoUnderLocks($movementId)));
        } catch (UnknownBankMovement $unknown) {
            throw $unknown;
        } catch (PaymentRefused $refused) {
            $this->stayUnmatchedAfter($movementId, $refused);

            return null;
        } catch (\Throwable $failure) {
            // One live payment per movement is a unique index: losing to it means paid already.
            if ($this->movements->hasLivePayment($movementId)) {
                return null;
            }

            throw $failure;
        }
    }

    /**
     * The decision that pays, and any settlement in it, is made from amounts read
     * under the locks of the invoices, the same locks RecordPayment then takes in this
     * transaction: never from what was read before them.
     *
     * An invoice issued meanwhile may join the candidates after the first locks, so
     * the candidates are read again under the locks of all invoices read so far until
     * no read brings one that was not locked before it. Should they keep changing, a
     * person decides.
     */
    private function autoUnderLocks(string $movementId): ?Payment
    {
        $movement = $this->movements->lock($movementId);
        if (MovementState::Unmatched !== $movement->state() || $this->movements->hasLivePayment($movementId)) {
            return null;
        }
        $candidates = $this->matcher->candidates($movement);
        $decision = $this->matcher->decide($movement, $candidates, $this->rules->for($movement->firm()), $this->matcher->settlementLimit($movement));
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
            $locked = $this->lockInvoices([...$locked, ...$read]);
            $movement = $this->movements->get($movementId);
            $candidates = $this->matcher->candidates($movement);
            $decision = $this->matcher->decide($movement, $candidates, $this->rules->for($movement->firm()), $this->matcher->settlementLimit($movement));
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
    private function stayUnmatchedAfter(string $movementId, PaymentRefused $refused): void
    {
        $reason = match ($refused->reason) {
            PaymentRefused::CURRENCY_MISMATCH => UnmatchedReason::CurrencyMismatch,
            PaymentRefused::TRASHED => UnmatchedReason::NoInvoice,
            default => UnmatchedReason::Refused,
        };
        $this->retry->run(fn (): mixed => $this->work->run(function () use ($movementId, $reason): null {
            $movement = $this->movements->lock($movementId);
            if (MovementState::Unmatched === $movement->state() && !$this->movements->hasLivePayment($movementId)) {
                $movement->stayUnmatched($reason);
            }

            return null;
        }));
    }

    /**
     * @param list<AllocationRequest> $allocations
     */
    private function paid(string $movementId, array $allocations): Payment
    {
        $movement = $this->movements->get($movementId);
        $payment = $this->record->record(new PaymentRequest(
            $movement->firm()->id(),
            $movement->bookedOn(),
            $movement->amount(),
            PaymentMethod::BankTransfer,
            PaymentSource::BankMovement,
            $movement->id(),
            '',
            $allocations,
            $movement->isIncoming() ? PaymentDirection::Incoming : PaymentDirection::Refund,
        ));
        // Recording started the session over: the movement is read again to be changed.
        $this->movements->get($movementId)->pairWith($payment->id());

        return $payment;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> the ids locked, ascending
     */
    private function lockInvoices(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, \SORT_STRING);
        foreach ($ids as $id) {
            $this->store->lock($id);
        }

        return $ids;
    }

    private function refuseUnpairable(BankMovement $movement, bool $checkedInBank): void
    {
        // An outgoing movement refunds only: one on an invoice with nothing to refund is refused under its lock.
        $refusal = match (true) {
            MovementState::Paired === $movement->state(), $this->movements->hasLivePayment($movement->id()) => PairingRefused::ALREADY_PAIRED,
            MovementState::PossibleDuplicate === $movement->state() => PairingRefused::POSSIBLE_DUPLICATE,
            MovementState::OwnTransfer === $movement->state() => PairingRefused::OWN_TRANSFER,
            // A failed message is never paid, whatever reason the movement stands for now; recorded anyway, it is unverified.
            UnmatchedReason::Rejected === $movement->unmatchedReason(), MessageTrust::Failed === $movement->trust() => PairingRefused::REJECTED,
            MessageTrust::Verified !== $movement->trust() && !$checkedInBank => PairingRefused::NOT_CHECKED_IN_BANK,
            default => null,
        };
        if (null !== $refusal) {
            throw new PairingRefused($refusal);
        }
    }
}
