<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\IssueRecurringInvoices;

/**
 * The daily run of recurring templates, at 05:00 in the instance's zone (Prague
 * here, from the firm's country): what it issues, what it sends or holds for
 * review, and what it records and tells when a template's world moved. Every
 * outcome is read back from the stored rows; no mail leaves, the gateway records.
 */
final class IssueRecurringInvoicesTest extends SendingTestCase
{
    public function testTheDayBeginsAtFiveInstanceTime(): void
    {
        $job = self::getContainer()->get(IssueRecurringInvoices::class);
        $prague = new \DateTimeZone('Europe/Prague');

        self::assertSame('recurring-invoices', $job->name());
        self::assertSame('2026-12-31', $job->period(new \DateTimeImmutable('2027-01-01 03:59:00 UTC'), $prague));
        self::assertSame('2027-01-01', $job->period(new \DateTimeImmutable('2027-01-01 04:00:00 UTC'), $prague));
    }
}
