<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditCorrelation;
use App\Party\Application\TransactionalWork;
use App\Shared\Domain\Id;

/**
 * One action of a person, saved at once in one transaction under one
 * correlation id, so its history shows it as one step whatever it touched.
 */
final readonly class WorkUnit
{
    public function __construct(private AuditCorrelation $correlation, private TransactionalWork $transaction)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     */
    public function run(callable $change): mixed
    {
        return $this->correlation->within($this->correlation->current() ?? 'work:'.Id::generate(), function () use ($change): mixed {
            return $this->transaction->run($change);
        });
    }
}
