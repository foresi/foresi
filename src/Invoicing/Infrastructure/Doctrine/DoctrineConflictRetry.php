<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\ConflictRetry;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class DoctrineConflictRetry implements ConflictRetry
{
    private const int RETRIES = 3;

    public function run(callable $unit): mixed
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
