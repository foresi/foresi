<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Integration\CashFlow;

use Foresi\Banking\Application\MovementLedger;
use Foresi\CashFlow\Contract\AccountBalance;
use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Contract\BalanceCheckpoint;
use Foresi\CashFlow\Contract\BalanceSource;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\Shared\Domain\Money;

/**
 * The bank in the forecast is the past and the starting point (C16). The
 * balance of an account is its latest checkpoint and the movements booked
 * after its day; an account without any movement is not known here, and the
 * checkpoint stands alone. The movements are also the actuals a planned item's
 * match rule pairs with, so a planned payment already made leaves the forecast.
 * A movement never becomes a cost or an income on its own.
 */
final readonly class MovementFlows implements BalanceSource, ActualFlowSource
{
    public const string KEY = 'banking.movements';

    public function __construct(private MovementLedger $ledger)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function balance(BalanceCheckpoint $checkpoint, \DateTimeImmutable $today): ?AccountBalance
    {
        $booked = $this->ledger->bookedAfter($checkpoint->accountId, $checkpoint->on, $today);
        if (!$booked['known']) {
            return null;
        }
        $amount = $checkpoint->amount->plus(Money::ofMinor($booked['sumMinor'], $checkpoint->amount->currency()));
        $asOf = $booked['lastOn'] instanceof \DateTimeImmutable && $booked['lastOn']->format('Y-m-d') > $checkpoint->on->format('Y-m-d')
            ? new \DateTimeImmutable($booked['lastOn']->format('Y-m-d'), $today->getTimezone())
            : $checkpoint->on;

        return new AccountBalance($checkpoint->accountId, $amount, $asOf);
    }

    public function actuals(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        foreach ($this->ledger->bookedBetween($from, $to) as $movement) {
            yield new ActualFlow(
                self::KEY,
                new FlowSubject('bank_movement', $movement->id()),
                $movement->isIncoming() ? FlowDirection::In : FlowDirection::Out,
                $movement->amount(),
                new \DateTimeImmutable($movement->bookedOn()->format('Y-m-d'), $from->getTimezone()),
                counterpartyAccount: $movement->counterAccount() ?? $movement->counterIban(),
                variableSymbol: $movement->variableSymbol(),
            );
        }
    }
}
