<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Controller;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Work\Domain\Priority;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\TaskWatcher;
use App\Work\Domain\WorkflowTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * My tasks: those I am responsible for, or watch, in groups by when they are
 * due on 20 November 2026, narrowed by client, and their count in the sidebar.
 */
final class MyTasksControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantId $tenant;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em, $this->context);
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
        $link = $crawler->filter('#sidebar [data-nav-group="work"] li > a[href="/my-tasks"]');
        self::assertCount(1, $link);
        self::assertCount(0, $link->filter('[data-nav-count]'));
        self::assertSame(['/projects', '/my-tasks'], $crawler->filter('#sidebar [data-nav-group="work"] li > a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
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

        $crawler = $this->client->request('GET', '/my-tasks');

        self::assertResponseIsSuccessful();
        self::assertSame(['Overdue (1)', 'Today (2)', 'Next 7 days (1)', 'Later (1)'], $crawler->filter('[data-task-group] h2')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertSame(['Call the client', 'Order the hosting'], $this->titles($crawler, 'today'));
        $overdue = $crawler->filter('[data-task-group="overdue"] tbody tr td');
        self::assertSame(['Website', 'To do', 'Overdue 2026-11-18'], array_map(trim(...), $overdue->slice(1, 3)->each(static fn (Crawler $cell): string => $cell->text())));
        self::assertCount(1, $overdue->eq(3)->filter('.bg-badge-red'));
        self::assertSame('/tasks/'.$this->taskId('Fix the form'), $overdue->eq(0)->filter('a')->attr('href'));
        self::assertSame('High', trim($crawler->filter('[data-task-group="today"] tbody tr')->eq(0)->filter('td')->eq(4)->text()));
        self::assertSame(['Write the copy'], $this->titles($crawler, 'upcoming'));
        self::assertSame(['Plan the launch'], $this->titles($crawler, 'later'));
        // Nothing without a date: the group is left out.
        self::assertCount(0, $crawler->filter('[data-task-group="no_date"]'));
        // Overdue and today, in the sidebar.
        self::assertSame('3', trim($crawler->filter('#sidebar a[href="/my-tasks"] [data-nav-count]')->text()));
    }

    public function testTheClientFilterNarrowsBothTabs(): void
    {
        $this->signIn();
        $this->seed();

        $crawler = $this->client->request('GET', '/my-tasks');
        self::assertSame(['All clients', 'Example Client'], $crawler->filter('#filter-client option')->each(static fn (Crawler $option): string => trim($option->text())));
        $crawler = $this->client->submit($crawler->filter('[data-my-tasks-filter]')->form(['client' => $this->clientId()]));

        self::assertSame(['Fix the form', 'Call the client', 'Write the copy', 'Plan the launch'], $crawler->filter('[data-task-group] tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('/my-tasks?tab=watching&client='.$this->clientId(), $crawler->filter('nav a')->reduce(static fn (Crawler $a): bool => 'Watching' === trim($a->text()))->attr('href'));

        $this->client->request('GET', '/my-tasks?tab=watching&client='.$this->clientId());
        self::assertSelectorTextContains('main', 'Nothing here for this client.');
    }

    public function testTheWatchingTabShowsTheTasksIWatch(): void
    {
        $this->signIn();
        $this->seed();

        $crawler = $this->client->request('GET', '/my-tasks?tab=watching');

        self::assertSame(['No date (1)'], $crawler->filter('[data-task-group] h2')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertSame(['Review the budget'], $this->titles($crawler, 'no_date'));
        self::assertSame('watching', $crawler->filter('[data-my-tasks-filter] input[name="tab"]')->attr('value'));
    }

    private function signIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: $role);
        $this->browser->signIn();
    }

    /**
     * Website for Example Client, Other for nobody, and Old, archived: my tasks
     * across them, one of them done, and one task of someone else that I watch.
     */
    private function seed(): void
    {
        $this->context->switchTo($this->tenant);
        try {
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
        } finally {
            $this->context->reset();
        }
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
}
