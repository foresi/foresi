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
use Foresi\Invoicing\Domain\FirmReminders;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\SendPurpose;
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

// The world of the reminder specs, dated from the day the seed runs so its invoices stay
// overdue. "Example Reminding" reminds automatically since two months ago: R0001 has had two
// reminders today, R0002 gets its first tomorrow, R0003 is not due yet.
$today = new DateTimeImmutable('today');
$remindingFirm = $firmAccount(new LegalEntity('Example Reminding s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(4), VatStatus::Registered, 'Example register, entry C 7890'));
$entityManager->persist($remindingFirm);
$remindingSeries = new Series($remindingFirm, Series::STANDARD, SeriesFormat::fromString('R{NNNN}'), DocumentKind::Invoice);
$remindingSeries->markDefault();
$entityManager->persist($remindingSeries);
$entityManager->persist(FirmMailServer::define($remindingFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Reminding s.r.o.'));
$entityManager->flush();
$automatic = FirmReminders::standard($remindingFirm);
$automatic->change(true, 7, 7, null, $today->modify('-60 days'));
$entityManager->persist($automatic);
$entityManager->flush();
$debtor = $createParty->createFrom($party(PartyKind::Company, 'Example Debtor a.s.', [PartyRole::Customer], '', 10, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($debtor, 'billing@example.com', EmailPurpose::Invoices));
$entityManager->flush();
// Each one sent, which the synchronous e2e queue delivers at once. Sends are dated now, so a
// history reads in order: the invoice, its e-mail, then its reminders.
$reminded = static function (LegalEntity $firm, Series $series, string $description, string $price, int $issuedDaysAgo, int $dueDaysAgo) use ($issueInvoice, $sendInvoice, $debtor, $today): Invoice {
    $issued = $today->modify(sprintf('-%d days', $issuedDaysAgo));
    $invoice = $issueInvoice->issue(new InvoiceInput($firm->id(), $series->id(), null, $debtor->id(), null, $issued, $issued, $today->modify(sprintf('%+d days', -$dueDaysAgo)), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
    $sendInvoice->send(new SendRequest($invoice->id(), ['billing@example.com'], [], false, 'Faktura '.$invoice->number(), "Dobrý den,\nposíláme fakturu ".$invoice->number().".\n\n{link}"));

    return $invoice;
};
$twiceReminded = $reminded($remindingFirm, $remindingSeries, 'Server care', '12000.00', 45, 31);
foreach ([1, 2] as $step) {
    $sendInvoice->send(new SendRequest($twiceReminded->id(), ['billing@example.com'], [], false, 'Upomínka: faktura '.$twiceReminded->number(), "Dobrý den,\nfaktura ".$twiceReminded->number()." zatím není uhrazená.\n\n{link}", SendPurpose::Reminder, $step));
}
$reminded($remindingFirm, $remindingSeries, 'Backups', '2400.00', 20, 6);
$reminded($remindingFirm, $remindingSeries, 'Monitoring', '900.00', 4, -10);
