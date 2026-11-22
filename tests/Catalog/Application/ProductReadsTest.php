<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Application;

use Foresi\Catalog\Application\ArchiveProductInput;
use Foresi\Catalog\Application\CatalogRefused;
use Foresi\Catalog\Application\ProductReads;
use Foresi\Catalog\Contract\Products;
use Foresi\Catalog\Contract\ProductSnapshot;
use Foresi\Catalog\Domain\Product;
use Foresi\Tests\Catalog\Support\CatalogTestCase;

/**
 * The products as the catalog's screens, the API and MCP read them: only
 * staff read, search leaves out the archived ones unless asked, and the text
 * is trimmed and cut to 100 characters, as other modules search it too.
 */
final class ProductReadsTest extends CatalogTestCase
{
    public function testOnlyStaffRead(): void
    {
        $product = $this->product();
        self::assertSame($product->id(), $this->reads()->one($product->id())->id());

        $this->signIn($this->guest);
        foreach ([fn (): Product => $this->reads()->one($product->id()), fn (): array => $this->reads()->search('')] as $read) {
            try {
                $read();
                self::fail('A guest read the catalog.');
            } catch (CatalogRefused $refused) {
                self::assertSame('forbidden', $refused->reason());
            }
        }
    }

    public function testUnknownProductIsNotFound(): void
    {
        $this->expectExceptionObject(CatalogRefused::notFound());
        $this->reads()->one('0192f000-0000-7000-8000-000000000000');
    }

    public function testSearchHidesArchivedUnlessAsked(): void
    {
        $audit = $this->product();
        $this->writes()->archive(new ArchiveProductInput($audit->id()));

        self::assertSame([], $this->ids($this->reads()->search('')));
        self::assertSame([$audit->id()], $this->ids($this->reads()->search('', true)));
    }

    public function testSearchTextIsTrimmedAndCutToAHundredCharacters(): void
    {
        $audit = $this->product('Roof audit');
        $long = $this->product(str_repeat('č', 100));
        // Matched only when the text is cut by bytes rather than characters.
        $this->product(str_repeat('č', 50).'y');

        $search = str_repeat('č', 100).'x';
        self::assertSame([$audit->id()], $this->ids($this->reads()->search('  Roof  ')));
        self::assertSame([], $this->ids($this->reads()->search('xRoof')));
        self::assertSame([$long->id()], $this->ids($this->reads()->search($search)));

        $products = self::getContainer()->get(Products::class);
        self::assertSame([$audit->id()], $this->snapshotIds($products->search('  Roof  ')));
        self::assertSame([], $this->snapshotIds($products->search('xRoof')));
        self::assertSame([$long->id()], $this->snapshotIds($products->search($search)));
    }

    /**
     * @param list<ProductSnapshot> $snapshots
     *
     * @return list<string>
     */
    private function snapshotIds(array $snapshots): array
    {
        return array_map(static fn (ProductSnapshot $snapshot): string => $snapshot->id, $snapshots);
    }

    private function reads(): ProductReads
    {
        return self::getContainer()->get(ProductReads::class);
    }

    /**
     * @param list<Product> $products
     *
     * @return list<string>
     */
    private function ids(array $products): array
    {
        return array_map(static fn (Product $product): string => $product->id(), $products);
    }
}
