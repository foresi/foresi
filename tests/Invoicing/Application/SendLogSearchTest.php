<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\SendLogFilter;
use Foresi\Invoicing\Application\SendLogRow;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The text search of the send log: the number, the customer, the subject and the
 * recipients, whatever the case, with the wildcards of LIKE taken as characters.
 */
final class SendLogSearchTest extends SendingTestCase
{
    public function testTheLogIsFoundByNumberCustomerSubjectOrRecipient(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->sent($invoice, ['subject' => 'Your hosting invoice', 'to' => ['billing@example.com'], 'cc' => ['accounts@example.org']]);

        $found = fn (string $text): int => \count($this->sends->page(new SendLogFilter(new ViewFilter(), 1, $text))->rows);
        self::assertSame(1, $found(''));
        self::assertSame(1, $found('HOSTING'), 'by the subject');
        self::assertSame(1, $found('example customer'), 'by the customer');
        self::assertSame(1, $found($this->numberOf($invoice->id())), 'by the number');
        self::assertSame(1, $found('ACCOUNTS@example.org'), 'by a recipient in copy');
        self::assertSame(0, $found('%'));
        self::assertSame(0, $found('h_sting'));
        self::assertSame(0, $found('nothing like it'));
        self::assertSame(0, $this->sends->page(new SendLogFilter(new ViewFilter(), 1, 'nothing'))->total);
        self::assertTrue($this->sends->page(new SendLogFilter(new ViewFilter(), 1, 'nothing'))->anySent, 'a search that finds nothing is not a log that is empty');
    }

    private function numberOf(string $invoiceId): string
    {
        $rows = $this->sends->page(new SendLogFilter())->rows;
        self::assertContainsOnlyInstancesOf(SendLogRow::class, $rows);
        self::assertSame($invoiceId, $rows[0]->invoiceId);

        return $rows[0]->documentNumber;
    }
}
