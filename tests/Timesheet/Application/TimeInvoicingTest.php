<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Timesheet\Application;

use App\Invoicing\Domain\Invoice;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Party\Support\FirmAccounts;
use App\Tests\Work\Application\WorkTestCase;
use App\Timesheet\Application\TimeCandidate;
use App\Timesheet\Application\TimeInvoicing;
use App\Timesheet\Application\TimeInvoicingRefused;
use App\Timesheet\Application\TimeScope;
use App\Timesheet\Domain\BilledTime;
use App\Timesheet\Domain\LineGrouping;
use App\Timesheet\Domain\TimeLine;
use App\Timesheet\Domain\TimeRounding;
use App\Work\Application\ManageTime;
use App\Work\Application\ProjectRow;
use App\Work\Domain\Project;
use App\Work\Domain\WorkflowTemplate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * What the dialog of time to invoice offers: the projects, the time in scope
 * that is finished, billable and not invoiced, each with its rate, and the
 * lines the ticked time makes. Only an owner gets any of it.
 */
final class TimeInvoicingTest extends WorkTestCase
{
    private TimeInvoicing $invoicing;
    private ManageTime $time;
    private LegalEntity $firm;
    private Party $customer;
    private Project $website;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        // The time chosen waits in the session of the person, as between the dialog and the form.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);
        $this->invoicing = $container->get(TimeInvoicing::class);
        $this->time = $container->get(ManageTime::class);

        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em->persist($this->firm);
        FirmAccounts::add($this->em, $this->firm, 'CZ6508000000192000145399');
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em->persist($this->customer);
        $this->em->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->flush();

        $this->website = $this->projects->create('Website', $this->customer->id(), WorkflowTemplate::Simple);
        $this->time->setProjectHourlyRate($this->website->id(), Money::of('1000', Currency::CZK));
    }

    public function testOnlyAnOwnerGetsAnyOfIt(): void
    {
        $this->logged($this->website, '2026-10-05');
        $lines = $this->invoicing->lines($this->scope(), $this->candidateIds(), LineGrouping::Task, TimeRounding::None);
        $this->signIn($this->member);

        foreach ([
            'projects' => $this->invoicing->projects(...),
            'candidates' => fn (): array => $this->invoicing->candidates($this->scope()),
            'lines' => fn (): array => $this->invoicing->lines($this->scope(), [], LineGrouping::Task, TimeRounding::None),
            'select' => fn (): string => $this->invoicing->select($this->scope(), $lines, 'hod', 'j. n. Y'),
        ] as $call => $asked) {
            try {
                $asked();
                self::fail(\sprintf('%s is refused.', $call));
            } catch (TimeInvoicingRefused $refused) {
                self::assertSame('not_allowed', $refused->reason, $call);
            }
        }
    }

    public function testEveryProjectIsOfferedOnceArchivedOnesTooByName(): void
    {
        $apps = $this->projects->create('Apps', $this->customer->id(), WorkflowTemplate::Simple);
        $this->projects->create('Zoo', $this->customer->id(), WorkflowTemplate::Simple);
        $this->projects->archive($apps->id());

        self::assertSame(['Apps', 'Website', 'Zoo'], array_map(static fn (ProjectRow $row): string => $row->name, $this->invoicing->projects()));
    }

    public function testOnlyFinishedTimeOfTheProjectsChosenIsOffered(): void
    {
        $shop = $this->projects->create('Shop', $this->customer->id(), WorkflowTemplate::Simple);
        $this->time->setProjectHourlyRate($shop->id(), Money::of('1000', Currency::CZK));
        $finished = $this->logged($this->website, '2026-10-05');
        $this->logged($shop, '2026-10-06');
        $this->time->startTimer($this->website->id(), null, billable: true);

        self::assertSame([$finished], $this->candidateIds());
    }

    public function testEachPersonsTimeHasTheirOwnRate(): void
    {
        $this->time->setProjectHourlyRate($this->website->id(), null);
        $this->owner->changeHourlyRate(Money::of('1500', Currency::CZK));
        $this->member->changeHourlyRate(Money::of('800', Currency::CZK));
        $this->em->flush();
        $this->logged($this->website, '2026-10-05');
        $this->signIn($this->member);
        $this->logged($this->website, '2026-10-06');
        $this->signIn($this->owner);

        self::assertSame([150000, 80000], array_map(static fn (TimeCandidate $candidate): ?int => $candidate->rate?->minor(), $this->invoicing->candidates($this->scope())));
    }

    public function testOnlyTheTickedTimeMakesTheLines(): void
    {
        $this->logged($this->website, '2026-10-05');
        $ticked = $this->logged($this->website, '2026-10-06');

        $lines = $this->invoicing->lines($this->scope(), [$ticked], LineGrouping::Entry, TimeRounding::None);

        self::assertSame([[$ticked]], array_map(static fn (TimeLine $line): array => array_map(static fn (BilledTime $billed): string => $billed->time->entryId, $line->entries), $lines));
    }

    public function testTheTimeMakesAtMostTheLinesAnInvoiceHolds(): void
    {
        for ($entry = 0; $entry <= Invoice::MAX_LINES; ++$entry) {
            $this->logged($this->website, '2026-10-05');
        }
        $ids = $this->candidateIds();

        self::assertCount(Invoice::MAX_LINES, $this->invoicing->lines($this->scope(), \array_slice($ids, 0, Invoice::MAX_LINES), LineGrouping::Entry, TimeRounding::None));
        try {
            $this->invoicing->lines($this->scope(), $ids, LineGrouping::Entry, TimeRounding::None);
            self::fail('A line too many is refused.');
        } catch (TimeInvoicingRefused $refused) {
            self::assertSame('too_many_lines', $refused->reason);
        }
    }

    private function logged(Project $project, string $day): string
    {
        return $this->time->log($project->id(), null, new \DateTimeImmutable($day), 30, billable: true)->id();
    }

    /**
     * @return list<string>
     */
    private function candidateIds(): array
    {
        return array_map(static fn (TimeCandidate $candidate): string => $candidate->entry->id, $this->invoicing->candidates($this->scope()));
    }

    private function scope(): TimeScope
    {
        return new TimeScope($this->firm->id(), $this->customer->id(), [$this->website->id()], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
    }
}
