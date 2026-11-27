<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Integration\Invoicing\Support;

use Foresi\Invoicing\Application\InvoicePayments;
use Foresi\Invoicing\Application\PaymentState;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Tests\Banking\Support\BankingFixtures;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * The invoicing test firm with its account, a clock on 20 November 2026 and the
 * banking services, for movements that pay invoices. The default invoice is
 * 1,210.00 CZK with VS 20260001.
 */
abstract class InvoiceBankingTestCase extends InvoiceTestCase
{
    use BankingFixtures;

    protected InvoicePayments $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBanking();
        $this->payments = self::getContainer()->get(InvoicePayments::class);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    protected function stateOf(Invoice $invoice): PaymentState
    {
        $stored = $this->reloaded($invoice);

        return PaymentState::of($stored->payable(), $this->payments->paidOf($stored));
    }

    protected function paymentCount(): int
    {
        return self::int($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment'));
    }
}
