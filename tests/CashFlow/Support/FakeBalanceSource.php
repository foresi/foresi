<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\CashFlow\Support;

use App\CashFlow\Contract\AccountBalance;
use App\CashFlow\Contract\BalanceCheckpoint;
use App\CashFlow\Contract\BalanceSource;
use App\Shared\Domain\Money;

/**
 * A balance source that adds a fixed amount to the checkpoint, knows nothing, or fails.
 */
final class FakeBalanceSource implements BalanceSource
{
    /** @var list<BalanceCheckpoint> */
    public array $asked = [];

    public function __construct(private readonly string $key, private readonly ?string $booked = null, private readonly bool $fails = false)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function balance(BalanceCheckpoint $checkpoint, \DateTimeImmutable $today): ?AccountBalance
    {
        $this->asked[] = $checkpoint;
        if ($this->fails) {
            throw new \RuntimeException('The bank broke.');
        }

        return null === $this->booked ? null : new AccountBalance($checkpoint->accountId, $checkpoint->amount->plus(Money::of($this->booked, $checkpoint->amount->currency())), $today);
    }
}
