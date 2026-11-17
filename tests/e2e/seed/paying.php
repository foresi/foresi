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

// The tenant of the pay dialog spec: three invoices of one firm, one unpaid to pay in the
// dialog, one paid in part and one paid in full, so the list shows each state.
$payingFirm = new LegalEntity('Example Paying s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(4), VatStatus::Registered, 'Example register, entry C 7890');
$entityManager->persist($payingFirm);
$firmAccount($payingFirm);
$payingSeries = new Series($payingFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$payingSeries->markDefault();
$entityManager->persist($payingSeries);
$entityManager->flush();
$payer = $createParty->createFrom($party(PartyKind::Company, 'Example Payer a.s.', [PartyRole::Customer], 'payer@example.com', 10, '12345679', 'CZ12345679'));
$payable = [];
foreach (['Consulting' => '1000.00', 'Workshop' => '2000.00', 'Audit' => '3000.00'] as $description => $price) {
    $payable[$description] = $issueInvoice->issue(new InvoiceInput($payingFirm->id(), $payingSeries->id(), null, $payer->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
}
foreach (['Workshop' => '1000.00', 'Audit' => '3630.00'] as $description => $amount) {
    $money = Money::of($amount, Currency::CZK);
    $recordPayment->record(new PaymentRequest($payingFirm->id(), $day, $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($payable[$description]->id(), $money)]));
}
