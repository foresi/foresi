<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Membership;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Domain\Label;
use Foresi\Work\Domain\LabelColour;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskLabel;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The list of all tasks on 20 November 2026 (plan 37.1): it opens on Mine, its shipped views
 * hold what they say across the projects, a finished task and one of an archived project are
 * left out, it groups and sorts as its view or its address says, and New task files a task
 * in the project picked, prefilled from the page.
 */
final class TaskListControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $browser = new SignedInBrowser($this->client, $this->em);
        $browser->seed();
        $browser->signIn();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheListOpensOnMineGroupedByWhenTheTasksAreDue(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/tasks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Tasks');
        // My priorities right after Mine; No due date moves under the other views of the Settings sheet.
        self::assertSame(['All open', 'Mine', 'My priorities', 'Overdue', 'Unassigned', 'High and urgent'], $this->tabs($crawler));
        self::assertSame('Mine', trim($crawler->filter('nav[aria-label="Views of the tasks"] a[aria-current="page"]')->text()));
        self::assertSame(['Overdue (1)', 'Today (1)', 'Later (1)'], $crawler->filter('[data-task-group] h2')->each(static fn (Crawler $title): string => trim($title->text())));
        // By priority within a group, so the urgent one of today leads.
        self::assertSame(['Fix the form', 'Call the client', 'Plan the launch'], $this->titles($crawler));
        // New task in the page header, and the area leads with the list.
        self::assertCount(1, $crawler->filter('header [data-quick-task-new], main [data-quick-task-new]'));
        self::assertSame('/tasks', $crawler->filter('#sidebar [data-nav-area="projects"] [data-nav-section] li > a')->first()->attr('href'));
    }

    public function testTheShippedViewsHoldTheTasksOfEveryProject(): void
    {
        $this->seed();

        // All open: every unfinished task of the active projects, mine or not, by due date, the undated last.
        self::assertSame(['Fix the form', 'Call the client', 'Order the hosting', 'Plan the launch', 'Sort the inbox'], $this->titles($this->client->request('GET', '/tasks?view=all')));
        self::assertSame(['Fix the form'], $this->titles($this->client->request('GET', '/tasks?view=overdue')));
        self::assertSame(['Order the hosting', 'Sort the inbox'], $this->titles($this->client->request('GET', '/tasks?view=unassigned')));
        self::assertSame(['Call the client', 'Order the hosting'], $this->titles($this->client->request('GET', '/tasks?view=high')));
        self::assertSame(['Sort the inbox'], $this->titles($this->client->request('GET', '/tasks?view=no_due')));
    }

    /**
     * Martin hid a column on Mine, and after a while it was back (2026-11-26): dragging a column
     * wider sends only the widths, which read the view's own columns over the ones he chose. The
     * columns he hid stay hidden through a drag, a deploy (app:views:sync) and a reload, as he
     * left them on the view and as he saved them.
     */
    public function testAHiddenColumnStaysHiddenAfterAColumnIsDraggedWider(): void
    {
        $this->seed();
        $sync = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel.'))->find('app:views:sync'));
        $hidden = static fn (Crawler $crawler): bool => 0 === $crawler->filter('col[data-column="responsible"]')->count() && $crawler->filter('col[data-column="project"]')->count() > 0;

        // Hidden in the Settings sheet, which sends every column shown.
        self::assertTrue($hidden($this->client->request('GET', '/tasks?view=mine&cols=title,project,client,status,due,priority,labels')));
        $sync->execute([]);
        // Opened again, then a column dragged wider, which writes widths= alone to the address.
        self::assertTrue($hidden($this->client->request('GET', '/tasks?view=mine')));
        self::assertTrue($hidden($this->client->request('GET', '/tasks?view=mine&widths=title:320')), 'A drag keeps the columns remembered.');
        self::assertTrue($hidden($this->client->request('GET', '/tasks?view=mine')), 'The reload keeps them too.');
        self::assertSame('320px', $this->width($this->client->getCrawler(), 'title'), 'And the width dragged.');

        // Saved with the view, the same through a deploy, a drag and a reload.
        $crawler = $this->client->request('GET', '/tasks?view=mine&cols=title,project,client,status,due,priority,labels');
        $this->client->submit($crawler->filter('form[action="/views/save"]')->form());
        self::assertResponseRedirects();
        $sync->execute([]);
        self::assertTrue($hidden($this->client->request('GET', '/tasks?view=mine&widths=project:200')));
        self::assertTrue($hidden($this->client->request('GET', '/tasks?view=mine')));
        self::assertTrue($hidden($this->client->request('GET', '/tasks?view=mine&widths=')), 'Reset goes back to the view as saved.');
    }

    public function testTheListGroupsAndSortsAsTheAddressSays(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/tasks?view=all&group=project&sort=-priority');

        self::assertSame(['Other (2)', 'Website (3)'], $crawler->filter('[data-task-group] h2')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertSame(['Order the hosting', 'Sort the inbox', 'Call the client', 'Fix the form', 'Plan the launch'], $this->titles($crawler));

        $crawler = $this->client->request('GET', '/tasks?view=all&group=responsible&sort=title');
        self::assertSame(['Example Company (3)', 'Unassigned (2)'], $crawler->filter('[data-task-group] h2')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertSame(['Call the client', 'Fix the form', 'Plan the launch', 'Order the hosting', 'Sort the inbox'], $this->titles($crawler));
        // The Settings sheet offers what the list groups by.
        self::assertSame('responsible', $crawler->filter('select[name="group"] option[selected]')->attr('value'));
    }

    public function testTheFilterNarrowsByLabelStatusTypeAndProject(): void
    {
        $this->seed();
        $label = $this->em->getRepository(Label::class)->findOneBy(['name' => 'Design']) ?? throw new \LogicException('Label.');
        $website = $this->em->getRepository(Project::class)->findOneBy(['name' => 'Website']) ?? throw new \LogicException('Project.');

        self::assertSame(['Call the client'], $this->titles($this->filtered(new Condition(TaskLists::LABELS, 'is_any_of', [$label->id()]))));
        // Asking about the status type shows the finished tasks too.
        self::assertSame(['Old bug'], $this->titles($this->filtered(new Condition(TaskLists::STATUS_TYPE, 'is', StatusType::Done->value))));
        $crawler = $this->filtered(new Condition(TaskLists::PROJECT, 'is', $website->id()));
        self::assertSame(['Fix the form', 'Call the client', 'Plan the launch'], $this->titles($crawler));
        // New task goes in the project the filter names.
        self::assertStringContainsString('project='.$website->id(), (string) $crawler->filter('main [data-quick-task-new], header [data-quick-task-new]')->attr('href'));
    }

    public function testNewTaskFilesATaskInThePickedProject(): void
    {
        $this->seed();
        $other = $this->em->getRepository(Project::class)->findOneBy(['name' => 'Other']) ?? throw new \LogicException('Project.');
        $label = $this->em->getRepository(Label::class)->findOneBy(['name' => 'Design']) ?? throw new \LogicException('Label.');

        $crawler = $this->client->request('GET', '/tasks/new?project='.$other->id().'&return=/tasks?view=all');
        self::assertResponseIsSuccessful();
        self::assertSame('Other', $crawler->filter('input[name="projectQuery"]')->attr('value'));
        $token = (string) $crawler->filter('form[data-quick-task] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/tasks/new', ['_token' => $token, 'return' => '/tasks?view=all', 'title' => 'Renew the domain', 'project' => '', 'priority' => 'none']);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/tasks/new', ['_token' => $token, 'return' => '/tasks?view=all', 'title' => 'Renew the domain', 'project' => $other->id(), 'due' => '2026-11-30', 'priority' => 'high', 'labels' => [$label->id()]]);
        self::assertResponseRedirects('/tasks?view=all', 303);
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Renew the domain']) ?? throw new \LogicException('Task.');
        self::assertSame($other->id(), $task->project()->id());
        self::assertSame(Priority::High, $task->priority());
        self::assertSame('2026-11-30', $task->dueDate()?->format('Y-m-d'));
        self::assertEquals(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_label WHERE task_id = ?', [$task->id()]));

        // The project last added to comes first, and is the one a new task starts in.
        $crawler = $this->client->request('GET', '/tasks/new');
        self::assertSame($other->id(), $crawler->filter('input[name="project"]')->attr('value'));
        $this->client->request('GET', '/tasks/projects?new=1&q=');
        self::assertStringStartsWith('{"results":[{"id":"'.$other->id().'"', (string) $this->client->getResponse()->getContent());
    }

    public function testAChangedTaskIsUpdatedNow(): void
    {
        $this->seed();
        Clock::set(new MockClock('2026-11-22 09:00:00', 'UTC'));
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Sort the inbox']) ?? throw new \LogicException('Task.');
        $task->prioritise(Priority::Low);
        $this->em->flush();

        self::assertSame('2026-11-22 09:00', $task->updatedAt()->format('Y-m-d H:i'));
        self::assertSame(['Sort the inbox'], $this->titles($this->filtered(new Condition(TaskLists::UPDATED, 'on_or_after', '2026-11-21'))));
    }

    public function testAStarredTaskIsOnMyPrioritiesInTheOrderGiven(): void
    {
        $this->seed();
        $crawler = $this->client->request('GET', '/tasks?view=all');
        foreach (['Plan the launch', 'Fix the form', 'Sort the inbox'] as $title) {
            $this->client->submit($this->starOf($crawler, $title)->form());
            self::assertResponseRedirects('/tasks?view=all', 303);
        }

        $crawler = $this->client->request('GET', '/tasks?view=priorities');
        self::assertSame(['Plan the launch', 'Fix the form', 'Sort the inbox'], $this->titles($crawler));
        self::assertCount(3, $crawler->filter('[data-row-reorder-handle]'));
        self::assertSame('true', $this->starOf($crawler, 'Fix the form')->filter('button')->attr('aria-pressed'));
        // Move up and Move down in each row's menu: the first has no way up, the last none down.
        self::assertSame(['Move down', 'Move up', 'Move down', 'Move up'], $crawler->filter('[data-row-actions] button[type="submit"]')->each(static fn (Crawler $item): string => trim($item->text())));

        // A row dropped by its grip before another.
        $sort = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Sort the inbox']) ?? throw new \LogicException('Task.');
        $plan = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Plan the launch']) ?? throw new \LogicException('Task.');
        $token = (string) $crawler->filter('tbody[data-controller="row-reorder"]')->attr('data-row-reorder-token-value');
        $this->client->request('POST', '/tasks/'.$sort->id().'/star/move', ['_token' => $token, 'beforeTaskId' => $plan->id()], server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['Sort the inbox', 'Plan the launch', 'Fix the form'], $this->titles($this->client->request('GET', '/tasks?view=priorities')));

        // Grouped, the list keeps the order but drags nothing.
        self::assertCount(0, $this->client->request('GET', '/tasks?view=priorities&group=project')->filter('[data-row-reorder-handle]'));

        // The task page says it is on My priorities, and takes it off.
        $crawler = $this->client->request('GET', '/tasks/'.$plan->id());
        $star = $crawler->filter('form[data-task-star]');
        self::assertStringContainsString('Remove from My priorities', $star->text());
        $this->client->submit($star->form());
        self::assertSame(['Sort the inbox', 'Fix the form'], $this->titles($this->client->request('GET', '/tasks?view=priorities')));
    }

    /**
     * The star of a task's row.
     */
    private function starOf(Crawler $crawler, string $title): Crawler
    {
        $rows = $crawler->filter('[data-task-group] tbody tr')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), $title));

        return $rows->filter('form[data-cell-toggle]');
    }

    private function filtered(Condition $condition): Crawler
    {
        return $this->client->request('GET', '/tasks?view=all&'.WorkingFilter::PARAM.'='.rawurlencode((string) WorkingFilter::param(ViewFilter::all($condition), new ViewFilter())));
    }

    /**
     * Website for Example Client and Other for nobody, active, Old archived: my tasks of
     * Website, one done, two tasks of Other with no one responsible, and one of Old.
     */
    private function seed(): void
    {
        $me = $this->em->getRepository(Membership::class)->findOneBy([]) ?? throw new \LogicException('Membership.');
        $userId = $me->user()->id();
        $now = new \DateTimeImmutable('2026-11-01');
        $client = new Party(PartyKind::Person, 'Example Client', null, null, '');
        $label = new Label('Design', LabelColour::Green);
        $this->em->persist($client);
        $this->em->persist($label);
        $position = 0;
        foreach (['Website' => $client, 'Other' => null, 'Old' => null] as $name => $for) {
            $project = new Project($name, $for, WorkflowTemplate::Simple, $userId, $now);
            $open = new ProjectStatus($project, 'To do', StatusType::Open, 0);
            $done = new ProjectStatus($project, 'Done', StatusType::Done, 1);
            foreach ([$project, $open, $done] as $entity) {
                $this->em->persist($entity);
            }
            $tasks = match ($name) {
                'Website' => [['Fix the form', '2026-11-18', Priority::None, $open, $me], ['Call the client', '2026-11-20', Priority::Urgent, $open, $me], ['Plan the launch', '2026-12-15', Priority::None, $open, $me], ['Old bug', '2026-11-10', Priority::None, $done, $me]],
                'Other' => [['Order the hosting', '2026-11-20', Priority::High, $open, null], ['Sort the inbox', null, Priority::None, $open, null]],
                default => [['Archived work', '2026-11-19', Priority::High, $open, null]],
            };
            foreach ($tasks as [$title, $due, $priority, $status, $responsible]) {
                $task = new Task($project, $title, $status, $position++, $userId, $now);
                $task->assign($responsible);
                $task->dueOn(null === $due ? null : new \DateTimeImmutable($due));
                $task->prioritise($priority);
                $this->em->persist($task);
                if ('Call the client' === $title) {
                    $this->em->persist(new TaskLabel($task, $label));
                }
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
        return $crawler->filter('nav[aria-label="Views of the tasks"] a')->each(static fn (Crawler $tab): string => trim((string) preg_replace('/\s+/', ' ', $tab->text())));
    }

    /**
     * The width a column is drawn at, such as 320px; null when it sizes to its content.
     */
    private function width(Crawler $crawler, string $column): ?string
    {
        $style = $crawler->filter('col[data-column="'.$column.'"]')->attr('style');

        return null === $style ? null : (1 === preg_match('/--col-w:\s*(\d+px)/', $style, $match) ? $match[1] : null);
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('[data-task-group] tbody tr [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }
}
