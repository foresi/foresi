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
use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\SendPurpose;
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

// The tenant of the reminder specs, dated from the day the seed runs so its invoices stay
// overdue. "Example Reminding" reminds automatically since two months ago: R0001 has had two
// reminders today, R0002 gets its first tomorrow, R0003 is not due yet. "Example Manual" has
// reminders off: S0001 is overdue, for a reminder by hand, and S0002 is due in five days,
// so the settings page shows when its first reminder would go out.
$today = new DateTimeImmutable('today');
$reminderFirm = static function (string $name, string $number, string $prefix) use ($entityManager, $address, $mailSecrets, $firmAccount): array {
    $firm = $firmAccount(new LegalEntity($name, new RegistrationNumber('CZ', $number), VatId::fromString('CZ'.$number), $address(4), VatStatus::Registered, 'Example register, entry C 7890'));
    $entityManager->persist($firm);
    $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString($prefix.'{NNNN}'), DocumentKind::Invoice);
    $series->markDefault();
    $entityManager->persist($series);
    $entityManager->persist(FirmMailServer::define($firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', $name));
    $entityManager->flush();

    return [$firm, $series];
};
[$remindingFirm, $remindingSeries] = $reminderFirm('Example Reminding s.r.o.', '12345695', 'R');
[$manualFirm, $manualSeries] = $reminderFirm('Example Manual s.r.o.', '12345687', 'S');
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
$reminded($manualFirm, $manualSeries, 'Training', '5000.00', 30, 16);
$reminded($manualFirm, $manualSeries, 'Consulting', '7500.00', 9, -5);
