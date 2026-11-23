<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\DBAL\Driver\Exception as DriverFailure;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Foresi\Invoicing\Application\ConflictRetry;
use Foresi\Invoicing\Application\UnknownInvoice;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;

final class InvoicePersistenceTest extends InvoiceTestCase
{
    public function testASnapshotOfAnUnknownInvoiceFails(): void
    {
        $this->expectException(UnknownInvoice::class);
        $this->store->snapshot('01963c5e-0000-7000-8000-0000000000ff');
    }

    public function testALockErrorRunsTheWholeUnitAgainUpToThreeTimes(): void
    {
        $retry = self::getContainer()->get(ConflictRetry::class);
        $attempts = 0;

        $result = $retry->run(function () use (&$attempts): int {
            if (++$attempts < 4) {
                throw $this->deadlock();
            }

            return $attempts;
        });

        self::assertSame(4, $result);
    }

    public function testALockErrorTravelsOnAfterThreeRetries(): void
    {
        $retry = self::getContainer()->get(ConflictRetry::class);
        $attempts = 0;

        try {
            $retry->run(function () use (&$attempts): int {
                if (++$attempts < 100) {
                    throw $this->deadlock();
                }

                return $attempts;
            });
            self::fail('Expected the lock error.');
        } catch (DeadlockException) {
            self::assertSame(4, $attempts);
        }
    }

    public function testOtherFailuresAreNotRetried(): void
    {
        $retry = self::getContainer()->get(ConflictRetry::class);
        $attempts = 0;

        try {
            $retry->run(static function () use (&$attempts): int {
                if (++$attempts < 100) {
                    throw new \RuntimeException('Broken.');
                }

                return $attempts;
            });
            self::fail('Expected the failure.');
        } catch (\RuntimeException) {
            self::assertSame(1, $attempts);
        }
    }

    public function testAUniqueViolationRunsTheUnitAgain(): void
    {
        $retry = self::getContainer()->get(ConflictRetry::class);
        $attempts = 0;

        $retry->run(function () use (&$attempts): void {
            if (1 === ++$attempts) {
                throw new UniqueConstraintViolationException($this->driverFailure(), null);
            }
        });

        self::assertSame(2, $attempts);
    }

    public function testInsideAnotherTransactionALockErrorIsLeftToTheOutermostUnit(): void
    {
        $retry = self::getContainer()->get(ConflictRetry::class);
        $attempts = 0;
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $retry->run(function () use (&$attempts): int {
                if (++$attempts < 100) {
                    throw $this->deadlock();
                }

                return $attempts;
            });
            self::fail('Expected the lock error.');
        } catch (DeadlockException) {
            self::assertSame(1, $attempts, 'a lock error ends the whole transaction, so only its outermost unit can run again');
        } finally {
            $connection->rollBack();
        }
    }

    public function testNestedRetriesRunTheOutermostUnitAgain(): void
    {
        $retry = self::getContainer()->get(ConflictRetry::class);
        $work = self::getContainer()->get(TransactionalWork::class);
        $outer = 0;
        $inner = 0;

        $retry->run(function () use ($work, $retry, &$outer, &$inner): void {
            $work->run(function () use ($retry, &$outer, &$inner): void {
                ++$outer;
                $retry->run(function () use (&$inner): void {
                    if (++$inner < 3) {
                        throw $this->deadlock();
                    }
                });
            });
        });

        self::assertSame(3, $outer, 'the outer unit ran three times');
        self::assertSame(3, $inner, 'and the inner one once each time');
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException($this->driverFailure(), null);
    }

    private function driverFailure(): DriverFailure
    {
        return new class('Deadlock.') extends \RuntimeException implements DriverFailure {
            public function getSQLState(): ?string
            {
                return null;
            }
        };
    }
}
