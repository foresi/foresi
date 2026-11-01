<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Audit\Application\AuditCorrelation;
use App\Invoicing\Application\ConflictRetry;
use App\Shared\Domain\Id;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Every unit carries an audit correlation of its own, or joins the one already running,
 * so what one save wrote can be told from the next, however close in time.
 */
final readonly class DoctrineConflictRetry implements ConflictRetry
{
    private const int RETRIES = 3;

    public function __construct(private AuditCorrelation $correlation)
    {
    }

    public function run(callable $unit): mixed
    {
        return $this->correlation->within($this->correlation->current() ?? 'invoice:'.Id::generate(), fn (): mixed => $this->attempts($unit));
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
