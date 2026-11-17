<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PaymentSource;
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

// The tenant of the corrections spec: a payer firm and one customer, an unpaid invoice
// 20260001 of 1 210 Kč the spec credits in part, and an invoice 20260002 of 1 210 Kč paid in
// full, which the spec credits in full and refunds by hand.
$correctionsFirm = new LegalEntity('Example Corrections s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(10), VatStatus::Registered, 'Example register, entry C 8642');
$entityManager->persist($correctionsFirm);
$firmAccount($correctionsFirm);
$correctionsSeries = new Series($correctionsFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$correctionsSeries->markDefault();
$entityManager->persist($correctionsSeries);
$entityManager->flush();
$correctionsCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Credit Buyer a.s.', [PartyRole::Customer], 'credit-buyer@example.com', 15, '12345679', 'CZ12345679'));
$correctable = [];
foreach (['Consulting', 'Workshop'] as $description) {
    $correctable[$description] = $issueInvoice->issue(new InvoiceInput($correctionsFirm->id(), $correctionsSeries->id(), null, $correctionsCustomer->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
}
$money = Money::of('1210.00', Currency::CZK);
$recordPayment->record(new PaymentRequest($correctionsFirm->id(), $day, $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($correctable['Workshop']->id(), $money)]));
