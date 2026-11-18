<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\CzechRepublic\Invoicing;

use Foresi\Country\CzechRepublic\Invoicing\CzechInvoicing;
use Foresi\Country\CzechRepublic\Invoicing\CzechSupplyCodes;
use Foresi\Country\CzechRepublic\Invoicing\CzechVatRates;
use Foresi\Country\CzechRepublic\Invoicing\IsdocWriter;
use Foresi\Country\CzechRepublic\Invoicing\QrPlatba;
use Foresi\Shared\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class CzechSettlementLimitTest extends TestCase
{
    public function testACzechFirmWritesOffUpTo99HalerInCrowns(): void
    {
        self::assertSame(99, $this->czech()->settlementLimit(Currency::CZK));
    }

    public function testAnotherCurrencyHasNoSuchCustom(): void
    {
        self::assertNull($this->czech()->settlementLimit(Currency::EUR));
    }

    private function czech(): CzechInvoicing
    {
        return new CzechInvoicing(new CzechVatRates(), new CzechSupplyCodes(), new IsdocWriter(), new QrPlatba(new CzechVatRates()));
    }
}
