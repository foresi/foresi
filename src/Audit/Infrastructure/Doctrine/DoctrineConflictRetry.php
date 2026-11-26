<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Audit\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Shared\Application\ConflictRetry;
use Foresi\Shared\Domain\Id;

/**
 * Every unit carries an audit correlation of its own, or joins the one already running,
 * so what one save wrote can be told from the next, however close in time.
 *
 * A unit inside a transaction already running is not run again: the lock error or
 * the unique index failed that whole transaction, so the conflict travels on to the
 * outermost unit, which starts it over.
 */
final readonly class DoctrineConflictRetry implements ConflictRetry
{
    private const int RETRIES = 3;

    public function __construct(private AuditCorrelation $correlation, private Connection $connection)
    {
    }

    public function run(callable $unit): mixed
    {
        return $this->correlation->within($this->correlation->current() ?? 'invoice:'.Id::generate(), fn (): mixed => $this->connection->isTransactionActive() ? $unit() : $this->attempts($unit));
    }

    /**
     * @template T
     *
     * @param callable(): T $unit
     *
     * @return T
     */
    private function attempts(callable $unit): mixed
    {
        $retries = 0;
        while (true) {
            try {
                return $unit();
            } catch (RetryableException|UniqueConstraintViolationException $conflict) {
                if (++$retries > self::RETRIES) {
                    throw $conflict;
                }
            }
        }
    }
}
