<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\InvoiceBundle;

final class InvoiceBundleTest extends AdvanceTestCase
{
    private InvoiceBundle $bundle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bundle = self::getContainer()->get(InvoiceBundle::class);
    }

    public function testThePackIsNamedByFirmAndMonth(): void
    {
        self::assertSame('example-firm-s-r-o_2026-09.zip', $this->bundle->fileName(new \DateTimeImmutable('2026-09-30')));
    }
}
