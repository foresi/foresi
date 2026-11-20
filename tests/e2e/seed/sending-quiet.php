<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

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
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

// The world of the send dialog of a firm without a mail server: the instance's one firm, a non-payer
// with a bank account and no mail server, and one invoice to send.
$quietFirm = new LegalEntity('Example Quiet s.r.o.', new RegistrationNumber('CZ', '12345687'), null, $address(2), VatStatus::NotRegistered, 'Example trade register');
$entityManager->persist($quietFirm);
$firmAccount($quietFirm);
$quietSeries = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$quietSeries->markDefault();
$entityManager->persist($quietSeries);
$entityManager->flush();
$recipient = $createParty->createFrom($party(PartyKind::Company, 'Example Recipient a.s.', [PartyRole::Customer], 'billing@example.com', 8, '12345679', 'CZ12345679'));
$issueInvoice->issue(new InvoiceInput($quietSeries->id(), null, $recipient->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', 'Logo design', '', '', DocumentLanguage::Czech, [
    new LineInput('Logo design', Quantity::fromString('1'), 'ks', Money::of('8000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
]));
