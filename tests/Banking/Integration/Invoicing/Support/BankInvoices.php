<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Integration\Invoicing\Support;

use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * Invoices of the signed-in firm, for a bank screen test whose movements pay
 * them: the default series is made with the first one.
 */
trait BankInvoices
{
    protected ?Series $series = null;

    /**
     * An invoice, price without VAT; 1 000.00 is 1 210.00 to pay.
     */
    protected function invoice(string $price = '1000.00', string $vs = '', string $due = '2026-11-15', ?Series $series = null, Currency $currency = Currency::CZK, ?Party $customer = null, string $iban = self::IBAN): Invoice
    {
        return $this->scoped(function () use ($price, $vs, $due, $series, $currency, $customer, $iban): Invoice {
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of($price, $currency), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput(($series ?? $this->defaultSeries())->id(), null, ($customer ?? $this->customer)->id(), null, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable($due), PaymentMethod::BankTransfer, $iban, $vs, '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line], '' === $vs));
        });
    }

    protected function defaultSeries(): Series
    {
        if (null === $this->series) {
            $series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
            $series->markDefault();
            $this->em()->persist($series);
            $this->em()->flush();
            $this->series = $series;
        }

        return $this->series;
    }
}
