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
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

// The world of the dialog specs: a firm with a mail server and a customer, and three
// invoices to send, to move to the trash and to mark delivered in dialogs.
$dialogFirm = new LegalEntity('Example Dialogs s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(3), VatStatus::Registered, 'Example register, entry C 3456');
$entityManager->persist($dialogFirm);
$firmAccount($dialogFirm);
$dialogSeries = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$dialogSeries->markDefault();
$entityManager->persist($dialogSeries);
$entityManager->persist(FirmMailServer::define('smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Dialogs s.r.o.'));
$entityManager->flush();
$buyer = $createParty->createFrom($party(PartyKind::Company, 'Example Buyer a.s.', [PartyRole::Customer], '', 9, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($buyer, 'buyer@example.com', EmailPurpose::Invoices));
$entityManager->flush();
foreach (['Support' => '1000.00', 'Hosting' => '2000.00', 'Licence' => '3000.00'] as $description => $price) {
    $issueInvoice->issue(new InvoiceInput($dialogSeries->id(), null, $buyer->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
}
