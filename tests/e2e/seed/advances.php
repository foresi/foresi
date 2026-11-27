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
use Foresi\Invoicing\Domain\TaxOnReceiptChoice;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

// The world of the advances spec: a payer firm and one customer, an advance Z920260001 of
// 1 210 Kč the spec pays, and an advance Z920260002 paid before its supply was known, so it
// waits for its tax document; the spec issues it from the list.
$advancesFirm = new LegalEntity('Example Advances s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(9), VatStatus::Registered, 'Example register, entry C 9753');
$entityManager->persist($advancesFirm);
$firmAccount($advancesFirm);
$entityManager->flush();
$advancesCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Advance Buyer a.s.', [PartyRole::Customer], 'buyer@example.com', 14, '12345679', 'CZ12345679'));
$advanceOf = static fn (string $description, ?TaxOnReceiptChoice $choice): Invoice => $issueInvoice->issue(new InvoiceInput(null, null, $advancesCustomer->id(), null, $day, null, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
    new LineInput($description, Quantity::fromString('1'), 'ks', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
], true, DocumentKind::Advance, $choice));
$advanceOf('First milestone', null);
$waiting = $advanceOf('Second milestone', TaxOnReceiptChoice::Skip);
$money = Money::of('1210.00', Currency::CZK);
// Paid today, not on the fixed seed day: the tax document is due within 15 days of the payment, so
// the notice the spec reads says "waits", never "is late", whenever the suite runs.
$recordPayment->record(new PaymentRequest(new DateTimeImmutable('today'), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($waiting->id(), $money)]));
// The supply is known now: by the rules VAT arose on the payment, which has no tax document yet.
$entityManager->getConnection()->executeStatement('UPDATE invoice SET tax_on_receipt = NULL WHERE id = ?', [$waiting->id()]);
