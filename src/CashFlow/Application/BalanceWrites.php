<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Domain\Checkpoint;
use App\CashFlow\Domain\ExpectedDate;
use App\CashFlow\Domain\MinimumBalance;
use App\Party\Application\TransactionalWork;
use App\Shared\Domain\Currency;
use App\Tenancy\Application\TenantClock;
use Psr\Clock\ClockInterface;

/**
 * The balance side of the cash flow over the API and MCP (ADR 0032): bank
 * balance checkpoints, the expected day of a document and the minimum balance
 * of a firm. Only the owners and members of the organisation write.
 */
final readonly class BalanceWrites
{
    public function __construct(
        private CashFlowAccess $access,
        private CashFlowStore $store,
        private TransactionalWork $transaction,
        private TenantClock $tenantClock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CashFlowRefused
     */
    public function checkpoint(RecordCheckpointInput $input): Checkpoint
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): Checkpoint {
            $account = $this->access->account($input->bankAccountId);
            $on = PlannedItemRules::day($input->on, $this->tenantClock->timezone(), 'on');
            if ($on > $this->tenantClock->today()) {
                throw CashFlowRefused::invalid('date_in_future', 'on', 'A balance is recorded for today or a day before; the forecast projects the days after it.');
            }
            $amount = PlannedItemRules::money($input->amount, $account->currency(), 'amount');
            $checkpoint = $this->store->checkpoint($account, $on);
            if ($checkpoint instanceof Checkpoint) {
                $checkpoint->correct($amount, $this->clock->now());
            } else {
                $checkpoint = new Checkpoint($account, $on, $amount, $this->clock->now());
                $this->store->add($checkpoint);
            }

            return $checkpoint;
        });
    }

    /**
     * @throws CashFlowRefused
     */
    public function expect(SetExpectedDateInput $input): ExpectedDate
    {
        $this->access->denyUnlessStaff();
        [$type, $id] = $this->subject($input->subjectType, $input->subjectId);
        $on = PlannedItemRules::day($input->expectedOn, $this->tenantClock->timezone(), 'expectedOn');

        return $this->transaction->run(function () use ($type, $id, $on): ExpectedDate {
            $expected = $this->store->expectedDate($type, $id);
            if ($expected instanceof ExpectedDate) {
                $expected->moveTo($on);
            } else {
                $expected = new ExpectedDate($type, $id, $on);
                $this->store->add($expected);
            }

            return $expected;
        });
    }

    /**
     * @return array{string, string} the subject's type and id, as they were cleared
     *
     * @throws CashFlowRefused
     */
    public function clearExpected(ClearExpectedDateInput $input): array
    {
        $this->access->denyUnlessStaff();
        [$type, $id] = $this->subject($input->subjectType, $input->subjectId);
        $this->transaction->run(function () use ($type, $id): void {
            $expected = $this->store->expectedDate($type, $id);
            if ($expected instanceof ExpectedDate) {
                $this->store->remove($expected);
            }
        });

        return [$type, $id];
    }

    /**
     * @throws CashFlowRefused
     */
    public function minimum(SetMinimumBalanceInput $input): ?MinimumBalance
    {
        $this->access->denyUnlessStaff();
        $amount = null === $input->amount ? null : PlannedItemRules::money($input->amount, Currency::CZK, 'amount');

        return $this->transaction->run(function () use ($input, $amount): ?MinimumBalance {
            $firm = $this->access->firm($input->firmId);
            $minimum = $this->store->minimumBalance($firm->id());
            if (!$amount instanceof \App\Shared\Domain\Money) {
                if ($minimum instanceof MinimumBalance) {
                    $this->store->remove($minimum);
                }

                return null;
            }
            if ($minimum instanceof MinimumBalance) {
                $minimum->change($amount);
            } else {
                $minimum = new MinimumBalance($firm, $amount);
                $this->store->add($minimum);
            }

            return $minimum;
        });
    }

    /**
     * @return array{string, string}
     *
     * @throws CashFlowRefused
     */
    private function subject(string $type, string $id): array
    {
        $type = trim($type);
        $id = trim($id);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}\z/', $type)) {
            throw CashFlowRefused::invalid('subject_type_invalid', 'subjectType', 'A subject type is lower snake case, such as "invoice", as a forecast item names it.');
        }
        if ('' === $id || \strlen($id) > ExpectedDate::MAX_SUBJECT_ID) {
            throw CashFlowRefused::invalid('subject_id_invalid', 'subjectId', \sprintf('A subject id has 1 to %d characters.', ExpectedDate::MAX_SUBJECT_ID));
        }

        return [$type, $id];
    }
}
