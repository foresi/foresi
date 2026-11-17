<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\CashFlow\Infrastructure;

use App\Banking\Application\MovementInput;
use App\Banking\Application\RecordMovement;
use App\Banking\Application\TrustVerdict;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\CashFlow\Application\BalanceWrites;
use App\CashFlow\Application\CreatePlannedItemInput;
use App\CashFlow\Application\Forecast;
use App\CashFlow\Application\Forecasts;
use App\CashFlow\Application\RecordCheckpointInput;
use App\CashFlow\Contract\FlowFlag;
use App\CashFlow\Infrastructure\Source\PlannedItemFlows;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Integration\CashFlow\InvoiceFlows;
use App\Invoicing\Integration\CashFlow\RecurringInvoiceFlows;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Purchasing\Application\ReceivedDocumentInput;
use App\Purchasing\Application\RecordReceivedDocument;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\VatRate;
use App\Purchasing\Domain\VatRecapLine;
use App\Purchasing\Integration\CashFlow\ReceivedDocumentFlows;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Domain\Schedule\Recurrence;
use App\Shared\Domain\Schedule\RecurrenceEnd;
use App\Shared\Domain\Schedule\RecurrenceUnit;
use App\Tests\CashFlow\Support\CashFlowTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

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

    private function recurring(string $firstIssueOn): void
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
            'lines' => [$this->line('Hosting {month/year}')],
            'recurrence' => new Recurrence(new \DateTimeImmutable($firstIssueOn), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null),
            'taxPoint' => TaxPointRule::IssueDate,
            'dueDays' => 14,
            'delivery' => RecurringDelivery::Send,
            'name' => 'Hosting',
        ]);
        self::getContainer()->get(SaveRecurringInvoice::class)->create($input);
        $this->em->flush();
    }

    private function line(string $text = 'Work'): LineInput
    {
        return new LineInput($text, Quantity::fromString('1'), 'h', Money::of('10000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
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

    private function movement(MovementDirection $direction, string $amount, string $bookedOn, string $counterAccount, ?string $variableSymbol): void
    {
        self::getContainer()->get(RecordMovement::class)->record(new MovementInput(
            $this->account->id(),
            null,
            $direction,
            Money::of($amount, Currency::CZK),
            new \DateTimeImmutable($bookedOn),
            $counterAccount,
            null,
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
