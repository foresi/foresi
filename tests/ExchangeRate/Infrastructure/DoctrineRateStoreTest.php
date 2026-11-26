<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\Quote;
use Foresi\ExchangeRate\Domain\StoredRate;
use Foresi\ExchangeRate\Infrastructure\Doctrine\DoctrineRateStore;
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

    public function testTheTableIsKeyedByDateCurrencyAndSource(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $table = new SchemaTool($em)->getSchemaFromMetadata([$em->getClassMetadata(StoredRate::class)])->getTable('exchange_rate');

        self::assertSame(['rate_date', 'currency', 'source'], $table->getPrimaryKey()?->getColumns());
    }

    public function testWhatIsSavedIsFoundForTheDateItWasSavedFor(): void
    {
        $published = new PublishedRates(new \DateTimeImmutable('2026-11-06'), ['JPY' => new Quote('JPY', 100, '13.717')], 'CZK');
        $this->store->save(new \DateTimeImmutable('2026-11-07'), $published, 'cnb');

        $found = $this->store->find('cnb', new \DateTimeImmutable('2026-11-07'), ['JPY', 'CZK']);

        self::assertNotNull($found);
        self::assertSame(['JPY'], array_keys($found->quotes));
        self::assertSame([100, '13.717', 'CZK'], [$found->quotes['JPY']->amount, $found->quotes['JPY']->rate, $found->base]);
        self::assertSame('2026-11-06', $found->publishedOn->format('Y-m-d'));
        self::assertNull($this->store->find('cnb', new \DateTimeImmutable('2026-11-06'), ['JPY']));
        self::assertNull($this->store->find('cnb', new \DateTimeImmutable('2026-11-07'), ['EUR']));
        self::assertNull($this->store->find('ecb', new \DateTimeImmutable('2026-11-07'), ['JPY']));
        self::assertTrue($this->store->has('cnb', new \DateTimeImmutable('2026-11-07')));
        self::assertFalse($this->store->has('cnb', new \DateTimeImmutable('2026-11-06')));
        self::assertFalse($this->store->has('ecb', new \DateTimeImmutable('2026-11-07')));
    }

    public function testTwoSourcesKeepTheSameDaySideBySideEachInItsBase(): void
    {
        $date = new \DateTimeImmutable('2026-11-25');
        $this->store->save($date, new PublishedRates($date, ['USD' => new Quote('USD', 1, '22.345')], 'CZK'), 'cnb');
        $this->store->save($date, new PublishedRates($date, ['USD' => new Quote('USD', 10935, '10000'), 'CZK' => new Quote('CZK', 24435, '1000')], 'EUR'), 'ecb');

        $crowns = $this->store->find('cnb', $date, ['USD', 'CZK']);
        $euros = $this->store->find('ecb', $date, ['USD', 'CZK']);

        self::assertSame(['CZK', '22.345'], [$crowns?->base, $crowns?->quotes['USD']->rate]);
        self::assertSame(['EUR', 10935, '10000', 24435], [$euros?->base, $euros?->quotes['USD']->amount, $euros?->quotes['USD']->rate, $euros?->quotes['CZK']->amount]);
    }

    public function testASecondSaveLeavesTheStoredRateAlone(): void
    {
        $date = new \DateTimeImmutable('2026-11-10');
        $this->store->save($date, new PublishedRates($date, ['EUR' => new Quote('EUR', 1, '24.335')], 'CZK'), 'cnb');
        $this->store->save($date, new PublishedRates($date, ['EUR' => new Quote('EUR', 1, '99.000')], 'CZK'), 'cnb');

        self::assertSame('24.335', $this->store->find('cnb', $date, ['EUR'])?->quotes['EUR']->rate);
    }
}
