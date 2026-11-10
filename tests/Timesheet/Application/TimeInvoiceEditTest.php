<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Timesheet\Application;

use App\Invoicing\Application\BilledLine;
use App\Invoicing\Application\BilledWork;
use App\Invoicing\Application\BilledWorkDraft;
use App\Invoicing\Application\EditInvoice;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Party\Support\FirmAccounts;
use App\Tests\Work\Application\WorkTestCase;
use App\Timesheet\Application\TimeInvoicing;
use App\Timesheet\Application\TimeScope;
use App\Timesheet\Domain\LineGrouping;
use App\Timesheet\Domain\TimeRounding;
use App\Work\Application\ManageTime;
use App\Work\Domain\Project;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Editing an issued invoice of time keeps each entry with its row: the form
 * keeps with each row the line it was loaded from, so time follows a row moved
 * up or down, and the time of a row removed is free again, unbilled and offered
 * for invoicing, in the same transaction.
 */
final class TimeInvoiceEditTest extends WorkTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private TimeInvoicing $invoicing;
    private BilledWork $billed;
    private IssueInvoice $issue;
    private EditInvoice $edit;
    private InvoiceStore $store;
    private ManageTime $time;
    private LegalEntity $firm;
    private Party $customer;
    private Project $website;
    private Task $homepage;
    private Task $contact;

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
        $this->edit = $container->get(EditInvoice::class);
        $this->store = $container->get(InvoiceStore::class);
        $this->time = $container->get(ManageTime::class);

        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em->persist($this->firm);
        FirmAccounts::add($this->em, $this->firm, self::IBAN);
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em->persist($this->customer);
        $this->em->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->flush();

        $this->website = $this->projects->create('Website', $this->customer->id(), \App\Work\Domain\WorkflowTemplate::Simple);
        $this->homepage = $this->tasks->create($this->website->id(), 'Design the homepage');
        $this->contact = $this->tasks->create($this->website->id(), 'Build the contact page');
        $this->time->setProjectHourlyRate($this->website->id(), Money::of('1000', Currency::CZK));
    }

    public function testTheTimeOfARowRemovedIsFreeAndTheRestStaysOnItsLine(): void
    {
        [$invoice, $entries] = $this->issued();
        $lines = $this->lines($invoice);
        $last = $lines[2];
        $freed = $this->entriesOn($entries, $last->id());
        self::assertNotSame([], $freed);

        $this->edit->edit($invoice->id(), $this->input($invoice, [$this->row($lines[0]), $this->row($lines[1])]), $invoice->version());

        foreach ($entries as $id => $line) {
            $entry = $this->entry($id);
            if (in_array($id, $freed, true)) {
                self::assertFalse($entry->isInvoiced(), 'the time of the row removed is free');
                self::assertSame([null, null, null], [$entry->invoiceId(), $entry->invoicedRate(), $entry->invoicedMinutes()]);
            } else {
                self::assertSame([$invoice->id(), $line], [$entry->invoiceId(), $entry->invoiceLineId()], 'the rest stays on its line');
            }
        }
        self::assertSame($freed, $this->candidateIds(), 'offered for invoicing again');
    }

    public function testTimeFollowsItsRowWhenRowsMoveAndOneInTheMiddleIsRemoved(): void
    {
        [$invoice, $entries] = $this->issued();
        $lines = $this->lines($invoice);
        $middle = $this->entriesOn($entries, $lines[1]->id());

        // The last row moved to the top and the middle one removed: the form posts them by the lines they came from.
        $this->edit->edit($invoice->id(), $this->input($invoice, [$this->row($lines[2]), $this->row($lines[0])]), $invoice->version());

        $now = $this->lines($invoice);
        self::assertCount(2, $now);
        $expected = [$lines[2]->id() => $now[0]->id(), $lines[0]->id() => $now[1]->id()];
        foreach ($entries as $id => $line) {
            $entry = $this->entry($id);
            if (in_array($id, $middle, true)) {
                self::assertFalse($entry->isInvoiced(), 'the time of the row removed is free');
            } else {
                self::assertSame($expected[$line], $entry->invoiceLineId(), 'the time went with its row');
                self::assertNotNull($entry->invoicedMinutes());
            }
        }
        self::assertSame($middle, $this->candidateIds());
    }

    public function testInputWithoutRowSourcesIsByPosition(): void
    {
        [$invoice, $entries] = $this->issued();
        $lines = $this->lines($invoice);
        $freed = $this->entriesOn($entries, $lines[2]->id());

        $this->edit->edit($invoice->id(), $this->input($invoice, [$this->row($lines[0], false), $this->row($lines[1], false)]), $invoice->version());

        self::assertSame($freed, $this->candidateIds());
        foreach (array_diff(array_keys($entries), $freed) as $id) {
            self::assertSame($entries[$id], $this->entry($id)->invoiceLineId());
        }
    }

    public function testRowsEditedInPlaceKeepTheirTime(): void
    {
        [$invoice, $entries] = $this->issued();
        $rows = array_map(fn (InvoiceLine $line): LineInput => $this->row($line, true, $line->text().' (edited)'), $this->lines($invoice));

        $this->edit->edit($invoice->id(), $this->input($invoice, $rows), $invoice->version());

        foreach ($entries as $id => $line) {
            self::assertSame([$invoice->id(), $line], [$this->entry($id)->invoiceId(), $this->entry($id)->invoiceLineId()]);
        }
        self::assertSame([], $this->candidateIds());
    }

    /**
     * An invoice of three lines, one per task and one for the project, and the line of each entry.
     *
     * @return array{Invoice, array<string, string>}
     */
    private function issued(): array
    {
        $ids = [
            $this->logged($this->homepage, 50, '2026-10-05'),
            $this->logged($this->homepage, 20, '2026-10-06'),
            $this->logged($this->contact, 30, '2026-10-06'),
            $this->logged(null, 10, '2026-10-07'),
        ];
        $invoice = $this->issue->issue($this->draftInput($this->chosen()));
        $entries = [];
        foreach ($ids as $id) {
            $entries[$id] = $this->entry($id)->invoiceLineId() ?? throw new \LogicException('Billed.');
        }
        $this->em->clear();
        $invoice = $this->em->find(Invoice::class, $invoice->id()) ?? throw new \LogicException('Invoice.');
        self::assertCount(3, $this->lines($invoice));

        return [$invoice, $entries];
    }

    /**
     * @param array<string, string> $entries
     *
     * @return list<string>
     */
    private function entriesOn(array $entries, string $lineId): array
    {
        return array_keys(array_filter($entries, static fn (string $line): bool => $line === $lineId));
    }

    /**
     * @return list<InvoiceLine>
     */
    private function lines(Invoice $invoice): array
    {
        $this->em->clear();

        return $this->store->lines($this->em->find(Invoice::class, $invoice->id()) ?? throw new \LogicException('Invoice.'));
    }

    /**
     * A row of the edit form as loaded from the line, keeping the line as its source unless told otherwise.
     */
    private function row(InvoiceLine $line, bool $sourced = true, ?string $text = null): LineInput
    {
        return new LineInput($text ?? $line->text(), $line->quantity(), $line->unit(), $line->price(), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'), source: $sourced ? $line->id() : null);
    }

    /**
     * @return list<string>
     */
    private function candidateIds(): array
    {
        $ids = array_map(static fn (\App\Timesheet\Application\TimeCandidate $candidate): string => $candidate->entry->id, $this->invoicing->candidates($this->scope()));
        sort($ids);

        return $ids;
    }

    private function logged(?Task $task, int $minutes, string $day): string
    {
        return $this->time->log($this->website->id(), $task?->id(), new \DateTimeImmutable($day), $minutes, billable: true)->id();
    }

    /**
     * Every entry of last month on Website, chosen as the dialog does, per task.
     */
    private function chosen(): BilledWorkDraft
    {
        $scope = $this->scope();
        $ids = array_map(static fn (\App\Timesheet\Application\TimeCandidate $candidate): string => $candidate->entry->id, $this->invoicing->candidates($scope));
        $reference = $this->invoicing->select($scope, $this->invoicing->lines($scope, $ids, LineGrouping::Task, TimeRounding::None), 'hod', 'j. n. Y');

        return $this->billed->draft($reference) ?? throw new \LogicException('The time chosen waits.');
    }

    private function scope(): TimeScope
    {
        return new TimeScope($this->firm->id(), $this->customer->id(), [$this->website->id()], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
    }

    private function draftInput(BilledWorkDraft $draft): InvoiceInput
    {
        $lines = array_map(static fn (BilledLine $line): LineInput => new LineInput($line->text, $line->quantity, $line->unit, $line->price, PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'), source: $line->source), $draft->lines);

        return new InvoiceInput($draft->firmId, null, null, $draft->customerId, null, new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-24'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Time', '', '', DocumentLanguage::Czech, $lines, true, billedWork: $draft->reference);
    }

    /**
     * The edit form of the invoice saved with the rows given.
     *
     * @param list<LineInput> $lines
     */
    private function input(Invoice $invoice, array $lines): InvoiceInput
    {
        return new InvoiceInput($this->firm->id(), null, null, $this->customer->id(), null, new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-24'), PaymentMethod::BankTransfer, self::IBAN, $invoice->variableSymbol(), '', '', 'Time', '', '', DocumentLanguage::Czech, $lines, true);
    }

    private function entry(string $id): TimeEntry
    {
        $this->em->clear();

        return $this->em->find(TimeEntry::class, $id) ?? throw new \LogicException('Entry.');
    }
}
