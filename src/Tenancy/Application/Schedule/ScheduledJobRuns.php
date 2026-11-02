<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Schedule;

use App\Tenancy\Domain\TenantId;

/**
 * Port: one record per job and tenant of the last finished period and the run
 * in progress. Claiming is atomic, so two workers never run the same period.
 */
interface ScheduledJobRuns
{
    /**
     * Claims the period in the active tenant. Null when it has finished already
     * or another worker runs the job right now; a run left unfinished for an hour
     * counts as crashed and can be claimed again.
     */
    public function claim(string $job, string $period): ?Claim;

    /**
     * Records the claimed period as finished.
     */
    public function finish(Claim $claim): void;

    /**
     * Ends the run without finishing its period, which the next tick retries.
     */
    public function giveBack(Claim $claim, string $error): void;

    /**
     * Whether a claim of the period could succeed now, read without a tenant
     * being active, so the tick sends work only where some is left.
     */
    public function isDue(TenantId $tenant, string $job, string $period): bool;
}
