<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Integration\Work;

use Foresi\Sales\Application\Api\AddDealLineInput;
use Foresi\Sales\Application\Api\LoseDealInput;
use Foresi\Sales\Application\Api\ReopenDealInput;
use Foresi\Sales\Application\Api\TrashDealInput;
use Foresi\Sales\Application\Api\UpdateDealInput;
use Foresi\Sales\Application\Api\UpdateDealLineInput;
use Foresi\Sales\Application\Api\WinDealInput;
use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Application\LineProject;
use Foresi\Sales\Application\LineProjects;
use Foresi\Sales\Application\SalesRefused;
use Foresi\Sales\Application\SalesWork;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Infrastructure\Api\SalesJson;
use Foresi\Sales\Integration\Timesheet\DealLineRates;
use Foresi\Sales\Integration\Work\DealProjectFacts;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Sales\Support\SalesTestCase;
use Foresi\Timesheet\Application\RateResolver;
use Foresi\Timesheet\Domain\RateSource;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageTime;
use Foresi\Work\Contract\ProjectSlots;
use Foresi\Work\Domain\Project;

/**
 * An hourly deal line linked to a project of Work (spec "Email inbox" 4a): a
 * project serves one hourly line, of the deal's client or of no client, and
 * the time logged there takes the line's rate while the deal is open or won.
 */
final class DealLineProjectTest extends SalesTestCase
{
    private ManageProjects $projects;
    private ManageTime $time;
    private Project $dud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projects = self::getContainer()->get(ManageProjects::class);
        $this->time = self::getContainer()->get(ManageTime::class);
        $this->dud = $this->projects->create('DUD', $this->client->id());
        $this->time->setProjectHourlyRate($this->dud->id(), Money::of('1000', Currency::CZK));
    }

    public function testTheLineTakesTheProjectAndItsTimeTakesTheLinesRate(): void
    {
        $line = $this->hourly($this->deal('DUD'), $this->dud->id());

        self::assertSame($this->dud->id(), $this->reload(DealLine::class, $line->id())->projectId());
        $this->assertRate('1200', RateSource::DealLine);
    }

    public function testAProjectServesOneHourlyLineOnly(): void
    {
        $first = $this->hourly($this->deal('DUD'), $this->dud->id());
        $other = $this->deal('DUD again');

        $refused = $this->assertRefused('project_already_linked', fn (): DealLine => $this->hourly($other, $this->dud->id()));
        self::assertSame(RefusalKind::Conflict, $refused->kind());
        self::assertSame(['dealId' => $first->dealId(), 'lineId' => $first->id()], $refused->details());
        $second = $this->hourly($this->deal('Changes'), null);
        $this->assertRefused('project_already_linked', fn (): DealLine => $this->lines()->update(new UpdateDealLineInput($second->dealId(), $second->id(), projectId: $this->dud->id())));
        // Saving the line that has it keeps it.
        $this->lines()->update(new UpdateDealLineInput($first->dealId(), $first->id(), unitPrice: '1300.00', projectId: $this->dud->id()));
        self::assertSame($this->dud->id(), $this->reload(DealLine::class, $first->id())->projectId());
    }

    public function testTwoLinksOfOneProjectAtOnceRefuseTheSecond(): void
    {
        $this->hourly($this->deal('DUD'), $this->dud->id());
        $second = $this->hourly($this->deal('DUD again'), null);

        // As if the check had passed for both: the unique index stops the second link.
        try {
            self::getContainer()->get(SalesWork::class)->run(function () use ($second): void {
                $this->reload(DealLine::class, $second->id())->linkProject($this->dud->id());
            });
            self::fail('The second link is refused.');
        } catch (SalesRefused $refused) {
            self::assertSame(['project_already_linked', RefusalKind::Conflict], [$refused->reason(), $refused->kind()]);
        }
    }

    public function testAProjectOfAnotherClientIsRefused(): void
    {
        $other = $this->projects->create('Other', $this->company('Beta Example s.r.o.')->id());
        $internal = $this->projects->create('Internal', null);

        $this->assertRefused('project_client_differs', fn (): DealLine => $this->hourly($this->deal(), $other->id()));
        self::assertSame($internal->id(), $this->hourly($this->deal(), $internal->id())->projectId(), 'a project of no client');
    }

    public function testADealWithAProjectOfItsClientKeepsItsClient(): void
    {
        $deal = $this->deal('DUD');
        $line = $this->hourly($deal, $this->dud->id());
        $beta = $this->company('Beta Example s.r.o.');

        $refused = $this->assertRefused('client_has_projects', fn (): Deal => $this->deals()->update(new UpdateDealInput($deal->id(), clientPartyId: $beta->id())));
        self::assertSame(['lineId' => $line->id(), 'projectId' => $this->dud->id()], $refused->details());

        // A project of no client goes with any client; so does a deal once the project is unlinked.
        $this->lines()->update(new UpdateDealLineInput($deal->id(), $line->id(), projectId: $this->projects->create('Internal', null)->id()));
        self::assertSame($beta->id(), $this->deals()->update(new UpdateDealInput($deal->id(), clientPartyId: $beta->id()))->clientPartyId());
    }

    public function testThePickerOffersTheClientsProjectsAndThoseOfNoClient(): void
    {
        $this->projects->create('Internal', null);
        $this->projects->create('Other', $this->company('Beta Example s.r.o.')->id());
        $archived = $this->projects->create('Archived', null);
        $this->projects->archive($archived->id());

        $names = array_map(static fn (LineProject $project): string => $project->name, self::getContainer()->get(LineProjects::class)->ofClient($this->client->id()));

        self::assertSame(['DUD', 'Internal'], $names);
    }

    public function testTheRateIsReadOncePerProjectForARequest(): void
    {
        $line = $this->hourly($this->deal('DUD'), $this->dud->id());
        $rates = self::getContainer()->get(DealLineRates::class);
        $first = $rates->rateFor($this->dud->id());

        $this->lines()->update(new UpdateDealLineInput($line->dealId(), $line->id(), unitPrice: '1300.00'));
        $again = $rates->rateFor($this->dud->id());

        self::assertNotNull($first);
        self::assertTrue($first->equals(Money::of('1200', Currency::CZK)));
        self::assertSame($first, $again, 'kept for the request');

        $this->assertRate('1300', RateSource::DealLine);
    }

    public function testOnlyAnHourlyLineOfAKnownActiveProjectLinks(): void
    {
        $deal = $this->deal();
        $this->assertRefused('project_only_on_time_based', fn (): DealLine => $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Audit', unitPrice: '100.00', projectId: $this->dud->id())));
        $this->assertRefused('project_unknown', fn (): DealLine => $this->hourly($deal, '01900000-0000-7000-8000-000000000000'));
        $archived = $this->projects->create('Old', $this->client->id());
        $this->projects->archive($archived->id());
        $this->assertRefused('project_archived', fn (): DealLine => $this->hourly($deal, $archived->id()));

        // A linked line made another kind is refused until the project is unlinked; the line stays as it was.
        $line = $this->hourly($deal, $this->dud->id());
        $this->assertRefused('project_only_on_time_based', fn (): DealLine => $this->lines()->update(new UpdateDealLineInput($deal->id(), $line->id(), unit: 'piece', quantity: '1', billingKind: 'one_off')));
        $kept = $this->reload(DealLine::class, $line->id());
        self::assertSame(['time_based', $this->dud->id()], [$kept->billingKind()->value, $kept->projectId()]);
        $this->lines()->update(new UpdateDealLineInput($deal->id(), $line->id(), unit: 'piece', quantity: '1', billingKind: 'one_off', projectId: null));
        self::assertNull($this->reload(DealLine::class, $line->id())->projectId());
    }

    public function testUnlinkingTheProjectFallsBackToTheProjectRate(): void
    {
        $line = $this->hourly($this->deal('DUD'), $this->dud->id());

        $this->lines()->update(new UpdateDealLineInput($line->dealId(), $line->id(), projectId: null));

        $this->assertRate('1000', RateSource::Project);
    }

    public function testALostOrTrashedDealGivesNoRate(): void
    {
        $lost = $this->deal('DUD');
        $this->hourly($lost, $this->dud->id());
        $this->deals()->lose(new LoseDealInput($lost->id(), $this->reasons['price']->id()));
        $this->assertRate('1000', RateSource::Project);

        $this->deals()->reopen(new ReopenDealInput($lost->id()));
        $this->assertRate('1200', RateSource::DealLine);
        $this->deals()->win(new WinDealInput($lost->id()));
        $this->assertRate('1200', RateSource::DealLine, 'a won deal gives it too');

        $this->deals()->trash(new TrashDealInput($lost->id()));
        $this->assertRate('1000', RateSource::Project);
    }

    public function testTheDealNamesTheProjectAndTheBillableTimeLoggedOnIt(): void
    {
        $deal = $this->deal('DUD');
        $line = $this->hourly($deal, $this->dud->id());
        $this->typedLine($deal);
        $this->time->log($this->dud->id(), null, new \DateTimeImmutable('2026-11-20'), 90, billable: true);
        $this->time->log($this->dud->id(), null, new \DateTimeImmutable('2026-11-21'), 60, billable: false);

        $json = self::getContainer()->get(SalesJson::class)->deal(self::getContainer()->get(DealReads::class)->deal($deal->id()));

        $lines = $json['lines'];
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        [$hourly, $audit] = array_values($lines);
        self::assertIsArray($hourly);
        self::assertIsArray($audit);
        self::assertSame([$line->id(), ['id' => $this->dud->id(), 'name' => 'DUD'], 90], [$hourly['id'], $hourly['project'], $hourly['logged_billable_minutes']]);
        self::assertSame([null, null], [$audit['project'], $audit['logged_billable_minutes']], 'a line without a project');
    }

    public function testTheProjectHeaderNamesTheDealWhileItGivesTheRate(): void
    {
        $deal = $this->deal('DUD');
        $this->hourly($deal, $this->dud->id());
        $facts = self::getContainer()->get(DealProjectFacts::class);
        $context = ['projectId' => $this->dud->id(), 'clientId' => $this->client->id(), 'return' => '/projects/'.$this->dud->id()];

        $items = [...$facts->items(ProjectSlots::HEADER_FACTS, $context)];
        self::assertCount(1, $items);
        self::assertSame(['DUD', 'DUD development'], [$items[0]->data['deal'], $items[0]->data['line']]);
        self::assertSame([], [...$facts->items(ProjectSlots::SECTIONS, $context)], 'another slot');

        $this->deals()->lose(new LoseDealInput($deal->id(), $this->reasons['price']->id()));
        self::assertSame([], [...$facts->items(ProjectSlots::HEADER_FACTS, $context)], 'a lost deal gives no rate');
        $this->deals()->reopen(new ReopenDealInput($deal->id()));

        $this->signIn($this->guest);
        self::assertSame([], [...$facts->items(ProjectSlots::HEADER_FACTS, $context)], 'a guest works with no deals');
    }

    public function testAFollowUpDealCopiesTheLineWithoutItsProject(): void
    {
        $line = $this->hourly($this->deal('DUD'), $this->dud->id());

        self::assertNull($line->copyFor($this->deal('DUD next year')->id(), 0)->projectId());
    }

    private function hourly(Deal $deal, ?string $projectId): DealLine
    {
        return $this->lines()->add(new AddDealLineInput($deal->id(), name: 'DUD development', unitPrice: '1200.00', billingKind: 'time_based', projectId: $projectId));
    }

    private function assertRate(string $amount, RateSource $source, string $message = ''): void
    {
        $this->em->clear();
        // Each check stands for a request of its own.
        self::getContainer()->get(DealLineRates::class)->reset();
        $resolved = self::getContainer()->get(RateResolver::class)->resolve($this->dud->id(), $this->owner->id());

        self::assertNotNull($resolved, $message);
        self::assertTrue(Money::of($amount, Currency::CZK)->equals($resolved->rate), $message);
        self::assertSame($source, $resolved->source, $message);
    }
}
