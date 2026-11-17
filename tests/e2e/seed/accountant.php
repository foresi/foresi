<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

// The world of the accountant pack spec: a payer firm and one customer, two invoices of last
// month (1 210 Kč at 21 % and 560 Kč at 12 %), an advance of last month for 1 210 Kč and an
// invoice of this month for 2 420 Kč. Nothing is exported yet, so the overview reminds of last
// month. Dates count from the real today, as the overview and the export page do.
$accountantFirm = new LegalEntity('Example Ledger s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(11), VatStatus::Registered, 'Example register, entry C 7531');
$entityManager->persist($accountantFirm);
$firmAccount($accountantFirm);
$accountantSeries = new Series($accountantFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$accountantSeries->markDefault();
$entityManager->persist($accountantSeries);
$entityManager->flush();
$accountantCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Ledger Buyer a.s.', [PartyRole::Customer], 'ledger-buyer@example.com', 16, '12345679', 'CZ12345679'));
$accountantToday = $instanceClock->today();
$lastMonth = $accountantToday->modify('first day of last month');
$accountantDocument = static fn (DateTimeImmutable $date, string $description, string $price, VatRateKind $rate, DocumentKind $kind = DocumentKind::Invoice): Invoice => $issueInvoice->issue(new InvoiceInput($accountantFirm->id(), DocumentKind::Invoice === $kind ? $accountantSeries->id() : null, null, $accountantCustomer->id(), null, $date, DocumentKind::Invoice === $kind ? $date : null, $date->modify('+14 days'), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
    new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, $rate, null, Percent::fromString('0')),
], true, $kind));
$accountantDocument($lastMonth->modify('+9 days'), 'Bookkeeping', '1000.00', VatRateKind::Base);
$accountantDocument($lastMonth->modify('+11 days'), 'Printed manuals', '500.00', VatRateKind::Reduced);
$accountantDocument($lastMonth->modify('+14 days'), 'Annual audit deposit', '1000.00', VatRateKind::Base, DocumentKind::Advance);
$accountantDocument($accountantToday, 'Payroll', '2000.00', VatRateKind::Base);
