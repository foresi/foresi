<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Shared\Fixtures\TestPrice;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testMoneyRoundTripsThroughTheDatabase(): void
    {
        $price = new TestPrice(Money::of('-1234.56', Currency::EUR));
        $this->em->persist($price);
        $this->em->flush();
        $id = $price->id();
        $this->em->clear();

        $loaded = $this->em->find(TestPrice::class, $id);

        self::assertInstanceOf(TestPrice::class, $loaded);
        self::assertTrue(Money::of('-1234.56', Currency::EUR)->equals($loaded->price()));
    }

    public function testMinorUnitsAreStoredAsIntegerWithTheCurrencyCode(): void
    {
        $this->em->persist(new TestPrice(Money::of('10.05', Currency::CZK)));
        $this->em->flush();

        $row = $this->em->getConnection()->fetchAssociative('SELECT amount_minor, currency FROM test_price');

        self::assertSame(['amount_minor' => 1005, 'currency' => 'CZK'], $row);
    }
}
