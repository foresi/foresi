<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Health;

/**
 * The checks of one health run, each with its status and at most one number.
 * The installation stands as its worst check.
 */
final readonly class Health
{
    /**
     * @param array<string, array{status: HealthStatus, value: int|null}> $checks
     */
    public function __construct(
        public array $checks,
    ) {
    }

    public function status(): HealthStatus
    {
        return array_reduce($this->checks, static fn (HealthStatus $worst, array $check): HealthStatus => $worst->worse($check['status']), HealthStatus::Ok);
    }
}
