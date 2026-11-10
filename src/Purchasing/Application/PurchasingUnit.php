<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Audit\Application\AuditCorrelation;
use App\Party\Application\TransactionalWork;
use App\Shared\Domain\Id;

/**
 * One action of a person on received documents, stored in one transaction
 * under one correlation id, so its history shows it as one step whatever it
 * touched. A failure stores nothing and returns any evidence number taken.
 */
final readonly class PurchasingUnit
{
    public function __construct(private AuditCorrelation $correlation, private TransactionalWork $transaction)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        return $this->correlation->within($this->correlation->current() ?? 'purchasing:'.Id::generate(), fn (): mixed => $this->transaction->run($work));
    }
}
