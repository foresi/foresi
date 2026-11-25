<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Contract\Event\VatPeriodChanged;
use Foresi\Vat\Domain\PaymentAssignment;
use Foresi\Vat\Domain\PeriodEntry;
use Psr\Clock\ClockInterface;

/**
 * What people decide about the VAT periods: the amount of the return they
 * filed, the mark that a period is settled, and which period a bank
 * movement pays. Each change records vat.period.changed. A period must
 * exist to be written; an entry left with nothing in it is removed.
 */
final readonly class PeriodWrites
{
    public function __construct(
        private VatAccess $access,
        private VatStore $store,
        private VatPeriods $periods,
        private AuditActor $actor,
        private ClockInterface $clock,
        private TransactionalWork $transaction,
        private EventRecorder $events,
    ) {
    }

    /**
     * @throws VatRefused forbidden, not_found, or invalid with key_invalid or amount_invalid
     */
    public function setReturnAmount(SetReturnAmountInput $input): PeriodDetail
    {
        $key = $this->existing($input->key);
        if (1 !== preg_match('/^-?[0-9]{1,13}(\.[0-9]{1,2})?\z/', trim($input->amount))) {
            throw VatRefused::invalid('amount_invalid', 'amount', 'An amount is a decimal number in CZK with at most two decimals, such as "1500.00", negative for a refund.');
        }
        $amount = Money::of(trim($input->amount), Currency::CZK);
        $this->transaction->run(function () use ($key, $amount): void {
            $this->entry($key)->enterReturnAmount($amount);
            $this->events->record(new VatPeriodChanged(VatPeriodChanged::RETURN_AMOUNT, $key));
        });

        return $this->periods->get($key);
    }

    /**
     * @throws VatRefused forbidden, not_found, or invalid with key_invalid
     */
    public function clearReturnAmount(ClearReturnAmountInput $input): PeriodDetail
    {
        $key = $this->existing($input->key);
        $entry = $this->store->entry($key);
        if (!$entry?->returnAmount() instanceof Money) {
            throw VatRefused::notFound(\sprintf('The VAT period %s has no return amount.', $key));
        }
        $this->transaction->run(function () use ($entry, $key): void {
            $entry->enterReturnAmount(null);
            $this->removeWhenEmpty($entry);
            $this->events->record(new VatPeriodChanged(VatPeriodChanged::RETURN_AMOUNT, $key));
        });

        return $this->periods->get($key);
    }

    /**
     * @throws VatRefused forbidden, not_found, or invalid with key_invalid or note_too_long
     */
    public function settle(SettlePeriodInput $input): PeriodDetail
    {
        $key = $this->existing($input->key);
        $note = trim($input->note);
        if (mb_strlen($note) > PeriodEntry::MAX_NOTE) {
            throw VatRefused::invalid('note_too_long', 'note', \sprintf('A note has at most %d characters.', PeriodEntry::MAX_NOTE));
        }
        $by = (string) $this->actor->userId();
        $this->transaction->run(function () use ($key, $note, $by): void {
            $this->entry($key)->settle(\DateTimeImmutable::createFromInterface($this->clock->now()), $by, '' === $note ? null : $note);
            $this->events->record(new VatPeriodChanged(VatPeriodChanged::SETTLED, $key));
        });

        return $this->periods->get($key);
    }

    /**
     * @throws VatRefused forbidden, not_found, or invalid with key_invalid
     */
    public function reopen(ReopenPeriodInput $input): PeriodDetail
    {
        $key = $this->existing($input->key);
        $entry = $this->store->entry($key);
        if (!$entry?->settledAt() instanceof \DateTimeImmutable) {
            throw VatRefused::notFound(\sprintf('The VAT period %s is not marked settled.', $key));
        }
        $this->transaction->run(function () use ($entry, $key): void {
            $entry->reopen();
            $this->removeWhenEmpty($entry);
            $this->events->record(new VatPeriodChanged(VatPeriodChanged::REOPENED, $key));
        });

        return $this->periods->get($key);
    }

    /**
     * @throws VatRefused forbidden, not_found, or invalid with movement_id_invalid or key_invalid
     */
    public function assign(AssignPaymentInput $input): PaymentAssignmentView
    {
        $movement = $this->movement($input->movementId);
        $key = null === $input->period ? null : $this->existing($input->period, 'period');
        $by = (string) $this->actor->userId();
        $this->transaction->run(function () use ($movement, $key, $by): void {
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $assignment = $this->store->assignment($movement);
            if ($assignment instanceof PaymentAssignment) {
                $assignment->reassign($key, $now, $by);
            } else {
                $this->store->addAssignment(new PaymentAssignment($movement, $key, $now, $by));
            }
            $this->events->record(new VatPeriodChanged(VatPeriodChanged::PAYMENT_ASSIGNED, $key, $movement));
        });

        return new PaymentAssignmentView($movement, $key, false);
    }

    /**
     * @throws VatRefused forbidden, not_found, or invalid with movement_id_invalid
     */
    public function reset(ResetPaymentInput $input): PaymentAssignmentView
    {
        $movement = $this->movement($input->movementId);
        $assignment = $this->store->assignment($movement);
        if (!$assignment instanceof PaymentAssignment) {
            throw VatRefused::notFound(\sprintf('Nobody placed the bank movement %s.', $movement));
        }
        $this->transaction->run(function () use ($assignment, $movement): void {
            $this->store->removeAssignment($assignment);
            $this->events->record(new VatPeriodChanged(VatPeriodChanged::PAYMENT_AUTOMATIC, null, $movement));
        });

        return new PaymentAssignmentView($movement, null, true);
    }

    /**
     * The key of a period the firm has, as the module writes it.
     *
     * @throws VatRefused forbidden, not_found, or invalid with key_invalid
     */
    private function existing(string $key, string $field = 'key'): string
    {
        try {
            return $this->periods->get($key)->period->period->key;
        } catch (VatRefused $refused) {
            if ('key_invalid' === $refused->reason()) {
                throw VatRefused::invalid('key_invalid', $field, 'A period is a month such as "2026-11" or a quarter such as "2026-Q4".');
            }

            throw $refused;
        }
    }

    /**
     * @throws VatRefused forbidden, or invalid with movement_id_invalid
     */
    private function movement(string $id): string
    {
        $this->access->denyUnlessStaff();
        $id = trim($id);
        if ('' === $id || \strlen($id) > PaymentAssignment::MAX_MOVEMENT_ID) {
            throw VatRefused::invalid('movement_id_invalid', 'movementId', \sprintf('A movement id has 1 to %d characters.', PaymentAssignment::MAX_MOVEMENT_ID));
        }

        return $id;
    }

    private function entry(string $key): PeriodEntry
    {
        $entry = $this->store->entry($key);
        if (!$entry instanceof PeriodEntry) {
            $entry = new PeriodEntry($key);
            $this->store->addEntry($entry);
        }

        return $entry;
    }

    private function removeWhenEmpty(PeriodEntry $entry): void
    {
        if ($entry->isEmpty()) {
            $this->store->removeEntry($entry);
        }
    }
}
