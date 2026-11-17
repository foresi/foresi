<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\Invoicing\Application\IssueRecurringOccurrence;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\RecurringLifecycle;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxPointRule;
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
use App\Shared\Domain\Schedule\Recurrence;
use App\Shared\Domain\Schedule\RecurrenceEnd;
use App\Shared\Domain\Schedule\RecurrenceUnit;

// The world of the recurring list specs: a firm with a mail server and templates in each
// state, one failing, and an invoice one of them issued that waits to be sent. Dates count
// from the real today: a template cannot start in the past.
$recurringFirm = $firmAccount(new LegalEntity('Example Recurring s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(4), VatStatus::Registered, 'Example register, entry C 7890'));
$entityManager->persist($recurringFirm);
$recurringSeries = new Series($recurringFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$recurringSeries->markDefault();
$entityManager->persist($recurringSeries);
$entityManager->persist(FirmMailServer::define($recurringFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Recurring s.r.o.'));
$entityManager->flush();
$client = $createParty->createFrom($party(PartyKind::Company, 'Example Client a.s.', [PartyRole::Customer], '', 10, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($client, 'client@example.com', EmailPurpose::Invoices));
$entityManager->flush();
$saveRecurring = $container->get('e2e.save_recurring');
$recurringLifecycle = $container->get('e2e.recurring_lifecycle');
$issueRecurring = $container->get('e2e.issue_recurring');
\assert($saveRecurring instanceof SaveRecurringInvoice);
\assert($recurringLifecycle instanceof RecurringLifecycle);
\assert($issueRecurring instanceof IssueRecurringOccurrence);
// The firm's today, not the server's: after midnight in the firm's zone they differ.
$today = $instanceClock->today();
$firstOfNextMonth = $today->modify('first day of next month');
$recurring = static fn (string $description, string $price, Recurrence $recurrence, RecurringDelivery $delivery = RecurringDelivery::Send): RecurringInvoice => $saveRecurring->create(new RecurringInput(
    $recurringFirm->id(),
    $recurringSeries->id(),
    $client->id(),
    PaymentMethod::BankTransfer,
    'CZ6508000000192000145399',
    '',
    '',
    $description,
    '',
    '',
    DocumentLanguage::Czech,
    [new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'))],
    $recurrence,
    TaxPointRule::IssueDate,
    14,
    $delivery,
    'Example '.str_replace(' {month/year}', '', $description),
));
$every = static fn (RecurrenceUnit $unit, int $every = 1, ?DateTimeImmutable $first = null, RecurrenceEnd $end = RecurrenceEnd::Never, ?int $endAfter = null): Recurrence => new Recurrence($first ?? $firstOfNextMonth, $unit, $every, false, $end, null, $endAfter);
$recurring('Hosting {month/year}', '1200.00', $every(RecurrenceUnit::Month));
$recurring('Domain renewal', '450.00', $every(RecurrenceUnit::Year), RecurringDelivery::Review);
$recurring('Support licence', '24000.00', $every(RecurrenceUnit::Month, 12, end: RecurrenceEnd::AfterCount, endAfter: 3));
$recurringLifecycle->pause($recurring('Backup storage', '300.00', $every(RecurrenceUnit::Month))->id());
$recurringLifecycle->end($recurring('Old maintenance', '900.00', $every(RecurrenceUnit::Month))->id());
$failing = $recurring('Monitoring', '600.00', $every(RecurrenceUnit::Month));
// An issue refused for want of an address fails until a run goes clean; a refused send would end once its invoice is sent.
$entityManager->getConnection()->executeStatement("UPDATE recurring_invoice SET last_failure_code = 'invoice.problem.customer_address_incomplete', last_failure_at = CURRENT_TIMESTAMP WHERE id = ?", [$failing->id()]);
// Issued today for review, so it waits under "To send".
$held = $recurring('Consulting {month/year}', '5000.00', $every(RecurrenceUnit::Month, first: $today), RecurringDelivery::Review);
$issueRecurring->issue($held->id(), $today, $today);
