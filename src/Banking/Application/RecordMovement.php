<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\Event\BankMovementRecorded;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\UnknownFirmBankAccount;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\InvalidIban;
use Foresi\Shared\Application\ConflictRetry;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Money;

/**
 * Records a movement on an account of the firm and decides where it stands. It pays
 * nothing: PairMovement::auto() does that, in the same transaction when the caller
 * wants both, so the reason an incoming movement is recorded with is what the
 * matcher says now, and auto() settles it under the locks.
 *
 * Outgoing money goes the same way, to a refund: the same movement told again by
 * its source in another message or statement is a possible duplicate, and so is
 * one that two movements another source recorded could be (StatementIntake
 * corroborates one when it is the only one), and money to or from another account of the
 * firm is an own transfer; neither ever pays or refunds an invoice. Outgoing
 * money that no invoice with something to refund fits, by its symbol, amount,
 * account or customer, pays a supplier or a salary: it is recorded only, and waits
 * for no one. A person can still pair it with a refund that came later. Movements of one account are recorded
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
        private PairingChoices $choices,
        private EventRecorder $events,
        private InstanceFirm $firm,
    ) {
    }

    /**
     * @throws UnknownFirmBankAccount for an unknown account
     */
    public function record(MovementInput $input, TrustVerdict $verdict): BankMovement
    {
        return $this->retry->run(fn (): BankMovement => $this->work->run(function () use ($input, $verdict): BankMovement {
            $this->movements->lockAccount($input->accountId);
            $account = $this->accounts->get($input->accountId);
            $state = $this->stateOf($input);
            $movement = $this->movement($account, $input, $verdict, $state, MovementState::Unmatched === $state ? UnmatchedReason::NoInvoice : null);
            if (MovementState::Unmatched === $state) {
                $decision = $this->matcher->decide($movement, $this->matcher->candidates($movement), $this->rules->get(), $this->matcher->settlementLimit($movement));
                if ($decision->reason instanceof UnmatchedReason) {
                    $movement->stayUnmatched($decision->reason);
                }
                if (!$movement->isIncoming() && !$decision->pairs() && [] === $this->choices->of($movement)->suggested) {
                    $movement = $this->movement($account, $input, $verdict, MovementState::Recorded, null);
                }
            }
            $this->movements->add($movement);
            $this->events->record(new BankMovementRecorded(
                $movement->id(),
                $this->firm->get()->id(),
                $account->id(),
                $movement->direction()->value,
                $this->decimal($movement->amount()),
                $movement->amount()->currency()->code(),
                $movement->bookedOn()->format('Y-m-d'),
                $movement->state()->value,
                $movement->variableSymbol(),
            ));

            return $movement;
        }));
    }

    private function stateOf(MovementInput $input): MovementState
    {
        if ($this->movements->fingerprintTakenByOther($input->fingerprint(), $input->sourceId, $input->inboundMessageId, $input->statementId)
            || \count($this->movements->corroborationCandidates($input)) > 1) {
            return MovementState::PossibleDuplicate;
        }
        if ($this->isOwnAccount($input->counterIban)) {
            return MovementState::OwnTransfer;
        }

        return MovementState::Unmatched;
    }

    private function isOwnAccount(?string $iban): bool
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
        return $this->accounts->ofIban($normalised) instanceof FirmBankAccount;
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
            $input->channel,
            $input->sourceId,
            $input->statementId,
        );
    }

    /**
     * A positive amount as a decimal string, as events carry amounts.
     */
    private function decimal(Money $money): string
    {
        $places = $money->currency()->minorUnits();
        if (0 === $places) {
            return (string) $money->minor();
        }
        $unit = 10 ** $places;

        return \sprintf('%d.%0'.$places.'d', intdiv($money->minor(), $unit), $money->minor() % $unit);
    }
}
