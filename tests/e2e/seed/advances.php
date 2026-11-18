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
use App\Invoicing\Domain\TaxOnReceiptChoice;
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

// The world of the advances spec: a payer firm and one customer, an advance Z920260001 of
// 1 210 Kč the spec pays, and an advance Z920260002 paid before its supply was known, so it
// waits for its tax document; the spec issues it from the list.
$advancesFirm = new LegalEntity('Example Advances s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(9), VatStatus::Registered, 'Example register, entry C 9753');
$entityManager->persist($advancesFirm);
$firmAccount($advancesFirm);
$entityManager->flush();
$advancesCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Advance Buyer a.s.', [PartyRole::Customer], 'buyer@example.com', 14, '12345679', 'CZ12345679'));
$advanceOf = static fn (string $description, ?TaxOnReceiptChoice $choice): Invoice => $issueInvoice->issue(new InvoiceInput($advancesFirm->id(), null, null, $advancesCustomer->id(), null, $day, null, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
    new LineInput($description, Quantity::fromString('1'), 'ks', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
], true, DocumentKind::Advance, $choice));
$advanceOf('First milestone', null);
$waiting = $advanceOf('Second milestone', TaxOnReceiptChoice::Skip);
$money = Money::of('1210.00', Currency::CZK);
// Paid today, not on the fixed seed day: the tax document is due within 15 days of the payment, so
// the notice the spec reads says "waits", never "is late", whenever the suite runs.
$recordPayment->record(new PaymentRequest($advancesFirm->id(), new DateTimeImmutable('today'), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($waiting->id(), $money)]));
// The supply is known now: by the rules VAT arose on the payment, which has no tax document yet.
$entityManager->getConnection()->executeStatement('UPDATE invoice SET tax_on_receipt = NULL WHERE id = ?', [$waiting->id()]);
