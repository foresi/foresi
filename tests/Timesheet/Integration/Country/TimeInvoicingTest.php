<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Integration\Country;

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
use Foresi\Timesheet\Integration\Invoicing\BilledTime;
use Foresi\Timesheet\Integration\Invoicing\LineGrouping;
use Foresi\Timesheet\Integration\Invoicing\TimeCandidate;
use Foresi\Timesheet\Integration\Invoicing\TimeInvoicing;
use Foresi\Timesheet\Integration\Invoicing\TimeInvoicingRefused;
use Foresi\Timesheet\Integration\Invoicing\TimeLine;
use Foresi\Timesheet\Integration\Invoicing\TimeRounding;
use Foresi\Timesheet\Integration\Invoicing\TimeScope;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The lines the ticked time makes, by the invoicing rules of the firm's
 * country, and at most as many as an invoice holds. Only an owner gets any of
 * the dialog.
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
        return new TimeScope($this->customer->id(), [$this->website->id()], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
    }
}
