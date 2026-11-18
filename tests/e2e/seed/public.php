<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\SendRequest;
use Foresi\Invoicing\Domain\DocumentKind;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\LinkAudience;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\Percent;
use Foresi\Invoicing\Domain\PriceType;
use Foresi\Invoicing\Domain\Quantity;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Invoicing\Domain\TaxTreatment;
use Foresi\Invoicing\Domain\VatRateKind;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

// A sent invoice of an organisation nobody signs in to, for the public link page. Its links
// are derived from the e2e secret; the specs read them from var/e2e-links.json.
$senderFirm = new LegalEntity('Example Studio s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(1), VatStatus::Registered, 'Example register, entry C 5678');
$entityManager->persist($senderFirm);
$firmAccount($senderFirm);
$senderSeries = new Series($senderFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$senderSeries->markDefault();
$entityManager->persist($senderSeries);
$entityManager->persist(FirmMailServer::define($senderFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'studio@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'studio@example.com', 'Example Studio s.r.o.'));
$entityManager->flush();
$senderCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Client a.s.', [PartyRole::Customer], 'billing@example.com', 2, '12345679', 'CZ12345679'));
$senderInvoice = $issueInvoice->issue(new InvoiceInput($senderFirm->id(), $senderSeries->id(), null, $senderCustomer->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '20260001', '', '', 'Website redesign', '', '', DocumentLanguage::Czech, [
    new LineInput('Website redesign', Quantity::fromString('1'), 'ks', Money::of('12500.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
]));
// Delivered at once: the mail queue of the e2e environment is synchronous.
$senderSend = $sendInvoice->send(new SendRequest($senderInvoice->id(), ['billing@example.com'], [], true, 'Faktura '.$senderInvoice->number(), "Dobrý den,\nposíláme fakturu ".$senderInvoice->number().".\n\n{link}"));
file_put_contents(dirname(__DIR__, 3).'/var/e2e-links.json', json_encode([
    'customer' => $linkTokens->token($senderSend->id(), LinkAudience::Customer),
    'copy' => $linkTokens->token($senderSend->id(), LinkAudience::Copy),
    'number' => $senderInvoice->number(),
    'due' => $due->format('j. n. Y'),
], \JSON_THROW_ON_ERROR));
