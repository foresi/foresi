<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Integration\Country;

use Foresi\Invoicing\Application\BilledWork;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\BilledLine;
use Foresi\Invoicing\Contract\BilledWorkDraft;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceLine;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Work\Application\WorkTestCase;
use Foresi\Timesheet\Application\ManageTime;
use Foresi\Timesheet\Application\ProjectTimeSettings;
use Foresi\Timesheet\Application\TimeRefused;
use Foresi\Timesheet\Domain\TimeEntry;
use Foresi\Timesheet\Integration\Invoicing\LineGrouping;
use Foresi\Timesheet\Integration\Invoicing\TimeInvoicing;
use Foresi\Timesheet\Integration\Invoicing\TimeInvoicingRefused;
use Foresi\Timesheet\Integration\Invoicing\TimeRounding;
use Foresi\Timesheet\Integration\Invoicing\TimeScope;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Task;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Issuing an invoice of time locks the time in the same transaction: each entry
 * whose line stayed on the form is billed on that line, at the rate and for the
 * rounded minutes it was chosen with, however the line was edited; the time of
 * a line removed stays unbilled. Time invoiced, edited or deleted since it was
 * chosen refuses the invoice, which then stores nothing.
 */
final class TimeInvoiceIssueTest extends WorkTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private TimeInvoicing $invoicing;
    private BilledWork $billed;
    private IssueInvoice $issue;
    private ManageTime $time;
    private Party $customer;
    private Project $website;
    private Task $homepage;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        // The time chosen waits in the session of the person, as between the dialog and the form.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);
        $this->invoicing = $container->get(TimeInvoicing::class);
        $this->billed = $container->get(BilledWork::class);
        $this->issue = $container->get(IssueInvoice::class);
        $this->time = $container->get(ManageTime::class);

        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em->persist($firm);
        FirmAccounts::add($this->em, self::IBAN);
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em->persist($this->customer);
        $this->em->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->flush();

        $this->website = $this->projects->create('Website', $this->customer->id(), \Foresi\Work\Domain\WorkflowTemplate::Simple);
        $this->homepage = $this->tasks->create($this->website->id(), 'Design the homepage');
        self::getContainer()->get(ProjectTimeSettings::class)->setHourlyRate($this->website->id(), Money::of('1000', Currency::of('CZK')));
    }

    public function testIssuingBillsTheTimeOfEachLineKeptAndLeavesTheTimeOfARemovedLine(): void
    {
        $design = $this->logged($this->homepage, 50, '2026-10-05');
        $more = $this->logged($this->homepage, 20, '2026-10-06');
        $planning = $this->logged(null, 10, '2026-10-07');
        $draft = $this->chosen(TimeRounding::UpToQuarterHour);
        self::assertSame(['Website', 'Website – Design the homepage'], array_map(static fn (BilledLine $line): string => $line->text, $draft->lines));
        self::assertSame(['0.2500', '1.5000'], array_map(static fn (BilledLine $line): string => $line->quantity->toDecimal(), $draft->lines));

        // The line of the project itself is removed; the other's text, price and quantity are typed over.
        $kept = $draft->lines[1];
        $lines = [
            new LineInput('Other work', Quantity::fromString('1'), 'h', Money::of('100', Currency::of('CZK')), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
            new LineInput('Homepage design', Quantity::fromString('2'), 'hod', Money::of('900', Currency::of('CZK')), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'), source: $kept->source),
        ];
        $invoice = $this->issue->issue($this->input($draft, $lines));

        $row = $this->lineAt($invoice, 1);
        foreach ([$design, $more] as $entry) {
            $billed = $this->entry($entry);
            self::assertSame($invoice->id(), $billed->invoiceId());
            self::assertSame($row->id(), $billed->invoiceLineId());
            self::assertTrue(Money::of('1000', Currency::of('CZK'))->equals($billed->invoicedRate() ?? Money::ofMinor(0, Currency::of('EUR'))), 'the rate it was chosen at, not the price typed');
        }
        self::assertSame([50, 20], [$this->entry($design)->minutes(), $this->entry($more)->minutes()], 'the time worked stays');
        self::assertSame([60, 30], [$this->entry($design)->invoicedMinutes(), $this->entry($more)->invoicedMinutes()], 'the time billed is rounded');
        self::assertFalse($this->entry($planning)->isInvoiced());
        self::assertNull($this->entry($planning)->invoiceId());

        // Invoiced time is locked, and no longer offered.
        foreach ([fn () => $this->time->edit($design, $this->homepage->id(), new \DateTimeImmutable('2026-10-05'), 45, null, null, '', true), fn (): TimeEntry => $this->time->delete($design)] as $change) {
            try {
                $change();
                self::fail('Invoiced time is not changed.');
            } catch (TimeRefused|WorkRefused $refused) {
                self::assertSame('time_invoiced', $refused->reason);
            }
        }
        self::assertSame([$planning], array_map(static fn (\Foresi\Timesheet\Integration\Invoicing\TimeCandidate $candidate): string => $candidate->entry->id, $this->invoicing->candidates($this->scope())));
    }

    public function testTheFirstOfTwoCopiesOfARowBillsItsTime(): void
    {
        $design = $this->logged($this->homepage, 50, '2026-10-05');
        $draft = $this->chosen(TimeRounding::None);
        $row = static fn (string $text): LineInput => new LineInput($text, Quantity::fromString('1'), 'h', Money::of('1000', Currency::of('CZK')), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'), source: $draft->lines[0]->source);

        $invoice = $this->issue->issue($this->input($draft, [$row('First'), $row('Copy')]));

        self::assertSame($this->lineAt($invoice, 0)->id(), $this->entry($design)->invoiceLineId());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function changes(): iterable
    {
        yield 'edited' => ['edit', 'timesheet.invoice.problem.time_changed'];
        // Changes the issue could bill all the same, so only what the entry says tells them.
        yield 'edited to fewer minutes' => ['fewer', 'timesheet.invoice.problem.time_changed'];
        yield 'its note edited' => ['note', 'timesheet.invoice.problem.time_changed'];
        yield 'moved to another task' => ['task', 'timesheet.invoice.problem.time_changed'];
        yield 'made not billable' => ['unbillable', 'timesheet.invoice.problem.time_changed'];
        yield 'deleted' => ['delete', 'timesheet.invoice.problem.time_changed'];
        yield 'invoiced by another invoice' => ['invoice', 'timesheet.invoice.problem.time_invoiced'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function testTimeChangedSinceItWasChosenRefusesTheInvoice(string $change, string $problem): void
    {
        $design = $this->logged($this->homepage, 50, '2026-10-05');
        $this->logged($this->homepage, 20, '2026-10-06');
        $draft = $this->chosen(TimeRounding::None);
        match ($change) {
            'edit' => $this->time->edit($design, $this->homepage->id(), new \DateTimeImmutable('2026-10-05'), 55, null, null, '', true),
            'fewer' => $this->time->edit($design, $this->homepage->id(), new \DateTimeImmutable('2026-10-05'), 45, null, null, '', true),
            'note' => $this->time->edit($design, $this->homepage->id(), new \DateTimeImmutable('2026-10-05'), 50, null, null, 'Changed', true),
            'task' => $this->time->edit($design, $this->tasks->create($this->website->id(), 'Write the copy')->id(), new \DateTimeImmutable('2026-10-05'), 50, null, null, '', true),
            'unbillable' => $this->time->edit($design, $this->homepage->id(), new \DateTimeImmutable('2026-10-05'), 50, null, null, '', false),
            'delete' => $this->time->delete($design),
            'invoice' => $this->issue->issue($this->input($this->chosen(TimeRounding::None))),
            default => throw new \LogicException('A change.'),
        };
        $invoices = $this->invoiceCount();
        $this->em->clear();

        try {
            $this->issue->issue($this->input($draft));
            self::fail('The invoice is refused.');
        } catch (IssueRefused $refused) {
            self::assertSame([$problem], array_map(static fn (IssueProblem $found): string => $found->key, $refused->problems));
        }
        $this->em->clear();
        self::assertSame($invoices, $this->invoiceCount(), 'nothing is stored');
        if ('invoice' !== $change) {
            self::assertSame(0, $this->rows('SELECT COUNT(*) FROM time_entry WHERE invoice_id IS NOT NULL'));
        }
    }

    public function testTheChoiceIsRefusedForTimeWithoutARateOrInAnotherCurrency(): void
    {
        self::getContainer()->get(ProjectTimeSettings::class)->setHourlyRate($this->website->id(), null);
        $entry = $this->logged($this->homepage, 50, '2026-10-05');

        $refusal = function (): string {
            try {
                $this->invoicing->lines($this->scope(), array_map(static fn (\Foresi\Timesheet\Integration\Invoicing\TimeCandidate $candidate): string => $candidate->entry->id, $this->invoicing->candidates($this->scope())), LineGrouping::Task, TimeRounding::None);
            } catch (TimeInvoicingRefused $refused) {
                return $refused->reason;
            }

            return 'none';
        };
        self::assertSame('rate_missing', $refusal());
        self::assertNull($this->invoicing->candidates($this->scope())[0]->rate);

        self::getContainer()->get(ProjectTimeSettings::class)->setHourlyRate($this->website->id(), Money::of('40', Currency::of('EUR')));
        self::assertSame('firm_currency', $refusal());

        $this->owner->changeHourlyRate(Money::of('1000', Currency::of('CZK')));
        self::getContainer()->get(ProjectTimeSettings::class)->setHourlyRate($this->website->id(), null);
        $other = $this->projects->create('Shop', $this->customer->id(), \Foresi\Work\Domain\WorkflowTemplate::Simple);
        self::getContainer()->get(ProjectTimeSettings::class)->setHourlyRate($other->id(), Money::of('40', Currency::of('EUR')));
        $this->time->log($other->id(), null, new \DateTimeImmutable('2026-10-08'), 30, billable: true);
        $mixed = new TimeScope($this->customer->id(), [$this->website->id(), $other->id()], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
        try {
            $this->invoicing->lines($mixed, array_map(static fn (\Foresi\Timesheet\Integration\Invoicing\TimeCandidate $candidate): string => $candidate->entry->id, $this->invoicing->candidates($mixed)), LineGrouping::Task, TimeRounding::None);
            self::fail('A mix of currencies is refused.');
        } catch (TimeInvoicingRefused $refused) {
            self::assertSame('currencies', $refused->reason);
        }
        self::assertFalse($this->entry($entry)->isInvoiced());
    }

    private function logged(?Task $task, int $minutes, string $day): string
    {
        return $this->time->log($this->website->id(), $task?->id(), new \DateTimeImmutable($day), $minutes, billable: true)->id();
    }

    /**
     * Every entry of last month on Website, chosen as the dialog does, per task.
     */
    private function chosen(TimeRounding $rounding): BilledWorkDraft
    {
        $scope = $this->scope();
        $ids = array_map(static fn (\Foresi\Timesheet\Integration\Invoicing\TimeCandidate $candidate): string => $candidate->entry->id, $this->invoicing->candidates($scope));
        $reference = $this->invoicing->select($scope, $this->invoicing->lines($scope, $ids, LineGrouping::Task, $rounding), 'hod', 'j. n. Y');

        return $this->billed->draft($reference) ?? throw new \LogicException('The time chosen waits.');
    }

    private function scope(): TimeScope
    {
        return new TimeScope($this->customer->id(), [$this->website->id()], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
    }

    /**
     * The input of the invoice form opened from the draft, its lines as given or as drafted.
     *
     * @param list<LineInput>|null $lines
     */
    private function input(BilledWorkDraft $draft, ?array $lines = null, string $iban = self::IBAN): InvoiceInput
    {
        $lines ??= array_map(static fn (BilledLine $line): LineInput => new LineInput($line->text, $line->quantity, $line->unit, $line->price, PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'), source: $line->source), $draft->lines);

        return new InvoiceInput(null, null, $draft->customerId, null, new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-24'), PaymentMethod::BankTransfer, $iban, '', '', '', 'Time', '', '', DocumentLanguage::Czech, $lines, true, billedWork: $draft->reference);
    }

    private function lineAt(Invoice $invoice, int $position): InvoiceLine
    {
        return $this->em->getRepository(InvoiceLine::class)->findOneBy(['invoice' => $invoice->id(), 'position' => $position]) ?? throw new \LogicException('Line.');
    }

    private function entry(string $id): TimeEntry
    {
        $this->em->clear();

        return $this->em->find(TimeEntry::class, $id) ?? throw new \LogicException('Entry.');
    }

    private function invoiceCount(): int
    {
        return $this->rows('SELECT COUNT(*) FROM invoice');
    }

    private function rows(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);

        return is_numeric($count) ? (int) $count : throw new \LogicException('A count.');
    }
}
