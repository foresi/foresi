<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Schedule;

use App\Shared\Domain\Id;
use App\Tenancy\Application\Schedule\Claim;
use App\Tenancy\Application\Schedule\ScheduledJobRuns;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * One row per job and tenant, written by single statements that are the same on
 * SQLite and PostgreSQL. The claim is an insert that does nothing when the row
 * exists, then one conditional update: of two workers only one sees its row
 * change, so a period runs once. The last period moves only when a run finishes,
 * so a failed or crashed run leaves it, and the job is given what really finished.
 */
final readonly class DoctrineScheduledJobRuns implements ScheduledJobRuns
{
    /** A run unfinished for this long has crashed and may be claimed again. */
    private const string STALE_AFTER = '-1 hour';

    private const string DUE = <<<'SQL'
        (last_period IS NULL OR last_period < :period)
        AND (started_at IS NULL OR finished_at IS NOT NULL OR started_at < :stale)
        SQL;

    private const string CREATE = <<<'SQL'
        INSERT INTO scheduled_job_run (id, tenant_id, job) VALUES (:id, :tenant, :job)
        ON CONFLICT (tenant_id, job) DO NOTHING
        SQL;

    private const string CLAIM = 'UPDATE scheduled_job_run SET started_at = :now, finished_at = NULL WHERE tenant_id = :tenant AND job = :job AND '.self::DUE.' RETURNING last_period';

    private const string IS_DUE = 'SELECT CASE WHEN '.self::DUE.' THEN 1 ELSE 0 END FROM scheduled_job_run WHERE tenant_id = :tenant AND job = :job';

    private const string FINISH = 'UPDATE scheduled_job_run SET last_period = :period, finished_at = :now, last_error = NULL WHERE tenant_id = :tenant AND job = :job';

    private const string GIVE_BACK = 'UPDATE scheduled_job_run SET finished_at = :now, last_error = :error WHERE tenant_id = :tenant AND job = :job';

    public function __construct(
        private Connection $connection,
        private TenantContext $context,
        private ClockInterface $clock,
    ) {
    }

    public function claim(string $job, string $period): ?Claim
    {
        $tenant = $this->context->current()->toString();
        $this->connection->executeStatement(self::CREATE, ['id' => Id::generate(), 'tenant' => $tenant, 'job' => $job]);

        $now = $this->clock->now();
        // Read to the end, which is what finishes a RETURNING statement on SQLite.
        $claimed = $this->connection->fetchAllAssociative(
            self::CLAIM,
            ['now' => $now, 'stale' => $now->modify(self::STALE_AFTER), 'tenant' => $tenant, 'job' => $job, 'period' => $period],
            ['now' => Types::DATETIME_IMMUTABLE, 'stale' => Types::DATETIME_IMMUTABLE],
        );
        if ([] === $claimed) {
            return null;
        }

        $last = $claimed[0]['last_period'];

        return new Claim($job, $period, \is_string($last) ? $last : null);
    }

    public function finish(Claim $claim): void
    {
        $this->connection->executeStatement(
            self::FINISH,
            ['period' => $claim->period, 'now' => $this->clock->now(), 'tenant' => $this->context->current()->toString(), 'job' => $claim->job],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function giveBack(Claim $claim, string $error): void
    {
        $this->connection->executeStatement(
            self::GIVE_BACK,
            ['now' => $this->clock->now(), 'error' => $error, 'tenant' => $this->context->current()->toString(), 'job' => $claim->job],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function isDue(TenantId $tenant, string $job, string $period): bool
    {
        $due = $this->connection->fetchOne(
            self::IS_DUE,
            ['period' => $period, 'stale' => $this->clock->now()->modify(self::STALE_AFTER), 'tenant' => $tenant->toString(), 'job' => $job],
            ['stale' => Types::DATETIME_IMMUTABLE],
        );

        return false === $due || 1 === $due || '1' === $due;
    }
}
