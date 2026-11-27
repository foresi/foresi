<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Integration\Invoicing\Support;

use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Tests\Banking\Support\InboundFixtures;

/**
 * The invoicing test firm with an inbound address on its account, synthetic
 * Komerční banka notices to it (InboundFixtures) and the invoice they pay.
 */
abstract class InvoiceInboundTestCase extends InvoiceBankingTestCase
{
    use InboundFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInbound();
    }

    /**
     * The invoice the notices pay: 150.00 CZK with VAT, VS 2026001, issued the day before.
     */
    protected function kbInvoice(string $vs = '2026001', string $price = '150.00'): Invoice
    {
        return $this->issue->issue($this->input([$this->line($price, PriceType::WithVat)], ['variableSymbol' => $vs]));
    }
}
