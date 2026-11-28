<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\Invoicing\CzechRepublic;

use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechInvoicing;
use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechSupplyCodes;
use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechVatRates;
use Foresi\Country\Integration\Invoicing\CzechRepublic\IsdocWriter;
use Foresi\Country\Integration\Invoicing\CzechRepublic\QrPlatba;
use Foresi\Shared\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class CzechSettlementLimitTest extends TestCase
{
    public function testACzechFirmWritesOffUpTo99HalerInCrowns(): void
    {
        self::assertSame(99, $this->czech()->settlementLimit(Currency::of('CZK')));
    }

    public function testAnotherCurrencyHasNoSuchCustom(): void
    {
        self::assertNull($this->czech()->settlementLimit(Currency::of('EUR')));
    }

    private function czech(): CzechInvoicing
    {
        return new CzechInvoicing(new CzechVatRates(), new CzechSupplyCodes(), new IsdocWriter(), new QrPlatba(new CzechVatRates()));
    }
}
