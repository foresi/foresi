<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\BankMovement;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Invoicing\Application\ConflictRetry;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownFirmBankAccount;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;
use App\Party\Domain\LegalEntity;

/**
 * Records a movement on an account of a firm and decides where it stands. It pays
 * nothing: PairMovement::auto() does that, in the same transaction when the caller
 * wants both, so the reason an incoming movement is recorded with is what the
 * matcher says now, and auto() settles it under the locks.
 *
 * Outgoing money goes the same way, to a refund: the same movement from another
 * message is a possible duplicate, and money to or from another account of the
 * same firm is an own transfer; neither ever pays or refunds an invoice. Movements of one account are recorded
 * one after the other, so the second of two equal ones sees the first.
 */
final readonly class RecordMovement
{
    public function __construct(
        private BankMovements $movements,
        private FirmBankAccounts $accounts,
        private Matcher $matcher,
        private FirmPairingRules $rules,
        private TransactionalWork $work,
        private ConflictRetry $retry,
    ) {
    }

    /**
     * @throws UnknownFirmBankAccount also for an account of another tenant
     */
    public function record(MovementInput $input, TrustVerdict $verdict): BankMovement
    {
        return $this->retry->run(fn (): BankMovement => $this->work->run(function () use ($input, $verdict): BankMovement {
            $this->movements->lockAccount($input->accountId);
            $account = $this->accounts->get($input->accountId);
            $state = $this->stateOf($input, $account->firm());
            $movement = $this->movement($account, $input, $verdict, $state, MovementState::Unmatched === $state ? UnmatchedReason::NoInvoice : null);
            if (MovementState::Unmatched === $state) {
                $decision = $this->matcher->decide($movement, $this->matcher->candidates($movement), $this->rules->for($account->firm()), $this->matcher->settlementLimit($movement));
                if ($decision->reason instanceof UnmatchedReason) {
                    $movement->stayUnmatched($decision->reason);
                }
            }
            $this->movements->add($movement);

            return $movement;
        }));
    }

    private function stateOf(MovementInput $input, LegalEntity $firm): MovementState
    {
        if ($this->movements->fingerprintTakenByOther($input->fingerprint(), $input->inboundMessageId)) {
            return MovementState::PossibleDuplicate;
        }
        if ($this->isOwnAccount($firm, $input->counterIban)) {
            return MovementState::OwnTransfer;
        }

        return MovementState::Unmatched;
    }

    private function isOwnAccount(LegalEntity $firm, ?string $iban): bool
    {
        if (null === $iban) {
            return false;
        }
        try {
            $normalised = Iban::fromString($iban)->toString();
        } catch (InvalidIban) {
            return false;
        }

        // Archived accounts too: the firm still owns them, and money from them is its own.
        return $this->accounts->ofIban($firm, $normalised) instanceof FirmBankAccount;
    }

    private function movement(FirmBankAccount $account, MovementInput $input, TrustVerdict $verdict, MovementState $state, ?UnmatchedReason $reason): BankMovement
    {
        return new BankMovement(
            $account,
            $input->inboundMessageId,
            $input->direction,
            $input->amount,
            $input->bookedOn,
            $input->counterAccount,
            $input->counterIban,
            $input->counterName,
            $input->variableSymbol,
            $input->constantSymbol,
            $input->specificSymbol,
            $input->message,
            $input->bankReference,
            $input->fingerprint(),
            $verdict->trust,
            $verdict->basis,
            $state,
            $reason,
        );
    }
}
