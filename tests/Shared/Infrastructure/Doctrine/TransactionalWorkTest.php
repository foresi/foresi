<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Shared\Fixtures\TestPrice;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransactionalWorkTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testCaughtDomainErrorLeavesAUsableSession(): void
    {
        $this->em->persist($this->price('1.00'));
        $this->em->flush();

        try {
            $this->work()->run(function (): never {
                $this->em->persist($this->price('2.00'));
                $this->em->flush();

                throw new \DomainException('Rejected.');
            });
        } catch (\DomainException) {
        }

        self::assertTrue($this->em->isOpen());
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM test_price');
        self::assertEquals(1, $count, 'the failed work is rolled back');
    }

    public function testSuccessfulWorkReturnsItsResult(): void
    {
        $marker = new \stdClass();

        self::assertSame($marker, $this->work()->run(static fn (): \stdClass => $marker));
    }

    public function testAJoinedUnitFlushesSoItsCallerFindsWhatItMadeAndTheOuterUnitCommits(): void
    {
        $found = $this->em->wrapInTransaction(function (): mixed {
            $price = $this->work()->run(function (): TestPrice {
                $price = $this->price('3.00');
                $this->em->persist($price);

                return $price;
            });

            // A query, not the identity map: it sees only what was flushed.
            return $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM test_price WHERE id = ?', [$price->id()]);
        });

        self::assertEquals(1, $found);
    }

    private function price(string $amount): TestPrice
    {
        return new TestPrice(Money::of($amount, Currency::of('CZK')));
    }

    private function work(): TransactionalWork
    {
        return self::getContainer()->get(TransactionalWork::class);
    }
}
