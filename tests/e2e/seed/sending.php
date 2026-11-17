<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\SendRequest;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
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
use App\Tests\Support\Mail\RecordingMailGateway;

// The tenant of the send dialog specs: a payer firm with a mail server and one without,
// and a customer with an address for invoices and one for a copy. Its invoices are
// issued here, so the specs of the other tenants see nothing of them.
$sendingFirm = new LegalEntity('Example Sending s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(1), VatStatus::Registered, 'Example register, entry C 9012');
$entityManager->persist($sendingFirm);
$firmAccount($sendingFirm);
$sendingSeries = new Series($sendingFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$sendingSeries->markDefault();
$entityManager->persist($sendingSeries);
$entityManager->persist(FirmMailServer::define($sendingFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Sending s.r.o.'));
$quietFirm = new LegalEntity('Example Quiet s.r.o.', new RegistrationNumber('CZ', '12345687'), null, $address(2), VatStatus::NotRegistered, 'Example trade register');
$entityManager->persist($quietFirm);
$firmAccount($quietFirm);
$quietSeries = new Series($quietFirm, Series::STANDARD, SeriesFormat::fromString('Q{YYYY}{NNNN}'), DocumentKind::Invoice);
$quietSeries->markDefault();
$entityManager->persist($quietSeries);
$entityManager->flush();
$recipient = $createParty->createFrom($party(PartyKind::Company, 'Example Recipient a.s.', [PartyRole::Customer], '', 8, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($recipient, 'billing@example.com', EmailPurpose::Invoices));
$entityManager->persist(new PartyEmail($recipient, 'accounts@example.com', EmailPurpose::Copy));
$entityManager->flush();
$sendingInvoice = static fn (LegalEntity $firm, Series $series, string $description, string $price): Invoice => $issueInvoice->issue(new InvoiceInput($firm->id(), $series->id(), null, $recipient->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
    new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
]));
$sendingInvoice($sendingFirm, $sendingSeries, 'Annual support', '24000.00');
$sendingInvoice($quietFirm, $quietSeries, 'Logo design', '8000.00');
// Delivered at once by the synchronous e2e queue: one refused by the recipient's server,
// one sent and opened, so the list, the log and the notice have each state to show.
$sendingText = static fn (Invoice $invoice): array => ['Faktura '.$invoice->number(), "Dobrý den,\nposíláme fakturu ".$invoice->number().".\n\n{link}"];
$hosting = $sendingInvoice($sendingFirm, $sendingSeries, 'Hosting', '3600.00');
$failedSend = $sendInvoice->send(new SendRequest($hosting->id(), [RecordingMailGateway::REFUSING_RECIPIENT], [], false, ...$sendingText($hosting)));
$renewal = $sendingInvoice($sendingFirm, $sendingSeries, 'Domain renewal', '450.00');
$readSend = $sendInvoice->send(new SendRequest($renewal->id(), ['billing@example.com'], ['accounts@example.com'], true, ...$sendingText($renewal)));
$entityManager->getConnection()->executeStatement('UPDATE invoice_send SET first_read_at = created_at, read_count = 1 WHERE id = ?', [$readSend->id()]);
