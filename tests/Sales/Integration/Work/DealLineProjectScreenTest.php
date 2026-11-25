<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Integration\Work;

use Foresi\Sales\Domain\DealLine;
use Foresi\Tests\Sales\Support\SalesScreenTestCase;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageTime;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The project of an hourly line on the deal's screens: picked in the line's
 * dialog among the client's active projects, and shown on the line with the
 * billable time logged on it.
 */
final class DealLineProjectScreenTest extends SalesScreenTestCase
{
    public function testAnHourlyLinePicksTheClientsProjectAndShowsItsLoggedTime(): void
    {
        $deal = $this->newDeal('DUD');
        $this->actAsOwner();
        $projects = self::getContainer()->get(ManageProjects::class);
        $dud = $projects->create('DUD', $this->company->id());
        $projects->create('Internal', null);
        self::getContainer()->get(ManageTime::class)->log($dud->id(), null, new \DateTimeImmutable('2026-11-16'), 150, billable: true);

        $form = $this->client->request('GET', '/sales/deals/'.$deal->id().'/lines/new')->filter('#deal-line-form')->form();
        $field = $form['projectId'];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        self::assertSame(['', $dud->id()], $field->availableOptionValues(), 'no project, or an active one of the client');
        $form['name'] = 'DUD development';
        $form['quantity'] = '';
        $form['unit'] = '';
        $form['unitPrice'] = '1200';
        $kind = $form['billingKind'];
        self::assertInstanceOf(ChoiceFormField::class, $kind);
        $kind->select('time_based');
        $field->select($dud->id());
        $this->client->submit($form);
        self::assertResponseRedirects('/sales/deals/'.$deal->id());

        $this->em()->clear();
        $line = $this->em()->getRepository(DealLine::class)->findOneBy(['dealId' => $deal->id()]);
        self::assertInstanceOf(DealLine::class, $line);
        self::assertSame($dud->id(), $line->projectId());
        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id());
        self::assertSame('/projects/'.$dud->id(), $crawler->filter('[data-deal-lines] table a[data-chip]')->attr('href'));
        self::assertSelectorTextContains('[data-deal-lines] table', 'DUD');
        self::assertSelectorTextContains('[data-deal-lines] table', '2.5 h billable logged');
        self::assertSelectorTextContains('[data-deal-lines] thead', 'Project');

        $edit = $this->client->request('GET', '/sales/deals/'.$deal->id().'/lines/'.$line->id().'/edit');
        self::assertSame($dud->id(), $edit->filter('#projectId option[selected]')->attr('value'));
    }

    public function testALineOfAnotherKindSendsNoProjectAndTheTableHasNoProjectColumn(): void
    {
        $deal = $this->newDeal('Audit');
        $this->actAsOwner();
        $dud = self::getContainer()->get(ManageProjects::class)->create('DUD', $this->company->id());

        $form = $this->client->request('GET', '/sales/deals/'.$deal->id().'/lines/new')->filter('#deal-line-form')->form();
        $form['name'] = 'Audit';
        $form['unitPrice'] = '100';
        $field = $form['projectId'];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        // Hidden for a one-off line, yet sent without JavaScript: the server leaves it out.
        $field->select($dud->id());
        $this->client->submit($form);
        self::assertResponseRedirects('/sales/deals/'.$deal->id());

        $this->em()->clear();
        self::assertNull($this->em()->getRepository(DealLine::class)->findOneBy(['dealId' => $deal->id()])?->projectId());
        $this->client->request('GET', '/sales/deals/'.$deal->id());
        self::assertSelectorTextNotContains('[data-deal-lines] thead', 'Project');
    }
}
