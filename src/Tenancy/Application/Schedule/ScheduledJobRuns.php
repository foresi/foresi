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
     * counts as crashed and can be claimed again, so a run may take at most an
     * hour.
     */
    public function claim(string $job, string $period): ?Claim;

    /**
     * Records the claimed period as finished. A no-op when the run took longer
     * than an hour and its period was claimed again meanwhile.
     */
    public function finish(Claim $claim): void;

    /**
     * Ends the run without finishing its period, which the next tick retries.
     */
    public function giveBack(Claim $claim, string $error): void;

    /**
     * The last period of the job that finished in the active tenant; null before
     * the first one. A run in progress does not count until it finishes.
     */
    public function lastFinished(string $job): ?string;

    /**
     * Marks the period as queued when a claim of it could succeed now and it is
     * not queued already, without a tenant being active. True means the caller
     * sends the work; a second tick before a worker took it gets false. A mark
     * whose message was lost expires after an hour.
     */
    public function queue(TenantId $tenant, string $job, string $period): bool;
}
