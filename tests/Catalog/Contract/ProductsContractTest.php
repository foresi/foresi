<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Contract;

use Foresi\Catalog\Application\ArchiveProductInput;
use Foresi\Catalog\Application\CatalogRefused;
use Foresi\Catalog\Application\RestoreProductInput;
use Foresi\Catalog\Application\UpdateProductInput;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Catalog\Contract\Products;
use Foresi\Catalog\Contract\ProductSnapshot;
use Foresi\Catalog\Contract\UnknownProduct;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Tests\Catalog\Support\CatalogTestCase;

/**
 * The products as other modules read them (Foresi\Catalog\Contract\Products):
 * a snapshot says what the product says now, and search leaves out the
 * archived ones unless asked.
 */
final class ProductsContractTest extends CatalogTestCase
{
    public function testSnapshotMatchesProduct(): void
    {
        $retainer = $this->product('Marketing retainer', 'recurring', 'month', '25000.00', 'EUR', 'month', 1, 12, 'Monthly campaign work.');
        $this->em->clear();

        $snapshot = $this->products()->get($retainer->id());

        self::assertEquals(new ProductSnapshot($retainer->id(), 'Marketing retainer', 'month', $snapshot->unitPrice, BillingKind::Recurring, RecurrenceUnit::Month, 1, 12, false), $snapshot);
        self::assertSame([2500000, 'EUR'], [$snapshot->unitPrice?->minor(), $snapshot->unitPrice?->currency()->value]);

        $audit = $this->product(unitPrice: null);
        $this->writes()->archive(new ArchiveProductInput($audit->id()));
        $snapshot = $this->products()->get($audit->id());
        self::assertSame([null, BillingKind::OneOff, null, null, null, true], [$snapshot->unitPrice, $snapshot->billingKind, $snapshot->recurrenceUnit, $snapshot->interval, $snapshot->cycles, $snapshot->archived]);

        $this->expectException(UnknownProduct::class);
        $this->products()->get('0192f000-0000-7000-8000-000000000000');
    }

    public function testArchivedHiddenFromSearchByDefault(): void
    {
        $audit = $this->product('Roof audit', description: 'An inspection of the roof.');
        $retainer = $this->product('Maintenance retainer', 'recurring', recurrenceUnit: 'year', interval: 1);
        $this->writes()->archive(new ArchiveProductInput($audit->id()));

        self::assertSame([$retainer->id()], $this->ids($this->products()->search('')));
        self::assertSame([$retainer->id(), $audit->id()], $this->ids($this->products()->search('', true)));
        // The text is found in the name or the description, without regard to case.
        self::assertSame([$audit->id()], $this->ids($this->products()->search('INSPECTION', true)));
        self::assertSame([], $this->ids($this->products()->search('100%')));

        $this->writes()->restore(new RestoreProductInput($audit->id()));
        self::assertSame([$retainer->id(), $audit->id()], $this->ids($this->products()->search('')));
    }

    public function testEachWriteRecordsItsEventOnceAndOnlyStaffWrite(): void
    {
        $product = $this->product();
        $this->writes()->update(new UpdateProductInput($product->id(), billingKind: 'recurring', recurrenceUnit: 'month', interval: 3));
        $this->writes()->archive(new ArchiveProductInput($product->id()));
        $this->writes()->archive(new ArchiveProductInput($product->id()));
        $this->writes()->restore(new RestoreProductInput($product->id()));
        $this->writes()->restore(new RestoreProductInput($product->id()));

        $payload = ['product_id' => $product->id(), 'billing_kind' => 'one_off'];
        self::assertSame([$payload], $this->events('catalog.product.created'));
        self::assertSame([['product_id' => $product->id(), 'billing_kind' => 'recurring', 'custom_fields' => []]], $this->events('catalog.product.updated'));
        self::assertCount(1, $this->events('catalog.product.archived'));
        self::assertCount(1, $this->events('catalog.product.restored'));

        // Made one-off again, the product keeps no period.
        $oneOff = $this->writes()->update(new UpdateProductInput($product->id(), billingKind: 'one_off'));
        self::assertSame([null, null, null], [$oneOff->recurrenceUnit(), $oneOff->interval(), $oneOff->cycles()]);
        // A price sent as null is cleared; the rest stays.
        $cleared = $this->writes()->update(new UpdateProductInput($product->id(), unitPrice: null));
        self::assertSame([null, 'Roof audit', 'piece'], [$cleared->unitPrice(), $cleared->name(), $cleared->unit()]);

        $this->signIn($this->guest);
        try {
            $this->product();
            self::fail('A guest created a product.');
        } catch (CatalogRefused $refused) {
            self::assertSame('forbidden', $refused->reason());
        }
    }

    private function products(): Products
    {
        return self::getContainer()->get(Products::class);
    }

    /**
     * @param list<ProductSnapshot> $snapshots
     *
     * @return list<string>
     */
    private function ids(array $snapshots): array
    {
        return array_map(static fn (ProductSnapshot $snapshot): string => $snapshot->id, $snapshots);
    }
}
