<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The screens of recurring tasks (plan 37.4): the list in the Projects area, a new one in a
 * dialog that asks for its project first, or starts in the project from its "⋯", its page with
 * the next tasks, a change, pause, resume, a skipped date and the end; a guest is denied.
 */
final class RecurringTaskControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->browser = new SignedInBrowser($this->client, $this->em);
    }

    public function testTheListStartsEmptyInTheProjectsArea(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/recurring-tasks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Recurring tasks');
        self::assertSelectorTextContains('main', 'No recurring tasks yet');
        self::assertCount(2, $crawler->filter('main a[href="/recurring-tasks/new"][data-dialog]'));
        $nav = $crawler->filter('#sidebar a[href="/recurring-tasks"]');
        self::assertCount(1, $nav);
        self::assertSame('page', $nav->attr('aria-current'));
    }

    public function testANewOneAsksForItsProjectFirstThenSetsItUp(): void
    {
        $this->signIn();
        $project = $this->project('Retainer');

        $crawler = $this->client->request('GET', '/recurring-tasks/new');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[data-recurring-task-project] select[name="project"] option[value="'.$project.'"]'));

        $crawler = $this->client->submit($crawler->filter('#recurring-task-form')->form(['project' => $project]));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[data-recurring-task-form]'));
        self::assertSelectorTextContains('main', 'The tasks will go to the project Retainer.');

        // A title is needed, and a number of days is a number.
        $crawler = $this->client->submit($crawler->filter('#recurring-task-form')->form(['title' => ' ', 'create_days_before' => 'x']));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#recurring-task-title-error'));
        self::assertCount(1, $crawler->filter('#recurring-task-create-days-before-error'));

        $monday = new \DateTimeImmutable('monday next week');
        $this->client->submit($crawler->filter('#recurring-task-form')->form([
            'title' => 'Slot {week}',
            'create_days_before' => '0',
            'first_on' => $monday->format('Y-m-d'),
            'subtasks' => "Collect requests\n\nReport",
        ]));
        $id = $this->recurringId('Slot {week}');
        self::assertResponseRedirects('/recurring-tasks/'.$id, 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The recurring task Slot {week} is set up.');
        self::assertSelectorTextContains('[data-recurring-task-state]', 'Active');
        self::assertCount(5, $crawler->filter('[data-recurring-task-next] tbody tr'));
        self::assertSame($monday->format('Y-m-d'), $crawler->filter('[data-recurring-task-next] tbody tr')->first()->attr('data-recurring-task-occurrence'));
        self::assertSame('Slot '.(int) $monday->format('W'), trim($crawler->filter('[data-recurring-task-next] tbody tr td')->eq(1)->text()));
        self::assertSame(['Collect requests', 'Report'], $crawler->filter('[data-recurring-task-subtasks] li')->each(static fn (Crawler $item): string => trim($item->text())));

        $crawler = $this->client->request('GET', '/recurring-tasks');
        self::assertSame(['Slot {week}'], $crawler->filter('[data-recurring-tasks] tbody tr[data-id]')->each(static fn (Crawler $row): string => trim($row->filter('th, td')->first()->text())));
    }

    public function testTheProjectsMoreMenuStartsOneInThatProject(): void
    {
        $this->signIn();
        $project = $this->project('Retainer');

        $crawler = $this->client->request('GET', '/projects/'.$project);
        $link = $crawler->filter('main a[href="/recurring-tasks/new?project='.$project.'"][data-dialog]');
        self::assertCount(1, $link);

        $crawler = $this->client->request('GET', '/recurring-tasks/new?project='.$project);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[data-recurring-task-form] input[name="project"][value="'.$project.'"]'));
    }

    public function testItIsChangedPausedResumedSkippedAndEnded(): void
    {
        $this->signIn();
        $id = $this->recurring();
        $next = new \DateTimeImmutable('monday next week');

        $crawler = $this->client->request('GET', '/recurring-tasks/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('#recurring-task-form')->form(['title' => 'Weekly slot', 'due' => 'days', 'due_days' => '2']));
        self::assertResponseRedirects('/recurring-tasks/'.$id, 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main h1', 'Weekly slot');
        self::assertSelectorTextContains('main', '2 days after the date');

        $this->client->submit($crawler->filter('[data-recurring-task-occurrence="'.$next->format('Y-m-d').'"] form')->form());
        self::assertResponseRedirects('/recurring-tasks/'.$id, 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The task of this date will not be created.');
        self::assertCount(0, $crawler->filter('[data-recurring-task-occurrence="'.$next->format('Y-m-d').'"]'));
        self::assertCount(1, $crawler->filter('[data-recurring-task-skipped]'));

        $this->client->submit($crawler->filter('form[data-recurring-task-pause]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-recurring-task-state]', 'Paused');
        self::assertCount(0, $crawler->filter('[data-recurring-task-next]'));

        $this->client->submit($crawler->filter('form[data-recurring-task-resume]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-recurring-task-state]', 'Active');

        $crawler = $this->client->request('GET', '/recurring-tasks/'.$id.'/end');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('main form')->last()->form());
        self::assertResponseRedirects('/recurring-tasks/'.$id, 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-recurring-task-state]', 'Ended');
        self::assertCount(0, $crawler->filter('#recurring-task-more'));

        // An ended one is not changed any more.
        $this->client->request('GET', '/recurring-tasks/'.$id.'/edit');
        self::assertResponseRedirects('/recurring-tasks/'.$id, 303);
    }

    public function testAWriteWithoutTheTokenIsDenied(): void
    {
        $this->signIn();
        $id = $this->recurring();

        $this->client->request('POST', '/recurring-tasks/'.$id.'/pause', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAGuestIsDenied(): void
    {
        $this->signIn(Role::Guest);

        foreach (['/recurring-tasks', '/recurring-tasks/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
    }

    private function project(string $name): string
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => $name]));
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project WHERE name = ?', [$name]);
        self::assertIsString($id);

        return $id;
    }

    private function recurring(): string
    {
        $project = $this->project('Retainer');
        $crawler = $this->client->request('GET', '/recurring-tasks/new?project='.$project);
        $this->client->submit($crawler->filter('#recurring-task-form')->form(['title' => 'Slot', 'first_on' => new \DateTimeImmutable('monday next week')->format('Y-m-d')]));
        self::assertResponseStatusCodeSame(303);

        return $this->recurringId('Slot');
    }

    private function recurringId(string $title): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM recurring_task WHERE title = ?', [$title]);
        self::assertIsString($id, 'No recurring task '.$title);

        return $id;
    }
}
