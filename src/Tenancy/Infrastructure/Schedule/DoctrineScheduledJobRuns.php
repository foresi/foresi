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
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * One row per job and tenant, written by single statements that are the same on
 * SQLite and PostgreSQL. The claim is an insert that does nothing when the row
 * exists, then one conditional update: of two workers only one sees its row
 * change, so a period runs once. The last period moves only when a run finishes,
 * so a failed or crashed run leaves it, and the job is given what really finished.
 *
 * Times are stored in UTC, to the second, whatever the server's time zone.
 * Periods are compared byte by byte: SQLite does so anyway, PostgreSQL is told
 * to with the "C" collation, so a database locale never reorders them.
 *
 * A run may take at most STALE_AFTER: after that it counts as crashed, another
 * worker may claim it again, and the first one's finish or give-back, which name
 * the start of their own claim, change nothing.
 */
final readonly class DoctrineScheduledJobRuns implements ScheduledJobRuns
{
    /** The longest a run may take; one unfinished for longer has crashed. Also how long a queued message is waited for. */
    public const string STALE_AFTER = '-1 hour';

    private const string DUE = <<<'SQL'
        (last_period IS NULL OR last_period %1$s < :period)
        AND (started_at IS NULL OR finished_at IS NOT NULL OR started_at < :stale)
        SQL;

    private const string CREATE = <<<'SQL'
        INSERT INTO scheduled_job_run (id, tenant_id, job) VALUES (:id, :tenant, :job)
        ON CONFLICT (tenant_id, job) DO NOTHING
        SQL;

    private const string QUEUE = 'UPDATE scheduled_job_run SET queued_period = :period, queued_at = :now WHERE tenant_id = :tenant AND job = :job AND %s AND (queued_period IS NULL OR queued_period <> :period OR queued_at < :stale) RETURNING id';

    private const string CLAIM = 'UPDATE scheduled_job_run SET started_at = :now, finished_at = NULL, queued_period = NULL, queued_at = NULL WHERE tenant_id = :tenant AND job = :job AND %s RETURNING last_period';

    private const string FINISH = 'UPDATE scheduled_job_run SET last_period = :period, finished_at = :now, last_error = NULL WHERE tenant_id = :tenant AND job = :job AND started_at = :started AND finished_at IS NULL';

    private const string GIVE_BACK = 'UPDATE scheduled_job_run SET finished_at = :now, last_error = :error WHERE tenant_id = :tenant AND job = :job AND started_at = :started AND finished_at IS NULL';

    private const array TIMES = ['now' => Types::DATETIME_IMMUTABLE, 'stale' => Types::DATETIME_IMMUTABLE, 'started' => Types::DATETIME_IMMUTABLE];

    public function __construct(
        private Connection $connection,
        private TenantContext $context,
        private ClockInterface $clock,
    ) {
    }

    public function queue(TenantId $tenant, string $job, string $period): bool
    {
        $this->create($tenant->toString(), $job);
        $now = $this->now();

        return [] !== $this->connection->fetchAllAssociative(
            \sprintf(self::QUEUE, $this->due()),
            ['now' => $now, 'stale' => $now->modify(self::STALE_AFTER), 'tenant' => $tenant->toString(), 'job' => $job, 'period' => $period],
            $this->types('now', 'stale'),
        );
    }

    public function claim(string $job, string $period): ?Claim
    {
        $tenant = $this->context->current()->toString();
        $this->create($tenant, $job);

        $now = $this->now();
        // Read to the end, which is what finishes a RETURNING statement on SQLite.
        $claimed = $this->connection->fetchAllAssociative(
            \sprintf(self::CLAIM, $this->due()),
            ['now' => $now, 'stale' => $now->modify(self::STALE_AFTER), 'tenant' => $tenant, 'job' => $job, 'period' => $period],
            $this->types('now', 'stale'),
        );
        if ([] === $claimed) {
            return null;
        }

        $last = $claimed[0]['last_period'];

        return new Claim($job, $period, \is_string($last) ? $last : null, $now);
    }

    public function finish(Claim $claim): void
    {
        $this->connection->executeStatement(
            self::FINISH,
            ['period' => $claim->period, 'now' => $this->now(), 'started' => $claim->startedAt, 'tenant' => $this->context->current()->toString(), 'job' => $claim->job],
            $this->types('now', 'started'),
        );
    }

    public function giveBack(Claim $claim, string $error): void
    {
        $this->connection->executeStatement(
            self::GIVE_BACK,
            ['now' => $this->now(), 'started' => $claim->startedAt, 'error' => $error, 'tenant' => $this->context->current()->toString(), 'job' => $claim->job],
            $this->types('now', 'started'),
        );
    }

    private function create(string $tenant, string $job): void
    {
        $this->connection->executeStatement(self::CREATE, ['id' => Id::generate(), 'tenant' => $tenant, 'job' => $job]);
    }

    private function due(): string
    {
        return \sprintf(self::DUE, $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'COLLATE "C"' : '');
    }

    /**
     * Now in UTC, without the fraction of a second the column would drop, so a
     * claim names its start exactly as it is stored.
     */
    private function now(): \DateTimeImmutable
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
    }

    /**
     * @return array<string, string>
     */
    private function types(string ...$names): array
    {
        return array_intersect_key(self::TIMES, array_flip($names));
    }
}
