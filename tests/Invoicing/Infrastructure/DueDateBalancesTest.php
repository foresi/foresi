<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Application\InvoiceBalances;
use App\Tests\Invoicing\Application\InvoiceBalancesContractTest;

final class DueDateBalancesTest extends InvoiceBalancesContractTest
{
    protected function balances(): InvoiceBalances
    {
        return self::getContainer()->get('test.invoice_balances');
    }

    protected function settle(string $invoiceId): void
    {
        // Without payments, the trash is the only way an invoice stops being owed.
        $this->trashInvoice($invoiceId);
    }

    public function testItIsNotAuthoritative(): void
    {
        self::assertFalse($this->balances()->isAuthoritative());
    }

    public function testAnOpenInvoiceOwesItsPayable(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->em->clear();

        self::assertSame(121000, $this->balances()->outstanding([$invoice->id()])[$invoice->id()]->minor());
    }
}
