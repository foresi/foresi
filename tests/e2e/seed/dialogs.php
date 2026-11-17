<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

// The tenant of the dialog specs: a firm with a mail server and a customer, and three
// invoices to send, to move to the trash and to mark delivered in dialogs.
$dialogFirm = new LegalEntity('Example Dialogs s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(3), VatStatus::Registered, 'Example register, entry C 3456');
$entityManager->persist($dialogFirm);
$firmAccount($dialogFirm);
$dialogSeries = new Series($dialogFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$dialogSeries->markDefault();
$entityManager->persist($dialogSeries);
$entityManager->persist(FirmMailServer::define($dialogFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Dialogs s.r.o.'));
$entityManager->flush();
$buyer = $createParty->createFrom($party(PartyKind::Company, 'Example Buyer a.s.', [PartyRole::Customer], '', 9, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($buyer, 'buyer@example.com', EmailPurpose::Invoices));
$entityManager->flush();
foreach (['Support' => '1000.00', 'Hosting' => '2000.00', 'Licence' => '3000.00'] as $description => $price) {
    $issueInvoice->issue(new InvoiceInput($dialogFirm->id(), $dialogSeries->id(), null, $buyer->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
}
