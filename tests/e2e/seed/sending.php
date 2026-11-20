<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\SendRequest;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\Invoice;
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
use Foresi\Tests\Support\Mail\RecordingMailGateway;

// The world of the send dialog specs: a payer firm with a mail server and a customer with an
// address for invoices and one for a copy. Its invoices are issued here, so the specs of the
// other worlds see nothing of them.
$sendingFirm = new LegalEntity('Example Sending s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(1), VatStatus::Registered, 'Example register, entry C 9012');
$entityManager->persist($sendingFirm);
$firmAccount($sendingFirm);
$sendingSeries = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$sendingSeries->markDefault();
$entityManager->persist($sendingSeries);
$entityManager->persist(FirmMailServer::define('smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Sending s.r.o.'));
$entityManager->flush();
$recipient = $createParty->createFrom($party(PartyKind::Company, 'Example Recipient a.s.', [PartyRole::Customer], '', 8, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($recipient, 'billing@example.com', EmailPurpose::Invoices));
$entityManager->persist(new PartyEmail($recipient, 'accounts@example.com', EmailPurpose::Copy));
$entityManager->flush();
$sendingInvoice = static fn (string $description, string $price): Invoice => $issueInvoice->issue(new InvoiceInput($sendingSeries->id(), null, $recipient->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
    new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
]));
$sendingInvoice('Annual support', '24000.00');
// Delivered at once by the synchronous e2e queue: one refused by the recipient's server,
// one sent and opened, so the list, the log and the notice have each state to show.
$sendingText = static fn (Invoice $invoice): array => ['Faktura '.$invoice->number(), "Dobrý den,\nposíláme fakturu ".$invoice->number().".\n\n{link}"];
$hosting = $sendingInvoice('Hosting', '3600.00');
$failedSend = $sendInvoice->send(new SendRequest($hosting->id(), [RecordingMailGateway::REFUSING_RECIPIENT], [], false, ...$sendingText($hosting)));
$renewal = $sendingInvoice('Domain renewal', '450.00');
$readSend = $sendInvoice->send(new SendRequest($renewal->id(), ['billing@example.com'], ['accounts@example.com'], true, ...$sendingText($renewal)));
$entityManager->getConnection()->executeStatement('UPDATE invoice_send SET first_read_at = created_at, read_count = 1 WHERE id = ?', [$readSend->id()]);
