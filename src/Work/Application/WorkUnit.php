<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditCorrelation;
use App\Shared\Domain\Id;

/**
 * One action of a person, saved at once under one correlation id, so its
 * history shows it as one step whatever it touched.
 */
final readonly class WorkUnit
{
    public function __construct(private AuditCorrelation $correlation, private Projects $projects)
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
            $result = $change();
            $this->projects->flush();

            return $result;
        });
    }
}
