<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Application;

use Foresi\Party\Application\RefreshTodaysVatStatus;
use Foresi\Tests\Party\Support\VatStatusTestCase;

/**
 * The daily job moves the firm onto a dated VAT status on its day, by the
 * midnight of the instance's zone, Prague, not by UTC.
 * The tests that need the Czech rules are in Integration/Country.
 */
final class RefreshTodaysVatStatusTest extends VatStatusTestCase
{
    public function testTheJobIsNamedForTheSchedule(): void
    {
        self::assertSame('party-vat-status-refresh', self::getContainer()->get(RefreshTodaysVatStatus::class)->name());
    }
}
