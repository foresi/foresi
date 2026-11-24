<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Application;

use Foresi\Catalog\Application\ArchiveProductInput;
use Foresi\Catalog\Application\CatalogRefused;
use Foresi\Catalog\Application\RestoreProductInput;
use Foresi\Catalog\Application\UpdateProductInput;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Tests\Catalog\Support\CatalogTestCase;

/**
 * The writes of the catalog: an update keeps what it does not send, archive
 * and restore record their event only when they change the product, and only
 * staff write.
 */
final class ProductWritesTest extends CatalogTestCase
{
    public function testUpdateKeepsThePriceAndThePeriodItDoesNotSend(): void
    {
        $retainer = $this->product('Retainer', 'recurring', 'month', '1500.05', 'EUR', 'month', 2, 12);

        $renamed = $this->writes()->update(new UpdateProductInput($retainer->id(), name: 'Support retainer'));

        self::assertSame(['Support retainer', 150005, 'EUR', 2, 12], [$renamed->name(), $renamed->unitPrice()?->minor(), $renamed->unitPrice()?->currency()->value, $renamed->interval(), $renamed->cycles()]);
    }

    public function testMakingAProductTimeBasedClearsItsPeriodAndNeedsTheHour(): void
    {
        $retainer = $this->product('Retainer', 'recurring', 'month', '1500.00', 'CZK', 'month', 2, 12);

        $hourly = $this->writes()->update(new UpdateProductInput($retainer->id(), billingKind: 'time_based', unit: 'h'));

        self::assertSame([BillingKind::TimeBased, 'h', null, null, null, 150000], [$hourly->billingKind(), $hourly->unit(), $hourly->recurrenceUnit(), $hourly->interval(), $hourly->cycles(), $hourly->unitPrice()?->minor()]);

        $other = $this->product('Retainer B', 'recurring', 'month', '1500.00', 'CZK', 'month', 2, 12);
        $this->expectException(CatalogRefused::class);
        $this->writes()->update(new UpdateProductInput($other->id(), billingKind: 'time_based'));
    }

    public function testArchiveAndRestoreRecordOnlyAChange(): void
    {
        $product = $this->product();
        $counts = [];
        foreach ([true, true, false, false] as $archive) {
            $archive
                ? $this->writes()->archive(new ArchiveProductInput($product->id()))
                : $this->writes()->restore(new RestoreProductInput($product->id()));
            $counts[] = [\count($this->events('catalog.product.archived')), \count($this->events('catalog.product.restored'))];
        }

        self::assertSame([[1, 0], [1, 0], [1, 1], [1, 1]], $counts);
    }

    public function testUnknownProductIsNotFound(): void
    {
        $this->expectExceptionObject(CatalogRefused::notFound());
        $this->writes()->archive(new ArchiveProductInput('0192f000-0000-7000-8000-000000000000'));
    }

    public function testGuestChangesNothing(): void
    {
        $id = $this->product()->id();
        $this->signIn($this->guest);

        foreach ([
            fn (): \Foresi\Catalog\Domain\Product => $this->writes()->update(new UpdateProductInput($id, name: 'Renamed')),
            fn (): \Foresi\Catalog\Domain\Product => $this->writes()->archive(new ArchiveProductInput($id)),
            fn (): \Foresi\Catalog\Domain\Product => $this->writes()->restore(new RestoreProductInput($id)),
        ] as $write) {
            try {
                $write();
                self::fail('A guest changed a product.');
            } catch (CatalogRefused $refused) {
                self::assertSame('forbidden', $refused->reason());
            }
        }
        self::assertSame([], $this->events('catalog.product.updated'));
        self::assertSame([], $this->events('catalog.product.archived'));
    }
}
