<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\ImportedTotals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportedTotalsTest extends TestCase
{
    /**
     * @return iterable<string, array{ImportedTotals}>
     */
    public static function differences(): iterable
    {
        yield 'net' => [new ImportedTotals(99, 21, 121, 121)];
        yield 'vat' => [new ImportedTotals(100, 20, 121, 121)];
        yield 'gross' => [new ImportedTotals(100, 21, 120, 121)];
        yield 'payable' => [new ImportedTotals(100, 21, 121, 120)];
    }

    #[DataProvider('differences')]
    public function testADifferenceInAnyTotalIsADifferentDocument(ImportedTotals $other): void
    {
        $totals = new ImportedTotals(100, 21, 121, 121);

        self::assertTrue($totals->equals(new ImportedTotals(100, 21, 121, 121)));
        self::assertFalse($totals->equals($other));
    }
}
