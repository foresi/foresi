<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Infrastructure;

use Foresi\Banking\Application\MovementInput;
use Foresi\Banking\Application\RecordMovement;
use Foresi\Banking\Application\TrustVerdict;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Domain\MessageTrust;
use Foresi\Banking\Integration\CashFlow\MovementFlows;
use Foresi\CashFlow\Application\BalanceWrites;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\Forecast;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Application\RecordCheckpointInput;
use Foresi\CashFlow\Contract\AccountBalance;
use Foresi\CashFlow\Contract\BalanceCheckpoint;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Infrastructure\Source\PlannedItemFlows;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\InvoiceRow;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\InvoicingAccess;
use Foresi\Invoicing\Application\InvoicingNotAllowed;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\PaymentState;
use Foresi\Invoicing\Application\Query\InvoiceList;
use Foresi\Invoicing\Application\Query\InvoiceListItem;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Application\RecurringAmounts;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInput;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringOverview;
use Foresi\Invoicing\Application\RecurringPage;
use Foresi\Invoicing\Application\SaveRecurringInvoice;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Domain\RecurringDelivery;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Invoicing\Domain\TaxPointRule;
use Foresi\Invoicing\Integration\CashFlow\InvoiceFlows;
use Foresi\Invoicing\Integration\CashFlow\InvoiceReadAccess;
use Foresi\Invoicing\Integration\CashFlow\RecurringInvoiceFlows;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Purchasing\Application\PayReceivedDocument;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\Query\ReceivedDocumentBalances;
use Foresi\Purchasing\Application\Query\ReceivedDocumentQueries;
use Foresi\Purchasing\Application\Query\ReceivedDocumentSliceFilter;
use Foresi\Purchasing\Application\ReceivedDocumentInput;
use Foresi\Purchasing\Application\ReceivedDocumentPayments;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Application\RecordReceivedDocument;
use Foresi\Purchasing\Domain\PaymentMethod as PurchasingPaymentMethod;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Purchasing\Integration\CashFlow\ReceivedDocumentFlows;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\CashFlow\Support\FakeInvoiceQueries;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The forecast from the real modules (C13, C15, C16): issued invoices, a
 * recurring template, received documents and bank movements, read through the
 * container at half past midnight in Prague, while UTC is still a day behind.
 */
final class CashFlowSourcesTest extends CashFlowTestCase
{
    private Series $series;
    private Party $supplier;
    private Party $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm->update('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->series = new Series($this->firm, 'Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->em->persist($this->series);
        $this->em->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->persist(new PartyEmail($this->customer, 'billing@example.com', EmailPurpose::Invoices));
        $this->supplier = $this->supplier('Landlord Example s.r.o.', '11223344');
        $this->otherSupplier = $this->supplier('Supplies Example s.r.o.', '55667788');
        $this->em->flush();
    }

    public function testTheModulesFeedTheForecastAndActualsSuppressThePlan(): void
    {
        // Before midnight: the documents of November.
        Clock::set(new MockClock('2026-11-28 12:00:00', 'UTC'));
        $overdue = $this->invoice('2026-11-16', '2026-11-30', 'Consulting');
        $partlyPaid = $this->invoice('2026-11-20', '2026-12-20', 'Development');
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-25'), Money::of('2100.00', Currency::CZK), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($partlyPaid, Money::of('2100.00', Currency::CZK))]));
        $undated = $this->received($this->otherSupplier, 'FV-11', '2026-11-18', null, '2026-11-20', 500_000);
        $this->checkpoint('2026-11-25', '100000.00');
        $this->movement(MovementDirection::Incoming, '5000.00', '2026-11-27', '123456789/0800', null);

        // 2026-11-30 23:30 UTC is 2026-12-01 00:30 in Prague: December has begun.
        Clock::set(new MockClock('2026-11-30 23:30:00', 'UTC'));
        $rent = $this->plannedItem('Office rent', '20000.00', '2026-12-10', counterpartyId: $this->supplier->id());
        $software = $this->plannedItem('Software', '1000.00', '2026-12-05', matchAccount: '123456789/0710', matchVariableSymbol: '777');
        $this->received($this->supplier, 'FV-12', '2026-12-01', '2026-12-14', '2026-12-01', 2_000_000);
        $this->movement(MovementDirection::Outgoing, '1000.00', '2026-12-01', '123456789/0710', '777');
        $this->recurring('2026-12-15');
        $this->em->clear();

        $forecast = self::getContainer()->get(Forecasts::class)->of($this->firm->id());

        self::assertSame('2026-12-01', $forecast->today->format('Y-m-d'));
        self::assertSame(10_400_000, $forecast->opening->minor());
        self::assertSame('2026-12-01', $forecast->balanceAsOf?->format('Y-m-d'));
        self::assertFalse($forecast->isIncomplete());
        self::assertSame([], $forecast->unavailableSources);
        self::assertSame([], $forecast->restrictedSources);

        $lines = $this->lines($forecast);
        self::assertSame([InvoiceFlows::KEY, '2026-12-01', 1_210_000, ['overdue']], $this->without('subject', $lines[$overdue]));
        self::assertSame([InvoiceFlows::KEY, '2026-12-20', 1_000_000, []], $this->without('subject', $lines[$partlyPaid]));
        self::assertSame([ReceivedDocumentFlows::KEY, '2026-12-01', -605_000, [FlowFlag::NoDueDate->value, FlowFlag::Overdue->value]], $this->without('subject', $lines[$undated]));
        self::assertSame(-2_420_000, $this->byLabel($lines, 'FV-12 Landlord Example s.r.o.')[2]);
        self::assertSame('2026-12-14', $this->byLabel($lines, 'FV-12 Landlord Example s.r.o.')[1]);

        $recurring = array_values(array_filter($lines, static fn (array $line): bool => RecurringInvoiceFlows::KEY === $line[0]));
        self::assertSame(['2026-12-29', 1_210_000], [$recurring[0][1], $recurring[0][2]]);
        self::assertSame('2027-01-29', $recurring[1][1]);

        // The rent of December is billed by the landlord, the software was paid from the bank.
        self::assertSame(['2027-01-10'], $this->daysOf($lines, $rent));
        self::assertSame(['2027-01-05'], $this->daysOf($lines, $software));
    }

    public function testTheBalanceOfAnAccountIsItsCheckpointAndWhatWasBookedAfter(): void
    {
        self::assertNull($this->movementBalance(), 'An account without a movement is not known here.');

        $this->movement(MovementDirection::Incoming, '50.00', '2026-11-05', '123456789/0800', null);
        $known = $this->movementBalance();
        self::assertInstanceOf(AccountBalance::class, $known);
        self::assertSame([100_000, '2026-11-10'], [$known->amount->minor(), $known->asOf->format('Y-m-d')], 'Nothing was booked after the checkpoint: it stands.');

        $this->movement(MovementDirection::Outgoing, '30.00', '2026-11-12', '123456789/0800', null);
        $this->movement(MovementDirection::Incoming, '5.00', '2026-11-14', '123456789/0800', null);
        $after = $this->movementBalance();
        self::assertInstanceOf(AccountBalance::class, $after);
        self::assertSame([97_500, '2026-11-14', 'Europe/Prague'], [$after->amount->minor(), $after->asOf->format('Y-m-d'), $after->asOf->getTimezone()->getName()]);
    }

    public function testTheBankMovementsAreTheActualsWithTheAccountAsTheBankGivesIt(): void
    {
        $this->movement(MovementDirection::Outgoing, '1000.00', '2026-12-01', '123456789/0710', '777', 'CZ5508000000001234567899');
        $this->movement(MovementDirection::Incoming, '20.00', '2026-12-02', null, null, 'CZ5508000000001234567899');
        $zone = new \DateTimeZone('Europe/Prague');

        $actuals = iterator_to_array(self::getContainer()->get(MovementFlows::class)->actuals($this->firm->id(), new \DateTimeImmutable('2026-12-01', $zone), new \DateTimeImmutable('2026-12-31', $zone)), false);

        self::assertCount(2, $actuals);
        self::assertSame([FlowDirection::Out, 100_000, '2026-12-01', '123456789/0710', '777', null], [$actuals[0]->direction, $actuals[0]->amount->minor(), $actuals[0]->on->format('Y-m-d'), $actuals[0]->counterpartyAccount, $actuals[0]->variableSymbol, $actuals[0]->counterpartyId]);
        self::assertSame([FlowDirection::In, 2_000, 'CZ5508000000001234567899', null], [$actuals[1]->direction, $actuals[1]->amount->minor(), $actuals[1]->counterpartyAccount, $actuals[1]->variableSymbol]);
    }

    public function testIssuedInvoicesAreExpectedOnTheirDueDateWhileWhatTheyLeaveToPayIsInTheHorizon(): void
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $item = fn (string $id, DocumentKind $kind, string $payable, string $paid, string $due): InvoiceListItem => new InvoiceListItem(
            new InvoiceRow($id, 'FV-'.$id, 'Example Firm s.r.o.', 'Customer Example s.r.o.', new \DateTimeImmutable('2026-11-10', $zone), new \DateTimeImmutable($due, $zone), Money::of($payable, Currency::CZK), false, false, false, Money::of($paid, Currency::CZK), PaymentState::Unpaid, kind: $kind),
            $this->firm->id(),
            $this->customer->id(),
            null,
            new \DateTimeImmutable('2026-11-10', $zone),
        );
        $queries = new FakeInvoiceQueries([
            [
                $item('last-day', DocumentKind::Invoice, '100.00', '0.00', '2026-12-31'),
                $item('too-late', DocumentKind::Invoice, '100.00', '0.00', '2027-01-01'),
                $item('correction', DocumentKind::CorrectionDocument, '100.00', '0.00', '2026-12-01'),
                $item('settled', DocumentKind::Invoice, '100.00', '100.00', '2026-12-02'),
            ],
            [
                $item('refund', DocumentKind::Invoice, '100.00', '150.00', '2026-12-05'),
                $item('advance', DocumentKind::Advance, '200.00', '50.00', '2026-12-03'),
            ],
        ]);

        $flows = iterator_to_array($this->invoiceFlows($queries)->flows($this->firm->id(), new \DateTimeImmutable('2026-11-17', $zone), new \DateTimeImmutable('2026-12-31', $zone), new \DateTimeImmutable('2026-11-17', $zone)), false);

        self::assertSame(
            [['last-day', FlowDirection::In, 10_000], ['refund', FlowDirection::Out, 5_000], ['advance', FlowDirection::In, 15_000]],
            array_map(static fn (ExpectedFlow $flow): array => [$flow->subject->id, $flow->direction, $flow->amount->minor()], $flows),
        );
        self::assertSame(['2026-12-31', 'FV-last-day Customer Example s.r.o.', $this->customer->id(), 'Europe/Prague'], [$flows[0]->expectedOn->format('Y-m-d'), $flows[0]->label, $flows[0]->counterpartyId, $flows[0]->expectedOn->getTimezone()->getName()]);
        self::assertCount(2, $queries->filters, 'Both slices were read.');
        self::assertSame([$this->firm->id(), 'unpaid'], [$queries->filters[0]->firmId, $queries->filters[0]->state]);
    }

    public function testEveryInvoiceAndAdvanceIssuedInThePeriodIsAnActualWhetherPaidOrNot(): void
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $item = fn (string $id, DocumentKind $kind, string $payable): InvoiceListItem => new InvoiceListItem(
            new InvoiceRow($id, 'FV-'.$id, 'Example Firm s.r.o.', 'Customer Example s.r.o.', new \DateTimeImmutable('2026-11-10', $zone), new \DateTimeImmutable('2026-11-24', $zone), Money::of($payable, Currency::CZK), false, false, false, Money::of('0.00', Currency::CZK), PaymentState::Unpaid, kind: $kind),
            $this->firm->id(),
            $this->customer->id(),
            null,
            new \DateTimeImmutable('2026-11-10', $zone),
        );
        $queries = new FakeInvoiceQueries([
            [$item('first', DocumentKind::Invoice, '100.00'), $item('correction', DocumentKind::CorrectionDocument, '100.00'), $item('nothing', DocumentKind::Invoice, '0.00')],
            [$item('credit', DocumentKind::Invoice, '-30.00'), $item('advance', DocumentKind::Advance, '50.00')],
        ]);
        $from = new \DateTimeImmutable('2026-11-01', $zone);
        $to = new \DateTimeImmutable('2026-11-30', $zone);

        $actuals = iterator_to_array($this->invoiceFlows($queries)->actuals($this->firm->id(), $from, $to), false);

        self::assertSame(
            [['first', FlowDirection::In, 10_000], ['credit', FlowDirection::Out, 3_000], ['advance', FlowDirection::In, 5_000]],
            array_map(static fn (\Foresi\CashFlow\Contract\ActualFlow $actual): array => [$actual->subject->id, $actual->direction, $actual->amount->minor()], $actuals),
        );
        self::assertSame(['2026-11-10', $this->customer->id()], [$actuals[0]->on->format('Y-m-d'), $actuals[0]->counterpartyId]);
        self::assertCount(2, $queries->filters);
        self::assertSame([$this->firm->id(), '2026-11-01', '2026-11-30'], [$queries->filters[0]->firmId, $queries->filters[0]->issuedFrom?->format('Y-m-d'), $queries->filters[0]->issuedTo?->format('Y-m-d')]);
    }

    public function testARecurringTemplateIssuesUntilItsEndAndEveryOccurrenceCountsWhereverItSitsInTheHorizon(): void
    {
        $this->recurring('2026-11-18', 'Daily hosting', RecurrenceUnit::Day, RecurrenceEnd::AfterCount, 100);
        $zone = new \DateTimeZone('Europe/Prague');

        $flows = iterator_to_array(self::getContainer()->get(RecurringInvoiceFlows::class)->flows($this->firm->id(), new \DateTimeImmutable('2026-11-17', $zone), new \DateTimeImmutable('2027-03-31', $zone), new \DateTimeImmutable('2026-11-17', $zone)), false);

        self::assertCount(100, $flows);
        self::assertSame('Daily hosting (2026-11-18)', $flows[0]->label);
        self::assertSame('Daily hosting (2027-02-25)', $flows[99]->label);
        self::assertSame('2027-03-11', $flows[99]->expectedOn->format('Y-m-d'));
        self::assertSame($this->customer->id(), $flows[0]->counterpartyId);
    }

    public function testAnOccurrenceDueOnTheLastDayOfTheHorizonCountsAndTheNextOneDoesNot(): void
    {
        $this->recurring('2026-11-18', 'Daily hosting', RecurrenceUnit::Day);
        $zone = new \DateTimeZone('Europe/Prague');

        $flows = iterator_to_array(self::getContainer()->get(RecurringInvoiceFlows::class)->flows($this->firm->id(), new \DateTimeImmutable('2026-11-17', $zone), new \DateTimeImmutable('2026-12-31', $zone), new \DateTimeImmutable('2026-11-17', $zone)), false);

        self::assertCount(30, $flows);
        self::assertSame(['2026-12-31', 'Daily hosting (2026-12-17)'], [$flows[29]->expectedOn->format('Y-m-d'), $flows[29]->label]);
    }

    public function testATemplateThatAsksForNothingIsNoFlow(): void
    {
        $this->recurring('2026-11-18', 'Free hosting', price: '0.00');
        $zone = new \DateTimeZone('Europe/Prague');

        $flows = iterator_to_array(self::getContainer()->get(RecurringInvoiceFlows::class)->flows($this->firm->id(), new \DateTimeImmutable('2026-11-17', $zone), new \DateTimeImmutable('2027-03-31', $zone), new \DateTimeImmutable('2026-11-17', $zone)), false);

        self::assertSame([], $flows);
    }

    public function testOnlyTheStaffMayAskWhatTheTemplatesWillIssue(): void
    {
        $this->signIn($this->guest);
        $zone = new \DateTimeZone('Europe/Prague');

        $this->expectException(InvoicingNotAllowed::class);

        iterator_to_array(self::getContainer()->get(RecurringInvoiceFlows::class)->flows($this->firm->id(), new \DateTimeImmutable('2026-11-17', $zone), new \DateTimeImmutable('2027-03-31', $zone), new \DateTimeImmutable('2026-11-17', $zone)), false);
    }

    public function testEveryPageOfTheActiveTemplatesIsRead(): void
    {
        $this->recurring('2026-11-18', 'First hosting');
        $this->recurring('2026-11-19', 'Second hosting');
        $this->recurring('2026-11-20', 'Third hosting');
        $container = self::getContainer();
        $all = $container->get(RecurringInvoices::class)->page(new RecurringFilter($this->firm->id(), RecurringState::Active, false, 1))->templates;
        self::assertCount(3, $all);
        $templates = self::createStub(RecurringInvoices::class);
        $templates->method('page')->willReturnCallback(static fn (RecurringFilter $filter): RecurringPage => new RecurringPage([$all[$filter->page - 1]], \count($all), $filter->page, \count($all)));
        $flows = new RecurringInvoiceFlows($container->get(InvoicingAccess::class), $templates, $container->get(RecurringOverview::class), $container->get(RecurringAmounts::class), $container->get(InvoiceReadAccess::class));
        $zone = new \DateTimeZone('Europe/Prague');

        $given = iterator_to_array($flows->flows($this->firm->id(), new \DateTimeImmutable('2026-11-17', $zone), new \DateTimeImmutable('2026-12-31', $zone), new \DateTimeImmutable('2026-11-17', $zone)), false);

        $names = array_unique(array_map(static fn (ExpectedFlow $flow): string => explode(' (', $flow->label)[0], $given));
        sort($names);
        self::assertSame(['First hosting', 'Second hosting', 'Third hosting'], $names);
    }

    public function testReceivedDocumentsAreExpectedOnTheirDueDateOrTheDayTheyCameAndWhatIsPaidIsNot(): void
    {
        $dueLast = $this->received($this->supplier, 'DUE-LAST', '2026-11-01', '2026-12-31', '2026-11-02', 100_000);
        $this->received($this->supplier, 'DUE-AFTER', '2026-11-01', '2027-01-01', '2026-11-02', 100_000);
        $received = $this->received($this->supplier, 'RECEIVED', '2026-11-10', null, '2026-11-12', 100_000);
        $evidence = $this->received($this->otherSupplier, '', '2026-11-08', '2026-12-05', '2026-11-08', 100_000);
        $paid = $this->received($this->supplier, 'PAID', '2026-11-01', '2026-12-10', '2026-11-02', 100_000);
        self::getContainer()->get(PayReceivedDocument::class)->pay($paid, new \DateTimeImmutable('2026-11-16'), Money::ofMinor(121_000, Currency::CZK), PurchasingPaymentMethod::BankTransfer);
        $zone = new \DateTimeZone('Europe/Prague');

        $flows = [];
        foreach ($this->receivedFlows(includePaid: true)->flows($this->firm->id(), new \DateTimeImmutable('2026-11-17', $zone), new \DateTimeImmutable('2026-12-31', $zone), new \DateTimeImmutable('2026-11-17', $zone)) as $flow) {
            $flows[$flow->subject->id] = $flow;
        }

        self::assertEqualsCanonicalizing([$dueLast, $received, $evidence], array_keys($flows), 'Not the one due after the horizon, and not the paid one.');
        self::assertSame(['2026-12-31', 'DUE-LAST Landlord Example s.r.o.', [], FlowDirection::Out, 121_000, $this->supplier->id()], [$flows[$dueLast]->expectedOn->format('Y-m-d'), $flows[$dueLast]->label, $flows[$dueLast]->flags, $flows[$dueLast]->direction, $flows[$dueLast]->amount->minor(), $flows[$dueLast]->counterpartyId]);
        self::assertSame(['2026-11-12', [FlowFlag::NoDueDate]], [$flows[$received]->expectedOn->format('Y-m-d'), $flows[$received]->flags], 'The day it was received comes before the day it was issued.');
        $evidenceNumber = $this->em->find(ReceivedDocument::class, $evidence)?->evidenceNumber();
        self::assertNotNull($evidenceNumber);
        self::assertSame($evidenceNumber.' Supplies Example s.r.o.', $flows[$evidence]->label, 'Without the supplier\'s number, the evidence number names it.');
    }

    public function testEveryRecordedReceivedDocumentIssuedInThePeriodIsAnActualWhetherPaidOrNot(): void
    {
        $first = $this->received($this->supplier, 'FIRST', '2026-11-10', '2026-12-10', '2026-11-11', 100_000);
        $last = $this->received($this->supplier, 'LAST', '2026-11-30', '2026-12-10', '2026-12-02', 100_000);
        $both = $this->received($this->supplier, 'BOTH', '2026-11-12', '2026-12-10', '2026-11-20', 100_000);
        $this->received($this->supplier, 'BEFORE', '2026-11-09', '2026-12-10', '2026-11-11', 100_000);
        $this->received($this->supplier, 'AFTER', '2026-12-01', '2026-12-10', '2026-11-11', 100_000);
        self::getContainer()->get(PayReceivedDocument::class)->pay($first, new \DateTimeImmutable('2026-11-16'), Money::ofMinor(121_000, Currency::CZK), PurchasingPaymentMethod::BankTransfer);
        $zone = new \DateTimeZone('Europe/Prague');

        $actuals = [];
        foreach ($this->receivedFlows()->actuals($this->firm->id(), new \DateTimeImmutable('2026-11-10', $zone), new \DateTimeImmutable('2026-11-30', $zone)) as $actual) {
            $actuals[$actual->subject->id] = $actual;
        }

        self::assertEqualsCanonicalizing([$first, $last, $both], array_keys($actuals));
        self::assertSame(['2026-11-10', 121_000, FlowDirection::Out, $this->supplier->id(), 'Europe/Prague'], [$actuals[$first]->on->format('Y-m-d'), $actuals[$first]->amount->minor(), $actuals[$first]->direction, $actuals[$first]->counterpartyId, $actuals[$first]->on->getTimezone()->getName()], 'The paid document still counts for what it asked.');
        self::assertSame('2026-11-30', $actuals[$last]->on->format('Y-m-d'));
        self::assertSame('2026-11-12', $actuals[$both]->on->format('Y-m-d'), 'The issue day comes before the day it was received.');
    }

    public function testADocumentWhoseSupplierIsGoneStillCountsWithoutAContact(): void
    {
        $id = $this->received($this->supplier, 'ORPHAN', '2026-11-10', '2026-12-10', '2026-11-11', 100_000);
        $table = $this->em->getClassMetadata(ReceivedDocument::class)->getTableName();
        $this->em->getConnection()->executeStatement('UPDATE '.$table.' SET supplier_id = NULL WHERE id = ?', [$id]);
        $this->em->clear();
        $zone = new \DateTimeZone('Europe/Prague');
        $from = new \DateTimeImmutable('2026-11-01', $zone);
        $to = new \DateTimeImmutable('2026-12-31', $zone);
        $flows = $this->receivedFlows();

        $expected = iterator_to_array($flows->flows($this->firm->id(), $from, $to, $from), false);
        $actuals = iterator_to_array($flows->actuals($this->firm->id(), $from, $to), false);

        self::assertCount(1, $expected);
        self::assertNull($expected[0]->counterpartyId);
        self::assertCount(1, $actuals);
        self::assertNull($actuals[0]->counterpartyId);
    }

    public function testTheCallerMayReadTheDocumentsWithoutATokenOrWithTheReadScopeOfThem(): void
    {
        $grant = static fn (string $scope): ApiTokenContext => new readonly class(new ApiTokenGrant('019a0000-0000-7000-8000-000000000001', '019a0000-0000-7000-8000-0000000000aa', 'Reporting', [ApiScope::from($scope)], new \DateTimeImmutable('2026-12-01', new \DateTimeZone('UTC')))) implements ApiTokenContext {
            public function __construct(private ApiTokenGrant $grant)
            {
            }

            public function grant(): ApiTokenGrant
            {
                return $this->grant;
            }

            public function requireScope(ApiScope $scope): ApiTokenGrant
            {
                return $this->grant;
            }
        };
        $none = new class implements ApiTokenContext {
            public function grant(): ?ApiTokenGrant
            {
                return null;
            }

            public function requireScope(ApiScope $scope): ApiTokenGrant
            {
                throw new \LogicException('No token.');
            }
        };

        self::assertTrue($this->receivedFlows(tokens: $none)->mayReadDetails());
        self::assertTrue($this->receivedFlows(tokens: $grant('received_documents:read'))->mayReadDetails());
        self::assertTrue($this->receivedFlows(tokens: $grant('read'))->mayReadDetails());
        self::assertFalse($this->receivedFlows(tokens: $grant('contacts:read'))->mayReadDetails());
        self::assertSame(ReceivedDocumentFlows::KEY, $this->receivedFlows()->key());
    }

    /**
     * The balance the movements give for a checkpoint of 1,000.00 CZK on 2026-11-10, seen on 2026-11-17.
     */
    private function movementBalance(): ?AccountBalance
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $checkpoint = new BalanceCheckpoint($this->account->id(), $this->firm->id(), Money::of('1000.00', Currency::CZK), new \DateTimeImmutable('2026-11-10', $zone));

        return self::getContainer()->get(MovementFlows::class)->balance($checkpoint, new \DateTimeImmutable('2026-11-17', $zone));
    }

    private function invoiceFlows(FakeInvoiceQueries $queries): InvoiceFlows
    {
        $container = self::getContainer();

        return new InvoiceFlows(new InvoiceList($container->get(InvoicingAccess::class), $container->get(InvoiceStore::class), $queries), $container->get(InvoiceReadAccess::class), $container->get(UrlGeneratorInterface::class));
    }

    /**
     * The received documents one at a time, so every page is read; with the paid ones too when asked.
     */
    private function receivedFlows(bool $includePaid = false, ?ApiTokenContext $tokens = null): ReceivedDocumentFlows
    {
        $container = self::getContainer();
        $inner = $container->get(ReceivedDocumentQueries::class);
        $queries = self::createStub(ReceivedDocumentQueries::class);
        $queries->method('slice')->willReturnCallback(static fn (ReceivedDocumentSliceFilter $filter, ?string $after, int $limit): array => $inner->slice(
            $includePaid ? new ReceivedDocumentSliceFilter($filter->firmId, $filter->state, false, $filter->dueBefore, $filter->trashed, $filter->supplierId) : $filter,
            $after,
            1,
        ));
        $balances = new ReceivedDocumentBalances($container->get(PurchasingAccess::class), $container->get(ReceivedDocuments::class), $queries, $container->get(ReceivedDocumentPayments::class));

        return new ReceivedDocumentFlows($balances, $tokens ?? $container->get(ApiTokenContext::class), $container->get(UrlGeneratorInterface::class));
    }

    /**
     * @param array<int|string, array<int|string, mixed>> $lines
     *
     * @return list<mixed> the days the planned item is counted on before February
     */
    private function daysOf(array $lines, string $plannedItemId): array
    {
        $days = [];
        foreach ($lines as $line) {
            if (PlannedItemFlows::KEY === $line[0] && $line['subject'] === $plannedItemId && $line[1] < '2027-02-01') {
                $days[] = $line[1];
            }
        }

        return $days;
    }

    /**
     * Every line of the forecast: source, counted day, signed amount in CZK minor
     * units and flags, keyed by the id of its subject where that is unique.
     *
     * @return array<int|string, array<int|string, mixed>>
     */
    private function lines(Forecast $forecast): array
    {
        $lines = [];
        foreach ($forecast->periods as $period) {
            foreach ($period->lines as $line) {
                $row = [$line->flow->source, $line->countedOn->format('Y-m-d'), $line->signed()->minor(), array_map(static fn (FlowFlag $flag): string => $flag->value, $line->flow->flags), 'subject' => $line->flow->subject->id, 'label' => $line->flow->label];
                if (PlannedItemFlows::KEY === $line->flow->source || RecurringInvoiceFlows::KEY === $line->flow->source) {
                    $lines[] = $row;
                } else {
                    $lines[$line->flow->subject->id] = $row;
                }
            }
        }

        return $lines;
    }

    /**
     * @param array<int|string, mixed> $line
     *
     * @return list<mixed>
     */
    private function without(string $key, array $line): array
    {
        unset($line[$key], $line['label']);

        return array_values($line);
    }

    /**
     * @param array<int|string, array<int|string, mixed>> $lines
     *
     * @return array<int|string, mixed>
     */
    private function byLabel(array $lines, string $label): array
    {
        foreach ($lines as $line) {
            if ($label === $line['label']) {
                return $line;
            }
        }
        self::fail('No line '.$label.'.');
    }

    private function supplier(string $name, string $registrationNumber): Party
    {
        $supplier = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', $registrationNumber), VatId::fromString('CZ'.$registrationNumber), '');
        $this->em->persist($supplier);
        $this->em->persist(new PartyAddress($supplier, AddressType::Billing, new Address('Supplier Street 2', 'Example City', '110 00', 'CZ')));

        return $supplier;
    }

    /**
     * An invoice of 10,000 CZK and 21 % VAT, so 12,100 CZK.
     */
    private function invoice(string $issued, string $due, string $description): string
    {
        $input = new \ReflectionClass(InvoiceInput::class)->newInstanceArgs([
            'firmId' => $this->firm->id(),
            'seriesId' => $this->series->id(),
            'typedNumber' => null,
            'customerId' => $this->customer->id(),
            'customerOnDocument' => null,
            'issueDate' => new \DateTimeImmutable($issued),
            'taxPointDate' => new \DateTimeImmutable($issued),
            'dueDate' => new \DateTimeImmutable($due),
            'paymentMethod' => PaymentMethod::BankTransfer,
            'bankAccount' => self::IBAN,
            'variableSymbol' => '',
            'constantSymbol' => '0308',
            'orderNumber' => '',
            'description' => $description,
            'textBefore' => '',
            'textAfter' => '',
            'language' => DocumentLanguage::Czech,
            'lines' => [$this->line()],
        ]);
        $id = self::getContainer()->get(IssueInvoice::class)->issue($input)->id();
        $this->em->flush();

        return $id;
    }

    private function recurring(string $firstIssueOn, string $name = 'Hosting', RecurrenceUnit $unit = RecurrenceUnit::Month, RecurrenceEnd $end = RecurrenceEnd::Never, ?int $endAfter = null, string $price = '10000.00'): RecurringInvoice
    {
        $input = new \ReflectionClass(RecurringInput::class)->newInstanceArgs([
            'firmId' => $this->firm->id(),
            'seriesId' => $this->series->id(),
            'customerId' => $this->customer->id(),
            'paymentMethod' => PaymentMethod::BankTransfer,
            'bankAccount' => self::IBAN,
            'constantSymbol' => '0308',
            'orderNumber' => '',
            'description' => 'Hosting {month/year}',
            'textBefore' => '',
            'textAfter' => '',
            'language' => DocumentLanguage::Czech,
            'lines' => [$this->line('Hosting {month/year}', $price)],
            'recurrence' => new Recurrence(new \DateTimeImmutable($firstIssueOn), $unit, 1, false, $end, null, $endAfter),
            'taxPoint' => TaxPointRule::IssueDate,
            'dueDays' => 14,
            'delivery' => RecurringDelivery::Send,
            'name' => $name,
        ]);
        $template = self::getContainer()->get(SaveRecurringInvoice::class)->create($input);
        $this->em->flush();

        return $template;
    }

    private function line(string $text = 'Work', string $price = '10000.00'): LineInput
    {
        return new LineInput($text, Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
    }

    /**
     * A recorded domestic tax invoice of the supplier with 21 % VAT on the base.
     */
    private function received(Party $supplier, string $number, string $issued, ?string $due, string $received, int $base): string
    {
        $vat = intdiv($base * 21, 100);
        $saved = self::getContainer()->get(RecordReceivedDocument::class)->create($this->firm->id(), new ReceivedDocumentInput(
            ReceivedDocumentKind::TaxInvoice,
            PurchaseTaxTreatment::Domestic,
            $supplier->id(),
            $number,
            '2026123',
            new \DateTimeImmutable($issued),
            new \DateTimeImmutable($issued),
            null === $due ? null : new \DateTimeImmutable($due),
            new \DateTimeImmutable($received),
            Currency::CZK,
            Money::ofMinor($base + $vat, Currency::CZK),
            null,
            null,
            [new VatRecapLine(VatRate::of('21'), Money::ofMinor($base, Currency::CZK), Money::ofMinor($vat, Currency::CZK))],
        ));
        $this->em->flush();

        return $saved->document->id();
    }

    private function checkpoint(string $on, string $amount): void
    {
        self::getContainer()->get(BalanceWrites::class)->checkpoint(new RecordCheckpointInput($this->account->id(), $on, $amount));
    }

    private function movement(MovementDirection $direction, string $amount, string $bookedOn, ?string $counterAccount, ?string $variableSymbol, ?string $counterIban = null): void
    {
        self::getContainer()->get(RecordMovement::class)->record(new MovementInput(
            $this->account->id(),
            null,
            $direction,
            Money::of($amount, Currency::CZK),
            new \DateTimeImmutable($bookedOn),
            $counterAccount,
            $counterIban,
            'Example Counterparty',
            $variableSymbol,
            null,
            null,
            'Example payment',
            null,
        ), new TrustVerdict(MessageTrust::Verified, TrustVerdict::DMARC));
    }

    private function plannedItem(string $name, string $amount, string $on, ?string $counterpartyId = null, ?string $matchAccount = null, ?string $matchVariableSymbol = null): string
    {
        return $this->writes()->create(new CreatePlannedItemInput(
            $this->firm->id(),
            'out',
            $name,
            'other',
            $amount,
            $on,
            repeat: 'month',
            counterpartyId: $counterpartyId,
            matchAccount: $matchAccount,
            matchVariableSymbol: $matchVariableSymbol,
        ))->id();
    }
}
