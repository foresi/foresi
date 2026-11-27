<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Integration\Invoicing;

use Foresi\Invoicing\Domain\Invoice;
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
use Foresi\Timesheet\Integration\Invoicing\TimeCandidate;
use Foresi\Timesheet\Integration\Invoicing\TimeInvoicing;
use Foresi\Timesheet\Integration\Invoicing\TimeScope;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * What the dialog of time to invoice offers: the projects, and the time in
 * scope that is finished, billable and not invoiced, each with its rate. The
 * lines, which need the invoicing rules of the firm's country, are in
 * Integration/Country.
 */
final class TimeInvoicingTest extends WorkTestCase
{
    private TimeInvoicing $invoicing;
    private ManageTime $time;
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

        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em->persist($firm);
        FirmAccounts::add($this->em, 'CZ6508000000192000145399');
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em->persist($this->customer);
        $this->em->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->flush();

        $this->website = $this->projects->create('Website', $this->customer->id(), WorkflowTemplate::Simple);
        self::getContainer()->get(ProjectTimeSettings::class)->setHourlyRate($this->website->id(), Money::of('1000', Currency::CZK));
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
        self::getContainer()->get(ProjectTimeSettings::class)->setHourlyRate($shop->id(), Money::of('1000', Currency::CZK));
        $finished = $this->logged($this->website, '2026-10-05');
        $this->logged($shop, '2026-10-06');
        $this->time->startTimer($this->website->id(), null, billable: true);

        self::assertSame([$finished], $this->candidateIds());
    }

    public function testEachPersonsTimeHasTheirOwnRate(): void
    {
        self::getContainer()->get(ProjectTimeSettings::class)->setHourlyRate($this->website->id(), null);
        $this->owner->changeHourlyRate(Money::of('1500', Currency::CZK));
        $this->member->changeHourlyRate(Money::of('800', Currency::CZK));
        $this->em->flush();
        $this->logged($this->website, '2026-10-05');
        $this->signIn($this->member);
        $this->logged($this->website, '2026-10-06');
        $this->signIn($this->owner);

        self::assertSame([150000, 80000], array_map(static fn (TimeCandidate $candidate): ?int => $candidate->rate?->minor(), $this->invoicing->candidates($this->scope())));
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
        return new TimeScope($this->customer->id(), [$this->website->id()], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
    }
}
