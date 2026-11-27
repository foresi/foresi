<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

// The world of the corrections spec: a payer firm and one customer, an unpaid invoice
// 20260001 of 1 210 Kč the spec credits in part, and an invoice 20260002 of 1 210 Kč paid in
// full, which the spec credits in full and refunds by hand.
$correctionsFirm = new LegalEntity('Example Corrections s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(10), VatStatus::Registered, 'Example register, entry C 8642');
$entityManager->persist($correctionsFirm);
$firmAccount($correctionsFirm);
$correctionsSeries = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$correctionsSeries->markDefault();
$entityManager->persist($correctionsSeries);
$entityManager->flush();
$correctionsCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Credit Buyer a.s.', [PartyRole::Customer], 'credit-buyer@example.com', 15, '12345679', 'CZ12345679'));
$correctable = [];
foreach (['Consulting', 'Workshop'] as $description) {
    $correctable[$description] = $issueInvoice->issue(new InvoiceInput($correctionsSeries->id(), null, $correctionsCustomer->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
}
$money = Money::of('1210.00', Currency::CZK);
$recordPayment->record(new PaymentRequest($day, $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($correctable['Workshop']->id(), $money)]));
