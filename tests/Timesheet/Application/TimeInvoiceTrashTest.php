<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Timesheet\Application;

use App\Invoicing\Application\BilledLine;
use App\Invoicing\Application\BilledWork;
use App\Invoicing\Application\BilledWorkDraft;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\Invoice;
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
 * An invoice of time in the trash frees its time in the same transaction: the
 * entries are unbilled, editable and offered again. Restoring the invoice bills
 * them again on the same lines, at the same rates and for the same minutes, and
 * is refused once an entry was invoiced elsewhere, edited or deleted meanwhile.
 * Every change of an entry is audited under its task or project.
 */
final class TimeInvoiceTrashTest extends WorkTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private TimeInvoicing $invoicing;
    private BilledWork $billed;
    private IssueInvoice $issue;
    private TrashInvoice $trash;
    private ManageTime $time;
    private LegalEntity $firm;
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
        $this->trash = $container->get(TrashInvoice::class);
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
        $this->time->setProjectHourlyRate($this->website->id(), Money::of('1000', Currency::CZK));
    }

    public function testTheTrashFreesTheTimeAndRestoringBillsItAgainAsItWas(): void
    {
        $design = $this->logged($this->homepage, 50, '2026-10-05');
        $planning = $this->logged(null, 10, '2026-10-07');
        $invoice = $this->issue->issue($this->input($this->chosen(TimeRounding::UpToQuarterHour)));
        $billed = [];
        foreach ([$design, $planning] as $id) {
            $entry = $this->entry($id);
            $billed[$id] = [$entry->invoiceLineId(), $entry->invoicedRate()?->minor(), $entry->invoicedMinutes()];
        }
        self::assertSame([60, 15], [$billed[$design][2], $billed[$planning][2]]);

        $this->trash->trash($invoice->id(), 'Issued by mistake');

        foreach ([$design, $planning] as $id) {
            $entry = $this->entry($id);
            self::assertFalse($entry->isInvoiced());
            self::assertSame([null, null, null, null], [$entry->invoiceId(), $entry->invoiceLineId(), $entry->invoicedRate(), $entry->invoicedMinutes()]);
        }
        self::assertSame([$design, $planning], $this->candidateIds(), 'offered for invoicing again');
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM released_time'));
        // Freed time is editable again; saving it unchanged keeps it what the invoice billed.
        $this->time->edit($design, $this->homepage->id(), new \DateTimeImmutable('2026-10-05'), 50, null, null, '', true);

        $this->trash->restore($invoice->id());

        foreach ([$design, $planning] as $id) {
            $entry = $this->entry($id);
            self::assertSame($invoice->id(), $entry->invoiceId());
            self::assertSame($billed[$id], [$entry->invoiceLineId(), $entry->invoicedRate()?->minor(), $entry->invoicedMinutes()], 'the same line, rate and minutes');
        }
        self::assertSame([], $this->candidateIds());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM released_time'));
        // Billed, released and billed again, under the task for the one on a task and the project for the
        // other; the save of the one on the task is a fourth, as a day saved is a new value to Doctrine.
        self::assertSame([4, 3], [$this->updates($design, $this->homepage->id()), $this->updates($planning, $this->website->id())]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function changes(): iterable
    {
        yield 'edited' => ['edit', 'timesheet.problem.restore_time_changed'];
        // Changes the issue could bill all the same, so only what the entry says tells them.
        yield 'edited to fewer minutes' => ['fewer', 'timesheet.problem.restore_time_changed'];
        yield 'its note edited' => ['note', 'timesheet.problem.restore_time_changed'];
        yield 'moved to another task' => ['task', 'timesheet.problem.restore_time_changed'];
        yield 'made not billable' => ['unbillable', 'timesheet.problem.restore_time_changed'];
        yield 'deleted' => ['delete', 'timesheet.problem.restore_time_changed'];
        yield 'invoiced by another invoice' => ['invoice', 'timesheet.problem.restore_time_invoiced'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function testTimeChangedWhileTheInvoiceIsInTheTrashRefusesTheRestore(string $change, string $problem): void
    {
        $design = $this->logged($this->homepage, 50, '2026-10-05');
        $more = $this->logged($this->homepage, 20, '2026-10-06');
        $invoice = $this->issue->issue($this->input($this->chosen(TimeRounding::None)));
        $this->trash->trash($invoice->id(), 'Issued by mistake');
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
        $this->em->clear();

        try {
            $this->trash->restore($invoice->id());
            self::fail('The restore is refused.');
        } catch (IssueRefused $refused) {
            self::assertSame([$problem], array_map(static fn (IssueProblem $found): string => $found->key, $refused->problems));
        }
        $this->em->clear();
        self::assertTrue($this->em->find(Invoice::class, $invoice->id())?->isTrashed(), 'it stays in the trash');
        self::assertSame(0, $this->rows(sprintf("SELECT COUNT(*) FROM time_entry WHERE invoice_id = '%s'", $invoice->id())), 'no time is billed on it, the time unchanged not either');
        self::assertNotSame($invoice->id(), $this->entry($more)->invoiceId());
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM released_time'), 'kept for a restore once the time is as it was');
    }

    public function testTheTrashFreesTheTimeOfThatInvoiceAlone(): void
    {
        $design = $this->logged($this->homepage, 50, '2026-10-05');
        $first = $this->issue->issue($this->input($this->chosen(TimeRounding::None)));
        $planning = $this->logged(null, 10, '2026-10-07');
        $second = $this->issue->issue($this->input($this->chosen(TimeRounding::None)));
        self::assertSame([$first->id(), $second->id()], [$this->entry($design)->invoiceId(), $this->entry($planning)->invoiceId()]);

        $this->trash->trash($first->id(), 'Issued by mistake');

        self::assertFalse($this->entry($design)->isInvoiced());
        $kept = $this->entry($planning);
        self::assertSame([$second->id(), 10], [$kept->invoiceId(), $kept->invoicedMinutes()]);
        self::assertSame([$design], $this->candidateIds());
    }

    /**
     * The updates of an entry the audit log keeps under the root.
     */
    private function updates(string $entryId, string $rootId): int
    {
        $count = $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_entry WHERE entity_class = ? AND entity_id = ? AND root_id = ? AND action = 'updated'", [TimeEntry::class, $entryId, $rootId]);

        return is_numeric($count) ? (int) $count : throw new \LogicException('A count.');
    }

    /**
     * @return list<string>
     */
    private function candidateIds(): array
    {
        return array_map(static fn (\App\Timesheet\Application\TimeCandidate $candidate): string => $candidate->entry->id, $this->invoicing->candidates($this->scope()));
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
        $ids = array_map(static fn (\App\Timesheet\Application\TimeCandidate $candidate): string => $candidate->entry->id, $this->invoicing->candidates($scope));
        $reference = $this->invoicing->select($scope, $this->invoicing->lines($scope, $ids, LineGrouping::Task, $rounding), 'hod', 'j. n. Y');

        return $this->billed->draft($reference) ?? throw new \LogicException('The time chosen waits.');
    }

    private function scope(): TimeScope
    {
        return new TimeScope($this->firm->id(), $this->customer->id(), [$this->website->id()], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
    }

    /**
     * The input of the invoice form opened from the draft, its lines as given or as drafted.
     *
     * @param list<LineInput>|null $lines
     */
    private function input(BilledWorkDraft $draft, ?array $lines = null): InvoiceInput
    {
        $lines ??= array_map(static fn (BilledLine $line): LineInput => new LineInput($line->text, $line->quantity, $line->unit, $line->price, PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'), source: $line->source), $draft->lines);

        return new InvoiceInput($draft->firmId, null, null, $draft->customerId, null, new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-24'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Time', '', '', DocumentLanguage::Czech, $lines, true, billedWork: $draft->reference);
    }

    private function entry(string $id): TimeEntry
    {
        $this->em->clear();

        return $this->em->find(TimeEntry::class, $id) ?? throw new \LogicException('Entry.');
    }

    private function rows(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);

        return is_numeric($count) ? (int) $count : throw new \LogicException('A count.');
    }
}
