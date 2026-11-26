<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that knows what happened on a bank account after its latest
 * checkpoint, such as Banking with its movements (ADR 0033). Without any, the
 * balance of an account is its latest checkpoint. The first source that
 * answers for an account wins; one that throws is listed as unavailable.
 */
#[AutoconfigureTag(self::TAG)]
interface BalanceSource
{
    public const string TAG = 'foresi.cash_flow.balance_source';

    public function key(): string;

    /**
     * The balance of the checkpoint's account at the end of $today: the
     * checkpoint plus what was booked after it. Null when the source does not
     * know the account.
     */
    public function balance(BalanceCheckpoint $checkpoint, \DateTimeImmutable $today): ?AccountBalance;
}
