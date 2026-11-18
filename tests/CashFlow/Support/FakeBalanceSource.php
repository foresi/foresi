<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Support;

use Foresi\CashFlow\Contract\AccountBalance;
use Foresi\CashFlow\Contract\BalanceCheckpoint;
use Foresi\CashFlow\Contract\BalanceSource;
use Foresi\Shared\Domain\Money;

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
