<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Integration\Timesheet;

use Foresi\Deals\Domain\DealLine;
use Foresi\Tests\Deals\Support\SalesScreenTestCase;
use Foresi\Timesheet\Application\ManageTime;
use Foresi\Work\Application\ManageProjects;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The project of an hourly line on the deal's screens: picked in the line's
 * dialog among the client's active projects, and shown on the line with the
 * billable time logged on it; and the deal in the header of the project.
 */
final class DealLineProjectScreenTest extends SalesScreenTestCase
{
    public function testAnHourlyLinePicksTheClientsProjectAndShowsItsLoggedTime(): void
    {
        $deal = $this->newDeal('DUD');
        $this->actAsOwner();
        $projects = self::getContainer()->get(ManageProjects::class);
        $dud = $projects->create('DUD', $this->company->id());
        $internal = $projects->create('Internal', null);
        self::getContainer()->get(ManageTime::class)->log($dud->id(), null, new \DateTimeImmutable('2026-11-16'), 150, billable: true);

        $form = $this->client->request('GET', '/deals/'.$deal->id().'/lines/new')->filter('#deal-line-form')->form();
        $field = $form['projectId'];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        self::assertSame(['', $dud->id(), $internal->id()], $field->availableOptionValues(), 'no project, or an active one of the client or of no client');
        $form['name'] = 'DUD development';
        $form['quantity'] = '';
        $form['unit'] = '';
        $form['unitPrice'] = '1200';
        $kind = $form['billingKind'];
        self::assertInstanceOf(ChoiceFormField::class, $kind);
        $kind->select('time_based');
        $field->select($dud->id());
        $this->client->submit($form);
        self::assertResponseRedirects('/deals/'.$deal->id());

        $this->em()->clear();
        $line = $this->em()->getRepository(DealLine::class)->findOneBy(['dealId' => $deal->id()]);
        self::assertInstanceOf(DealLine::class, $line);
        self::assertSame($dud->id(), $line->projectId());
        $crawler = $this->client->request('GET', '/deals/'.$deal->id());
        self::assertSame('/projects/'.$dud->id(), $crawler->filter('[data-deal-lines] table a[data-chip]')->attr('href'));
        self::assertSelectorTextContains('[data-deal-lines] table', 'DUD');
        self::assertSelectorTextContains('[data-deal-lines] table', '2.5 h billable logged');
        self::assertSelectorTextContains('[data-deal-lines] thead', 'Project');

        // The project's header names the deal, the line and its rate, the deal linked to its page.
        $project = $this->client->request('GET', '/projects/'.$dud->id());
        $fact = $project->filter('[data-project-facts] [data-project-deal]');
        self::assertCount(1, $fact);
        self::assertSame('/deals/'.$deal->id(), $fact->filter('a')->attr('href'));
        self::assertMatchesRegularExpression('/^Deal: DUD · DUD development · 1[\s,.\x{a0}\x{202f}]?200[^·]* \/ h$/u', trim(preg_replace('/\s+/u', ' ', $fact->text()) ?? ''));

        $edit = $this->client->request('GET', '/deals/'.$deal->id().'/lines/'.$line->id().'/edit');
        self::assertSame($dud->id(), $edit->filter('#projectId option[selected]')->attr('value'));
    }

    public function testALineOfAnotherKindSendsNoProjectAndTheTableHasNoProjectColumn(): void
    {
        $deal = $this->newDeal('Audit');
        $this->actAsOwner();
        $dud = self::getContainer()->get(ManageProjects::class)->create('DUD', $this->company->id());

        $form = $this->client->request('GET', '/deals/'.$deal->id().'/lines/new')->filter('#deal-line-form')->form();
        $form['name'] = 'Audit';
        $form['unitPrice'] = '100';
        $field = $form['projectId'];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        // Hidden for a one-off line, yet sent without JavaScript: the server leaves it out.
        $field->select($dud->id());
        $this->client->submit($form);
        self::assertResponseRedirects('/deals/'.$deal->id());

        $this->em()->clear();
        self::assertNull($this->em()->getRepository(DealLine::class)->findOneBy(['dealId' => $deal->id()])?->projectId());
        $this->client->request('GET', '/deals/'.$deal->id());
        self::assertSelectorTextNotContains('[data-deal-lines] thead', 'Project');
    }
}
