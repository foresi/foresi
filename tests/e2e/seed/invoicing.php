<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\InvoicingDefaults;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;

$payerFirm = $firmAccount(new LegalEntity('Example Payer s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(1), VatStatus::Registered, 'Example register, entry C 1234'));
$entityManager->persist($payerFirm);
$entityManager->flush();
$standard = new Series($payerFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$standard->markDefault();
$entityManager->persist($standard);
$entityManager->persist(new Series($payerFirm, 'Second', SeriesFormat::fromString('B{YY}{NNN}'), DocumentKind::Invoice));
// A throwaway password: the e2e mail gateway records and never connects.
$entityManager->persist(FirmMailServer::define($payerFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Payer s.r.o.'));
$entityManager->flush();
$createParty->createFrom($party(PartyKind::Company, 'Example German GmbH', [PartyRole::Customer], 'german@example.com', 7, null, 'DE123456789'));
$createParty->createFrom($party(PartyKind::Company, 'Example Domestic a.s.', [PartyRole::Customer], 'domestic@example.com', 3, '12345679', 'CZ12345679'));
$createParty->createFrom($party(PartyKind::Company, 'Example Builder a.s.', [PartyRole::Customer], 'builder@example.com', 4, '00000019', 'CZ00000019'));
$createParty->createFrom($party(PartyKind::Company, 'Example Euro a.s.', [PartyRole::Customer], 'euro@example.com', 5, '00000001', 'CZ00000001'));
$createParty->createFrom($party(PartyKind::SoleTrader, 'Example Vendor', [PartyRole::Supplier], 'vendor@example.com', 6));
$defaultsOf = static function (string $name, InvoicingDefaults $defaults) use ($entityManager): void {
    $entityManager->getRepository(Party::class)->findOneBy(['displayName' => $name])?->changeInvoicingDefaults($defaults);
};
$defaultsOf('Example Builder a.s.', new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true));
$defaultsOf('Example German GmbH', new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::English, PaymentMethod::BankTransfer, true));
$defaultsOf('Example Euro a.s.', new InvoicingDefaults(14, Currency::EUR, DocumentLanguage::English, PaymentMethod::BankTransfer, false));
$entityManager->flush();
