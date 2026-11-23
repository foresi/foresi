<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\NewInvoiceDefaults;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\VatStatus;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class NewInvoiceDefaultsTest extends InvoiceTestCase
{
    private NewInvoiceDefaults $defaults;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-01 12:00:00', 'UTC'));
        parent::setUp();
        $this->defaults = self::getContainer()->get(NewInvoiceDefaults::class);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAFirmOfAnUnsupportedCountryHasNoDefaults(): void
    {
        $this->reconfigureFirm(VatStatus::NotRegistered, null, 'Register', new Address('Example Street 1', 'Example City', '100 00', 'SK'));

        $this->expectException(UnsupportedCountry::class);
        $this->defaults->for();
    }
}
