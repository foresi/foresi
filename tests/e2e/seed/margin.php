<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Contract\DocumentPairings;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\RecurringInput;
use Foresi\Invoicing\Application\SaveRecurringInvoice;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\RecurringDelivery;
use Foresi\Invoicing\Domain\RecurringInvoiceLine;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Invoicing\Domain\TaxPointRule;
use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\RebillLinks;
use Foresi\Margin\Application\SetPlannedAllocationInput;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Purchasing\Application\ReceivedDocumentInput;
use Foresi\Purchasing\Application\RecordReceivedDocument;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

// The world of the Margin spec: the instance's one firm, a VAT payer, one client with an invoice of 8,000
// (tax point 2 November 2026) and one cost nobody has claimed, a received document of 1,500 + VAT from
// Cloud Host dated 14 October 2026. In October the client has costs only; in November, revenue only.
$marginFirm = new LegalEntity('Example Margin s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(5), VatStatus::Registered, 'Example register, entry C 5555');
$entityManager->persist($marginFirm);
$firmAccount($marginFirm);
$marginSeries = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$marginSeries->markDefault();
$entityManager->persist($marginSeries);
$entityManager->flush();
$marginClient = $createParty->createFrom($party(PartyKind::Company, 'Client A s.r.o.', [PartyRole::Customer], 'billing@example.com', 8, '12345679', 'CZ12345679'));
$issueInvoice->issue(new InvoiceInput($marginSeries->id(), null, $marginClient->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [
    new LineInput('Consulting', Quantity::fromString('1'), 'ks', Money::of('8000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
]));
$createParty->createFrom($party(PartyKind::Company, 'Cloud Host s.r.o.', [PartyRole::Supplier], 'office@example.com', 2, '12345687', 'CZ12345687'));
$marginOwner = $entityManager->getRepository(User::class)->findOneBy(['email' => 'margin@example.com']) ?? throw new LogicException('The owner of the margin world is missing.');
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($marginOwner->id(), $marginOwner->email(), 'hash'), 'main', ['ROLE_USER']));
$marginSuppliers = [];
foreach ($entityManager->getRepository(Party::class)->findAll() as $candidate) {
    $marginSuppliers[$candidate->displayName()] = (string) $candidate->id();
}
$marginDocument = $container->get('e2e.record_received_document');
\assert($marginDocument instanceof RecordReceivedDocument);
$margin14 = new DateTimeImmutable('2026-10-14');
$marginDocument->create(new ReceivedDocumentInput(
    ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::Domestic, $marginSuppliers['Cloud Host s.r.o.'], 'FV-2026-0414', '2026041400',
    $margin14, $margin14, $margin14->modify('+14 days'), $margin14, Currency::CZK, Money::of('1815.00', Currency::CZK),
    null, null, [new VatRecapLine(VatRate::of('21'), Money::of('1500.00', Currency::CZK), Money::of('315.00', Currency::CZK))],
));

// A cost Client A bears that was paid and not rebilled: Voice API, planned monthly from 20 November 2026, mapped
// whole to Client A from November, with a received document of 1,000 + VAT dated 20 November paired with its
// occurrence. A recurring invoice to Client A has a line that rebills the cost, linked since 1 November, so the
// occurrence of November is "paid, not rebilled" until an invoice line is paired with it.
$createParty->createFrom($party(PartyKind::Company, 'Voice API s.r.o.', [PartyRole::Supplier], 'voice@example.com', 3, '12345709', 'CZ12345709'));
$marginSuppliers = [];
foreach ($entityManager->getRepository(Party::class)->findAll() as $candidate) {
    $marginSuppliers[$candidate->displayName()] = (string) $candidate->id();
}
$marginPlanned = $container->get('e2e.planned_item_writes');
\assert($marginPlanned instanceof PlannedItemWrites);
$marginCost = $marginPlanned->create(new CreatePlannedItemInput('out', 'Voice API', 'software', '1210.00', '2026-11-20', 'CZK', 'month', 1, false, null, null, $marginSuppliers['Voice API s.r.o.'], true));
$marginMapping = $container->get('e2e.margin_allocation_writes');
\assert($marginMapping instanceof AllocationWrites);
$marginMapping->setPlannedAllocation(new SetPlannedAllocationInput($marginCost->id(), ['kind' => 'whole', 'shares' => [['recipient' => $marginClient->id()]]], '2026-11'));
$margin20 = new DateTimeImmutable('2026-11-20');
$marginVoice = $marginDocument->create(new ReceivedDocumentInput(
    ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::Domestic, $marginSuppliers['Voice API s.r.o.'], 'FV-2026-1120', '2026112000',
    $margin20, $margin20, $margin20->modify('+14 days'), $margin20, Currency::CZK, Money::of('1210.00', Currency::CZK),
    null, null, [new VatRecapLine(VatRate::of('21'), Money::of('1000.00', Currency::CZK), Money::of('210.00', Currency::CZK))],
));
$container->get(DocumentPairings::class)->pair(new FlowSubject('received_document', $marginVoice->document->id()), $marginCost->id(), $margin20);
$marginSave = $container->get('e2e.save_recurring');
\assert($marginSave instanceof SaveRecurringInvoice);
$marginSave->create(new RecurringInput(
    $marginSeries->id(), $marginClient->id(), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', 'Voice API {month/year}', '', '', DocumentLanguage::Czech,
    [new LineInput('Voice API {month/year}', Quantity::fromString('1'), 'ks', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'))],
    new Recurrence(new DateTimeImmutable('2026-12-01'), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null),
    TaxPointRule::IssueDate, 14, RecurringDelivery::Review, 'Example Voice API',
));
$marginLine = $entityManager->getRepository(RecurringInvoiceLine::class)->findOneBy([]) ?? throw new LogicException('The recurring invoice of the margin world has no line.');
$marginLinks = $container->get('e2e.margin_rebill_links');
\assert($marginLinks instanceof RebillLinks);
$marginLinks->set($marginLine->id(), $marginCost->id());
// A link made today would start in the month the seed runs in, so the world fixes it to the first of November.
$entityManager->getConnection()->executeStatement("UPDATE margin_rebill_link SET created_on = '2026-11-01'");
$tokenStorage->setToken(null);
