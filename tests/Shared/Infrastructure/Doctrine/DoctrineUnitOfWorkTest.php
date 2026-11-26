<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Shared\Application\Event\EventOutbox;
use Foresi\Shared\Infrastructure\Doctrine\DoctrineUnitOfWork;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The contract's unit of work: one transaction for the outermost unit, the
 * outbox told how it ended, and a usable entity manager after a failure. The
 * events themselves are covered by DbalEventRecorderTest.
 */
final class DoctrineUnitOfWorkTest extends KernelTestCase
{
    private DoctrineUnitOfWork $work;
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $told;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->told = new \ArrayObject();
        $outbox = new class($this->told) implements EventOutbox {
            /** @param \ArrayObject<int, string> $told */
            public function __construct(private \ArrayObject $told)
            {
            }

            public function committed(): void
            {
                $this->told[] = 'committed';
            }

            public function rolledBack(): void
            {
                $this->told[] = 'rolledBack';
            }
        };
        $this->work = new DoctrineUnitOfWork($this->em(), self::getContainer()->get(ManagerRegistry::class), $outbox);
    }

    public function testRunsTheWorkInATransactionAndReturnsItsResult(): void
    {
        $result = $this->work->run(fn (): bool => $this->em()->getConnection()->isTransactionActive());

        self::assertTrue($result);
        self::assertFalse($this->em()->getConnection()->isTransactionActive());
        self::assertSame(['committed'], $this->told->getArrayCopy());
    }

    public function testANestedUnitIsPartOfTheOuterOne(): void
    {
        $this->work->run(function (): void {
            $this->work->run(static fn (): null => null);
            self::assertSame([], $this->told->getArrayCopy());
        });

        self::assertSame(['committed'], $this->told->getArrayCopy());
    }

    public function testAFailureRollsBackAndLeavesAUsableEntityManager(): void
    {
        $refusal = new \DomainException('Refused.');
        $caught = null;
        try {
            $this->work->run(function () use ($refusal): void {
                $this->work->run(static fn (): never => throw $refusal);
            });
        } catch (\DomainException $failure) {
            $caught = $failure;
        }

        self::assertSame($refusal, $caught, 'The failure travels on unchanged.');

        self::assertSame(['rolledBack'], $this->told->getArrayCopy());
        self::assertFalse($this->em()->getConnection()->isTransactionActive());
        self::assertTrue($this->em()->isOpen());
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
