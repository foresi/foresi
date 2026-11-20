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

// The world of the reminder specs for a firm with reminders off, dated from the day the seed runs so
// its invoices stay overdue: S0001 is overdue, for a reminder by hand, and S0002 is due in five days.
$today = new DateTimeImmutable('today');
$manualFirm = $firmAccount(new LegalEntity('Example Manual s.r.o.', new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), $address(4), VatStatus::Registered, 'Example register, entry C 7890'));
$entityManager->persist($manualFirm);
$manualSeries = new Series(Series::STANDARD, SeriesFormat::fromString('S{NNNN}'), DocumentKind::Invoice);
$manualSeries->markDefault();
$entityManager->persist($manualSeries);
$entityManager->persist(FirmMailServer::define('smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Manual s.r.o.'));
$entityManager->flush();
$debtor = $createParty->createFrom($party(PartyKind::Company, 'Example Debtor a.s.', [PartyRole::Customer], '', 10, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($debtor, 'billing@example.com', EmailPurpose::Invoices));
$entityManager->flush();
// Each one sent, which the synchronous e2e queue delivers at once.
$reminded = static function (string $description, string $price, int $issuedDaysAgo, int $dueDaysAgo) use ($issueInvoice, $sendInvoice, $debtor, $today, $manualSeries): Invoice {
    $issued = $today->modify(sprintf('-%d days', $issuedDaysAgo));
    $invoice = $issueInvoice->issue(new InvoiceInput($manualSeries->id(), null, $debtor->id(), null, $issued, $issued, $today->modify(sprintf('%+d days', -$dueDaysAgo)), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
    $sendInvoice->send(new SendRequest($invoice->id(), ['billing@example.com'], [], false, 'Faktura '.$invoice->number(), "Dobrý den,\nposíláme fakturu ".$invoice->number().".\n\n{link}"));

    return $invoice;
};
$reminded('Training', '5000.00', 30, 16);
$reminded('Consulting', '7500.00', 9, -5);
