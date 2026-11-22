<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Integration\TaxesAndContributions;

use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastRead;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\TaxesAndContributions\Integration\CashFlow\ForecastAmounts;
use PHPUnit\Framework\TestCase;

/**
 * The year end of Taxes and contributions never counts the VAT flows: VAT is
 * no income or cost of the business, and the flows are estimates.
 */
final class TaxesIgnoreTheVatFlowsTest extends TestCase
{
    public function testTheTaxesSuiteStillExcludesVat(): void
    {
        $reader = new class implements ForecastReader {
            public ?ForecastQuery $query = null;

            public function read(ForecastQuery $query): ForecastRead
            {
                $this->query = $query;

                return new ForecastRead([], [], [], false);
            }
        };

        new ForecastAmounts($reader)->between(new \DateTimeImmutable('2027-01-05'), new \DateTimeImmutable('2027-12-31'));

        self::assertContains(FlowCategory::Vat, $reader->query->excludeCategories ?? []);
        self::assertNotContains(FlowCertainty::Estimate, $reader->query->certainties ?? [FlowCertainty::Estimate]);
    }
}
