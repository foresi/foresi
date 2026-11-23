<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\DocumentParties;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Shared\Domain\Currency;

/**
 * The rules of a corrective tax document asked one by one, on an invoice of
 * 1,000 and 500 CZK at the base rate issued on 1 November 2026.
 */
final class CorrectionRulesTest extends InvoiceTestCase
{
    public function testATaxDocumentOnPaymentThatTaxesNothingYetIsNotReplaced(): void
    {
        $series = new Series('Tax documents', SeriesFormat::fromString('D{NNN}'), DocumentKind::PaymentTaxDocument);
        $copy = DocumentParties::ofCustomer($this->customer, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ'), null);
        $document = new Invoice($series, 'D1', VatRegime::Payer, TotalRounding::None, Currency::CZK, $copy, $this->input()->change($this->customer, $copy, DocumentKind::PaymentTaxDocument), null, new \DateTimeImmutable('2026-11-01'));

        self::assertFalse($document->isReplaced());
    }
}
