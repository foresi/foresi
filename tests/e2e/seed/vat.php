<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

// The world of the VAT spec: the instance's one firm, a monthly VAT payer from 12 November 2026 with a
// bank account, and one issued invoice of 9,000 + 21 % on 16 November, so November 2026 gives 1,890
// to pay, due on 28 December 2026. A guest of the customer must not see the page or its menu item.
$vatFirm = new LegalEntity('Example VAT Payer s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(4), VatStatus::Registered, 'Example register, entry C 4321');
$entityManager->persist($vatFirm);
$firmAccount($vatFirm);
$entityManager->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, null));
$entityManager->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new DateTimeImmutable('2026-11-12')));
$vatSeries = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$vatSeries->markDefault();
$entityManager->persist($vatSeries);
$entityManager->flush();
$vatCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Customer a.s.', [PartyRole::Customer], 'billing@example.com', 8, '12345679', 'CZ12345679'));
$vatDay = new DateTimeImmutable('2026-11-16');
$issueInvoice->issue(new InvoiceInput($vatSeries->id(), null, $vatCustomer->id(), null, $vatDay, $vatDay, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [
    new LineInput('Consulting', Quantity::fromString('1'), 'ks', Money::of('9000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
]));
$vatGuest = new User('vat-guest@example.com', $hash, 'Guest Example', 'cs');
$entityManager->persist($vatGuest);
$entityManager->persist(new Membership($vatGuest, Role::Guest, $vatCustomer));
$entityManager->flush();
