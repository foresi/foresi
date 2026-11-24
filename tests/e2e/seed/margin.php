<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
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
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Purchasing\Application\ReceivedDocumentInput;
use Foresi\Purchasing\Application\RecordReceivedDocument;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

// The world of the Margin spec: the instance's one firm, a VAT payer, one client with an invoice of 8,000
// (tax point 2 November 2026) and one cost nobody has claimed, a received document of 1,500 + VAT from
// Cloud Host dated 14 October 2026. In October the client has costs only; in November, revenue only.
$marginFirm = new LegalEntity('Example Margin s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(5), VatStatus::Registered, 'Example register, entry C 5555');
$entityManager->persist($marginFirm);
$firmAccount($marginFirm);
$marginSeries = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$marginSeries->markDefault();
$entityManager->persist($marginSeries);
$entityManager->flush();
$marginClient = $createParty->createFrom($party(PartyKind::Company, 'Client A s.r.o.', [PartyRole::Customer], 'billing@example.com', 8, '12345679', 'CZ12345679'));
$issueInvoice->issue(new InvoiceInput($marginSeries->id(), null, $marginClient->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [
    new LineInput('Consulting', Quantity::fromString('1'), 'ks', Money::of('8000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
]));
$createParty->createFrom($party(PartyKind::Company, 'Cloud Host s.r.o.', [PartyRole::Supplier], 'office@example.com', 2, '12345687', 'CZ12345687'));
$marginOwner = $entityManager->getRepository(User::class)->findOneBy(['email' => 'margin@example.com']) ?? throw new LogicException('The owner of the margin world is missing.');
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($marginOwner->id(), $marginOwner->email(), 'hash'), 'main', ['ROLE_USER']));
$marginSuppliers = [];
foreach ($entityManager->getRepository(Party::class)->findAll() as $candidate) {
    $marginSuppliers[$candidate->displayName()] = (string) $candidate->id();
}
$marginDocument = $container->get('e2e.record_received_document');
\assert($marginDocument instanceof RecordReceivedDocument);
$margin14 = new DateTimeImmutable('2026-10-14');
$marginDocument->create(new ReceivedDocumentInput(
    ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::Domestic, $marginSuppliers['Cloud Host s.r.o.'], 'FV-2026-0414', '2026041400',
    $margin14, $margin14, $margin14->modify('+14 days'), $margin14, Currency::CZK, Money::of('1815.00', Currency::CZK),
    null, null, [new VatRecapLine(VatRate::of('21'), Money::of('1500.00', Currency::CZK), Money::of('315.00', Currency::CZK))],
));
$tokenStorage->setToken(null);
