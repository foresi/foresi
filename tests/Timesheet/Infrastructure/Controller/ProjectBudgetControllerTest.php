<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Timesheet\Domain\ProjectBudgetChange;
use Foresi\Work\Domain\Project;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The time budget on a project's page: the Budget tab with its change log and
 * its dialogs, and the budget in the header once it has one.
 */
final class ProjectBudgetControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $browser = new SignedInBrowser($this->client, $this->em);
        $browser->seed(role: Role::Member);
        $browser->signIn();
    }

    public function testAddingEightHoursShowsTheBudgetInTheHeaderAndTheTab(): void
    {
        $id = $this->createProject('DUD');
        $page = $this->client->request('GET', '/projects/'.$id);
        self::assertCount(0, $page->filter('[data-project-budget]'), 'no budget, no fact');
        self::assertContains('Budget', $page->filter('[data-project-tabs] a')->each(static fn ($tab): string => trim($tab->text())));

        $tab = $this->client->request('GET', '/projects/'.$id.'?tab=budget');
        self::assertSelectorTextContains('[data-project-budget-section]', 'No time budget yet');
        $form = $this->client->request('GET', $tab->filter('[data-budget-add]')->attr('href') ?? '')->filter('#budget-change-form')->form();
        $form['hours'] = '8';
        $form['note'] = 'Approved by the client';
        $this->client->submit($form);
        self::assertResponseRedirects('/projects/'.$id.'?tab=budget');

        $tab = $this->client->followRedirect();
        self::assertSame('Budget 8 h · used 0 h · left 8 h', trim(preg_replace('/\s+/u', ' ', $tab->filter('[data-project-facts] [data-project-budget]')->text()) ?? ''));
        self::assertSelectorTextContains('[data-budget-changes]', 'Approved by the client');
        self::assertSelectorTextContains('[data-budget-changes]', '+8 h');
        self::assertSelectorTextContains('[data-budget-kpis]', 'Used (all time)');
    }

    public function testAWrongHoursValueIsShownOnTheFieldAndTheCountingIsChosen(): void
    {
        $id = $this->createProject('SARAH');
        $form = $this->client->request('GET', '/projects/'.$id.'/budget/changes/new')->filter('#budget-change-form')->form();
        $form['hours'] = 'eight';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#budget-change-form', 'at most two decimal places');

        $form = $this->client->request('GET', '/projects/'.$id.'/budget/counting')->filter('#budget-counting-form')->form();
        $form['counting'] = 'billable';
        $this->client->submit($form);
        self::assertResponseRedirects('/projects/'.$id.'?tab=budget');
        $this->em->clear();
        self::assertSame('billable', $this->em->getConnection()->fetchOne('SELECT budget_counting FROM project_time_setting WHERE project_id = ?', [$id]));
    }

    public function testAChangeIsRemovedFromItsRowMenu(): void
    {
        $id = $this->createProject('Website');
        $form = $this->client->request('GET', '/projects/'.$id.'/budget/changes/new')->filter('#budget-change-form')->form();
        $form['hours'] = '2,5';
        $this->client->submit($form);
        $this->em->clear();
        $change = $this->em->getRepository(ProjectBudgetChange::class)->findOneBy(['project' => $id]);
        self::assertInstanceOf(ProjectBudgetChange::class, $change);
        self::assertSame(150, $change->minutes());

        $tab = $this->client->request('GET', '/projects/'.$id.'?tab=budget');
        $remove = $tab->filter('[data-budget-changes] form[action="/projects/'.$id.'/budget/changes/'.$change->id().'/remove"]');
        self::assertCount(1, $remove);
        $this->client->submit($remove->form());
        self::assertResponseRedirects('/projects/'.$id.'?tab=budget');
        $this->em->clear();
        self::assertNull($this->em->getRepository(ProjectBudgetChange::class)->findOneBy(['project' => $id]));
    }

    private function createProject(string $name): string
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => $name]));
        self::assertResponseStatusCodeSame(303);
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project WHERE name = ?', [$name]);
        self::assertIsString($id);

        return $id;
    }
}
