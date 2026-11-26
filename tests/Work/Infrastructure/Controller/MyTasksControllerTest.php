<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskWatcher;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * My tasks: those I am responsible for, or watch, in groups by when they are
 * due on 20 November 2026, on saved views narrowed by client, and their count in
 * the sidebar.
 */
final class MyTasksControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->browser = new SignedInBrowser($this->client, $this->em);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAnEmptyPageSaysSoAndTheSidebarShowsNoNumber(): void
    {
        $this->signIn(Role::Member);

        $crawler = $this->client->request('GET', '/my-tasks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'My tasks');
        self::assertSelectorTextContains('main', 'You have no unfinished tasks');
        // My tasks stays at its address; the area's first item, all tasks, carries my count.
        $link = $crawler->filter('#sidebar [data-nav-area="projects"] [data-nav-section] li > a[href="/tasks"]');
        self::assertCount(1, $link);
        self::assertCount(0, $link->filter('[data-nav-count]'));
        self::assertSame(['/tasks', '/projects', '/meetings', '/time'], $crawler->filter('#sidebar [data-nav-area="projects"] [data-nav-section] li > a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
    }

    public function testAGuestWithNothingSharedHasNoTasks(): void
    {
        $this->signIn(Role::Guest);

        $crawler = $this->client->request('GET', '/my-tasks');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-task-group]'));
    }

    public function testMyTasksAreGroupedByWhenTheyAreDue(): void
    {
        $this->signIn();
        $this->seed();

        $crawler = $this->client->request('GET', '/my-tasks?view=assigned');

        self::assertResponseIsSuccessful();
        self::assertSame(['Overdue (1)', 'Today (2)', 'Next 7 days (1)', 'Later (1)'], $crawler->filter('[data-task-group] h2')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertSame(['Call the client', 'Order the hosting'], $this->titles($crawler, 'today'));
        $overdue = $crawler->filter('[data-task-group="overdue"] tbody tr td');
        // Each property but the project changes in the row.
        self::assertSame(['Website', 'Status: To do', 'Responsible: Example Company', 'Due date: Overdue 2026-11-18'], $overdue->slice(2, 4)->each(static fn (Crawler $cell): string => self::shown($cell)));
        self::assertCount(1, $overdue->eq(5)->filter('[data-inline-edit-trigger] .bg-badge-red'));
        self::assertSame('/tasks/'.$this->taskId('Fix the form'), $overdue->eq(0)->filter('a')->attr('href'));
        // The row opens the task in the sheet.
        self::assertNotNull($overdue->eq(0)->filter('a[data-row-link]')->attr('data-sheet'));
        self::assertSame('/tasks/'.$this->taskId('Fix the form').'/due', $overdue->eq(5)->filter('form')->attr('action'));
        self::assertSame('/my-tasks?view=assigned', $overdue->eq(5)->filter('input[name="return"]')->attr('value'));
        self::assertSame('Priority: High', self::shown($crawler->filter('[data-task-group="today"] tbody tr')->eq(0)->filter('td')->eq(6)));
        self::assertSame(['Write the copy'], $this->titles($crawler, 'upcoming'));
        self::assertSame(['Plan the launch'], $this->titles($crawler, 'later'));
        // Nothing without a date: the group is left out.
        self::assertCount(0, $crawler->filter('[data-task-group="no_date"]'));
        // Overdue and today, in the sidebar.
        self::assertSame('3', trim($crawler->filter('#sidebar a[href="/tasks"] [data-nav-count]')->text()));
    }

    public function testAllHoldsTheTasksIAmResponsibleForAndThoseIWatch(): void
    {
        // Saved views, D12: All, then the tabs the list had, none with a count (D9).
        $this->signIn();
        $this->seed();

        $crawler = $this->client->request('GET', '/my-tasks');

        self::assertSame(['All', 'Assigned to me', 'Watching'], $this->tabs($crawler));
        self::assertSame('page', $crawler->filter('nav[aria-label="Views of my tasks"] a')->eq(0)->attr('aria-current'));
        self::assertSame(['/my-tasks', '/my-tasks?view=assigned', '/my-tasks?view=watching'], $crawler->filter('nav[aria-label="Views of my tasks"] a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertSame(['Overdue (1)', 'Today (2)', 'Next 7 days (1)', 'Later (1)', 'No date (1)'], $crawler->filter('[data-task-group] h2')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertSame(['Review the budget'], $this->titles($crawler, 'no_date'));
        self::assertCount(1, $crawler->filter('[data-filter-sheet-form]'));
    }

    public function testTheWatchingViewShowsTheTasksIWatchAndTheOldTabStillOpensIt(): void
    {
        $this->signIn();
        $this->seed();

        foreach (['/my-tasks?view=watching', '/my-tasks?tab=watching'] as $watching) {
            $crawler = $this->client->request('GET', $watching);

            self::assertSame(['No date (1)'], $crawler->filter('[data-task-group] h2')->each(static fn (Crawler $title): string => trim($title->text())), $watching);
            self::assertSame(['Review the budget'], $this->titles($crawler, 'no_date'), $watching);
            self::assertSame('page', $crawler->filter('nav[aria-label="Views of my tasks"] a')->eq(2)->attr('aria-current'), $watching);
            self::assertSame('watching', $crawler->filter('[data-my-tasks-filter] [data-filter-sheet-form] input[name="view"]')->attr('value'), $watching);
        }
    }

    public function testTheOldClientParameterIsAConditionOfTheBuilder(): void
    {
        $this->signIn();
        $this->seed();

        $crawler = $this->client->request('GET', '/my-tasks?tab=assigned&client='.$this->clientId());

        self::assertSame(['Fix the form', 'Call the client', 'Write the copy', 'Plan the launch'], $crawler->filter('[data-task-group] tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('Assigned to me, unsaved changes', $this->tabs($crawler)[1]);
        // No fixed select sits in the filter sheet above its conditions (spec D11).
        self::assertCount(0, $crawler->filter('select[name="client"]'));
        self::assertStringContainsString('Example Client', $crawler->filter('main')->text());

        $crawler = $this->client->request('GET', '/my-tasks?tab=watching&client='.$this->clientId());
        self::assertSelectorTextContains('main', 'No task matches these conditions.');
        self::assertSame('/my-tasks?view=watching', $crawler->filter('main a')->reduce(static fn (Crawler $a): bool => 'Reset the view' === trim($a->text()))->attr('href'));
        // What is no contact's id narrows nothing, as the sheet ignored it.
        $crawler = $this->client->request('GET', '/my-tasks?view=assigned&client=nobody');
        self::assertCount(5, $crawler->filter('[data-task-group] tbody tr'));
        self::assertSame('Assigned to me', $this->tabs($crawler)[1]);
    }

    public function testTheBuilderNarrowsTheViewAndItsChangesAreSavedAsANewView(): void
    {
        $this->signIn();
        $this->seed();

        $this->client->request('GET', '/my-tasks?'.http_build_query(['view' => 'all', 'client' => 'old', 'conditions' => '1', 'c' => [
            ['field' => 'involvement', 'op' => 'is', 'value' => 'watcher'],
            ['field' => 'client', 'op' => 'is_empty'],
        ]]));
        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('client=', $location);
        self::assertStringNotContainsString('view=', $location, 'All is the bare list.');
        $crawler = $this->client->followRedirect();

        self::assertSame(['Review the budget'], $this->titles($crawler, 'no_date'));
        self::assertSame('All, unsaved changes', $this->tabs($crawler)[0]);
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'Watched, no client']));
        $crawler = $this->client->followRedirect();
        self::assertSame(['Review the budget'], $this->titles($crawler, 'no_date'));
        self::assertContains('Watched, no client', $this->tabs($crawler));
    }

    public function testTheColumnsSheetHidesAColumn(): void
    {
        $this->signIn();
        $this->seed();

        $crawler = $this->client->request('GET', '/my-tasks?view=assigned&cols=title,due');

        self::assertSame(['Title', 'Due date'], $crawler->filter('[data-task-group="today"] table thead th')->each(static fn (Crawler $th): string => trim($th->text())));
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
    }

    /**
     * Website for Example Client, Other for nobody, and Old, archived: my tasks
     * across them, one of them done, and one task of someone else that I watch.
     */
    private function seed(): void
    {
        $me = $this->em->getRepository(Membership::class)->findOneBy([]) ?? throw new \LogicException('Membership.');
        $userId = $me->user()->id();
        $now = new \DateTimeImmutable('2026-11-01');
        $client = new Party(PartyKind::Person, 'Example Client', null, null, '');
        $this->em->persist($client);
        $position = 0;
        foreach (['Website' => $client, 'Other' => null, 'Old' => null] as $name => $for) {
            $project = new Project($name, $for, WorkflowTemplate::Simple, $userId, $now);
            $open = new ProjectStatus($project, 'To do', StatusType::Open, 0);
            $done = new ProjectStatus($project, 'Done', StatusType::Done, 1);
            foreach ([$project, $open, $done] as $entity) {
                $this->em->persist($entity);
            }
            $tasks = match ($name) {
                'Website' => [['Fix the form', '2026-11-18', Priority::None, $open], ['Call the client', '2026-11-20', Priority::High, $open], ['Write the copy', '2026-11-25', Priority::Low, $open], ['Plan the launch', '2026-12-15', Priority::None, $open], ['Old bug', '2026-11-10', Priority::None, $done]],
                'Other' => [['Order the hosting', '2026-11-20', Priority::None, $open]],
                default => [['Archived work', '2026-11-19', Priority::None, $open]],
            };
            foreach ($tasks as [$title, $due, $priority, $status]) {
                $task = new Task($project, $title, $status, $position++, $userId, $now);
                $task->assign($me);
                $task->dueOn(new \DateTimeImmutable($due));
                $task->prioritise($priority);
                $this->em->persist($task);
            }
            if ('Other' === $name) {
                $watched = new Task($project, 'Review the budget', $open, $position++, $userId, $now);
                $this->em->persist($watched);
                $this->em->persist(new TaskWatcher($watched, $me));
            }
            if ('Old' === $name) {
                $project->archive($now);
            }
        }
        $this->em->flush();
    }

    /**
     * @return list<string>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Views of my tasks"] a')->each(static fn (Crawler $tab): string => trim((string) preg_replace('/\s+/', ' ', $tab->text())));
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $crawler, string $group): array
    {
        return $crawler->filter('[data-task-group="'.$group.'"] tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }

    private function clientId(): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM party WHERE display_name = ?', ['Example Client']);
        self::assertIsString($id);

        return $id;
    }

    private function taskId(string $title): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM task WHERE title = ?', [$title]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * What a cell reads as: the text of its picker a screen reader hears (no avatar initials, no dash), else its text.
     */
    private static function shown(Crawler $cell): string
    {
        $trigger = $cell->filter('[data-inline-edit-trigger], [data-person-picker-trigger]');
        if (0 === $trigger->count()) {
            return trim($cell->text());
        }
        $node = $trigger->getNode(0)?->cloneNode(true) ?? throw new \LogicException('A trigger.');
        $hidden = new \DOMXPath($node->ownerDocument ?? throw new \LogicException('A document.'))->query('.//*[@aria-hidden="true"]', $node);
        foreach (false === $hidden ? [] : iterator_to_array($hidden) as $element) {
            if ($element instanceof \DOMElement) {
                $element->parentNode?->removeChild($element);
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $node->textContent));
    }
}
