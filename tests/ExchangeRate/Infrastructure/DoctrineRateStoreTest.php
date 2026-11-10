<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\ExchangeRate\Infrastructure;

use App\ExchangeRate\Application\PublishedRates;
use App\ExchangeRate\Application\Quote;
use App\ExchangeRate\Domain\StoredRate;
use App\ExchangeRate\Infrastructure\Doctrine\DoctrineRateStore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The store on the real mapping: the global table is created from the entity.
 */
final class DoctrineRateStoreTest extends KernelTestCase
{
    private DoctrineRateStore $store;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->store = new DoctrineRateStore($em->getConnection());
    }

    public function testTheTableHasNoTenantAndIsKeyedByDateAndCurrency(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $table = new SchemaTool($em)->getSchemaFromMetadata([$em->getClassMetadata(StoredRate::class)])->getTable('exchange_rate');

        self::assertFalse($table->hasColumn('tenant_id'));
        self::assertSame(['rate_date', 'currency'], $table->getPrimaryKey()?->getColumns());
    }

    public function testWhatIsSavedIsFoundForTheDateItWasSavedFor(): void
    {
        $published = new PublishedRates(new \DateTimeImmutable('2026-11-06'), ['JPY' => new Quote('JPY', 100, '13.717')]);
        $this->store->save(new \DateTimeImmutable('2026-11-07'), $published, 'cnb');

        $rate = $this->store->find('JPY', new \DateTimeImmutable('2026-11-07'));

        self::assertNotNull($rate);
        self::assertSame(100, $rate->amount);
        self::assertSame('13.717', $rate->rate);
        self::assertSame('2026-11-07', $rate->validOn->format('Y-m-d'));
        self::assertSame('2026-11-06', $rate->publishedOn->format('Y-m-d'));
        self::assertNull($this->store->find('JPY', new \DateTimeImmutable('2026-11-06')));
        self::assertNull($this->store->find('EUR', new \DateTimeImmutable('2026-11-07')));
        self::assertTrue($this->store->has(new \DateTimeImmutable('2026-11-07')));
        self::assertFalse($this->store->has(new \DateTimeImmutable('2026-11-06')));
    }

    public function testASecondSaveLeavesTheStoredRateAlone(): void
    {
        $date = new \DateTimeImmutable('2026-11-10');
        $this->store->save($date, new PublishedRates($date, ['EUR' => new Quote('EUR', 1, '24.335')]), 'cnb');
        $this->store->save($date, new PublishedRates($date, ['EUR' => new Quote('EUR', 1, '99.000')]), 'cnb');

        self::assertSame('24.335', $this->store->find('EUR', $date)?->rate);
    }
}
