<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Domain\Id;

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
        return $this->correlation->within($this->correlation->current() ?? 'work:'.Id::generate(), fn (): mixed => $this->transaction->run($change));
    }
}
