<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The task counts of the projects index (plan 37.3): open, overdue, unassigned and the
 * next due date, each sortable and filterable, each count opening its tasks in the list
 * of all tasks. The API and MCP are in WorkReadApiTest.
 */
final class ProjectCountsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->browser = new SignedInBrowser($this->client, $this->em);
    }

    public function testEachProjectCountsItsOpenOverdueAndUnassignedTasksAndItsNextDueDate(): void
    {
        $this->signIn();
        [$website] = $this->seed();

        $rows = $this->rows($this->client->request('GET', '/projects'));

        // Website: Brief (unassigned, due yesterday), Wireframes (mine, due today) and its subtask Logo (unassigned);
        // Launch is done and the trashed Old draft counts for nothing, nor do their dates.
        self::assertSame([
            'Mobile app' => ['open' => '1', 'overdue' => '0', 'unassigned' => '0', 'due' => $this->day('+5 days')],
            'Website' => ['open' => '3', 'overdue' => '1', 'unassigned' => '2', 'due' => $this->day('-1 day')],
        ], array_intersect_key($this->counts($rows), ['Mobile app' => true, 'Website' => true]));
        self::assertSame(['open' => '0', 'overdue' => '0', 'unassigned' => '0'], array_slice($this->counts($rows)['Empty'], 0, 3));
        self::assertSame(['Empty', 'Mobile app', 'Website'], array_keys($this->counts($rows)));
        self::assertSame($website, $this->projectOf('Website'));
    }

    public function testACountAboveZeroOpensItsTasksInTheListOfAllTasksAndZeroOpensNothing(): void
    {
        $this->signIn();
        [$website] = $this->seed();

        $crawler = $this->client->request('GET', '/projects');
        $row = $crawler->filter('tbody tr')->reduce(static fn (Crawler $tr): bool => str_contains($tr->text(), 'Website'));
        $project = new Condition(TaskLists::PROJECT, 'is', $website);
        self::assertSame('/tasks?f='.WorkingFilter::encode(ViewFilter::all($project)), $this->link($row, 'open'));
        self::assertSame('/tasks?view=overdue&f='.WorkingFilter::encode(ViewFilter::all(new Condition(TaskLists::DUE, 'is', 'overdue'), $project)), $this->link($row, 'overdue'));
        self::assertSame('/tasks?view=unassigned&f='.WorkingFilter::encode(ViewFilter::all(new Condition(TaskLists::RESPONSIBLE, 'is_empty'), $project)), $this->link($row, 'unassigned'));
        $empty = $crawler->filter('tbody tr')->reduce(static fn (Crawler $tr): bool => str_contains($tr->text(), 'Empty'));
        self::assertCount(0, $empty->filter('a[data-chip]'));

        // The link lists what the count counted.
        $crawler = $this->client->request('GET', (string) $this->link($row, 'unassigned'));
        self::assertResponseIsSuccessful();
        self::assertSame(['Brief', 'Logo'], $this->titles($crawler));
    }

    public function testAnArchivedProjectShowsItsCountsWithoutLinks(): void
    {
        $this->signIn();
        $this->seed();

        $crawler = $this->client->request('GET', '/projects?view=archived');

        self::assertSame(['Archive'], array_keys($this->counts($this->rows($crawler))));
        self::assertSame(['1', '0', '1'], array_slice(array_values($this->counts($this->rows($crawler))['Archive']), 0, 3));
        self::assertCount(0, $crawler->filter('tbody a[data-chip]'));
    }

    public function testTheIndexSortsByEachCountAndTheNextDueDateWithTiesByName(): void
    {
        $this->signIn();
        $this->seed();

        self::assertSame(['Website', 'Mobile app', 'Empty'], $this->names('/projects?sort=-open'));
        self::assertSame(['Empty', 'Mobile app', 'Website'], $this->names('/projects?sort=open'));
        // Equal counts follow the names the same way.
        self::assertSame(['Website', 'Mobile app', 'Empty'], $this->names('/projects?sort=-overdue'));
        self::assertSame(['Empty', 'Mobile app', 'Website'], $this->names('/projects?sort=overdue'));
        self::assertSame(['Website', 'Mobile app', 'Empty'], $this->names('/projects?sort=-unassigned'));
        // No date is last whichever way.
        self::assertSame(['Website', 'Mobile app', 'Empty'], $this->names('/projects?sort=due'));
        self::assertSame(['Mobile app', 'Website', 'Empty'], $this->names('/projects?sort=-due'));
        self::assertSame(['Website', 'Mobile app', 'Empty'], $this->names('/projects?sort=-name'));
        // A sort the index does not know is by name.
        self::assertSame(['Empty', 'Mobile app', 'Website'], $this->names('/projects?sort=client'));

        $crawler = $this->client->request('GET', '/projects');
        self::assertSame('/projects?sort=overdue', $crawler->filter('thead a[href*="sort=overdue"]')->attr('href'));
    }

    public function testTheIndexFiltersOnTheCountsWithProjectsWithNoTaskIncluded(): void
    {
        $this->signIn();
        $this->seed();

        // Zero fails "greater than": the projects with tasks that pass.
        self::assertSame(['Website'], $this->names($this->filtered(new Condition('overdue_tasks', 'gt', 0))));
        // Zero passes "equals": the project with no task is in.
        self::assertSame(['Empty'], $this->names($this->filtered(new Condition('open_tasks', 'eq', 0))));
        // With the archived ones when the filter asks for them.
        self::assertSame(['Archive'], $this->names($this->filtered(new Condition('archived', 'is', true), new Condition('open_tasks', 'gt', 0))));
        self::assertSame(['Mobile app'], $this->names($this->filtered(new Condition('open_tasks', 'le', 1), new Condition('open_tasks', 'ne', 0))));
        self::assertSame(['Mobile app', 'Website'], $this->names($this->filtered(new Condition('open_tasks', 'ge', 1), new Condition('unassigned_tasks', 'lt', 3))));
        self::assertSame(['Empty'], $this->names($this->filtered(new Condition('next_due', 'is_empty'))));
        self::assertSame(['Mobile app'], $this->names($this->filtered(new Condition('next_due', 'after', new \DateTimeImmutable()->format('Y-m-d')))));
        self::assertSame(['Website'], $this->names($this->filtered(new Condition('unassigned_tasks', 'between', ['from' => 2, 'to' => 2]), new Condition('billable', 'is', true))));
    }

    public function testAMemberCountsOnlyTheTasksTheySee(): void
    {
        $this->signIn(Role::Member);
        $this->seed(privateTask: true);

        $counts = $this->counts($this->rows($this->client->request('GET', '/projects')));

        // The private Secret of Mobile app is not theirs to see, so it counts for nothing.
        self::assertSame(['1', '0', '0'], array_slice(array_values($counts['Mobile app']), 0, 3));
        self::assertSame(['Website'], $this->names($this->filtered(new Condition('unassigned_tasks', 'gt', 0))));
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
    }

    /**
     * Website (billable): Brief unassigned and due yesterday, Wireframes mine and due today
     * with its subtask Logo unassigned, Launch done and due in a day, Old draft in the trash
     * and overdue. Mobile app: Spec mine and due in 5 days (and the private Secret, unassigned
     * and overdue, when asked). Empty has no task; Archive is archived with one unassigned task.
     *
     * @return array{string, string} the ids of Website and Mobile app
     */
    private function seed(bool $privateTask = false): array
    {
        $me = $this->em->getRepository(Membership::class)->findOneBy([]) ?? throw new \LogicException('Membership.');
        $userId = $me->user()->id();
        $now = new \DateTimeImmutable();
        $project = function (string $name) use ($userId, $now): array {
            $project = new Project($name, null, WorkflowTemplate::Simple, $userId, $now);
            $this->em->persist($project);
            $statuses = [];
            foreach ([['To do', StatusType::Open], ['In progress', StatusType::Active], ['Done', StatusType::Done]] as $position => [$status, $type]) {
                $statuses[] = $status = new ProjectStatus($project, $status, $type, $position);
                $this->em->persist($status);
            }

            return [$project, $statuses];
        };
        $task = function (Project $project, ProjectStatus $status, string $title, ?string $due = null, ?Membership $responsible = null, ?Task $parent = null) use ($userId, $now): Task {
            $task = new Task($project, $title, $status, 0, $userId, $now, null, $parent);
            $task->dueOn(null === $due ? null : new \DateTimeImmutable($due));
            $task->assign($responsible);
            $this->em->persist($task);

            return $task;
        };
        [$website, [$open, $active, $done]] = $project('Website');
        $website->billTimeByDefault(true);
        $task($website, $open, 'Brief', '-1 day');
        $wireframes = $task($website, $active, 'Wireframes', 'today', $me);
        $task($website, $open, 'Logo', null, null, $wireframes);
        $task($website, $done, 'Launch', '+1 day');
        $task($website, $open, 'Old draft', '-9 days')->delete($userId, $now);
        [$mobile, [$mobileOpen]] = $project('Mobile app');
        $task($mobile, $mobileOpen, 'Spec', '+5 days', $me);
        if ($privateTask) {
            $task($mobile, $mobileOpen, 'Secret', '-2 days')->makePrivate(true);
        }
        $project('Empty');
        [$archive, [$archiveOpen]] = $project('Archive');
        $task($archive, $archiveOpen, 'Leftover');
        $archive->archive($now);
        $this->em->flush();

        return [$website->id(), $mobile->id()];
    }

    private function filtered(Condition ...$conditions): string
    {
        return '/projects?f='.WorkingFilter::encode(ViewFilter::all(...$conditions));
    }

    /**
     * @return list<string>
     */
    private function names(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return array_keys($this->counts($this->rows($crawler)));
    }

    /**
     * @return list<array<string, string>> the cells of each row by column key, the name first
     */
    private function rows(Crawler $crawler): array
    {
        $keys = $this->keys($crawler);

        return $crawler->filter('tbody tr')->each(static fn (Crawler $tr): array => array_combine($keys, \array_slice($tr->filter('td')->each(static fn (Crawler $td): string => trim($td->text())), 0, \count($keys))));
    }

    /**
     * @return list<string> the keys of the columns, in their order
     */
    private function keys(Crawler $crawler): array
    {
        return $crawler->filter('colgroup col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column'));
    }

    /**
     * @param list<array<string, string>> $rows
     *
     * @return array<string, array{open: string, overdue: string, unassigned: string, due: string}>
     */
    private function counts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['name']] = ['open' => $row['open'], 'overdue' => $row['overdue'], 'unassigned' => $row['unassigned'], 'due' => $row['due']];
        }

        return $counts;
    }

    private function link(Crawler $row, string $column, ?Crawler $page = null): ?string
    {
        $at = array_search($column, $this->keys($page ?? $this->client->getCrawler()), true);
        self::assertIsInt($at, $column);
        $link = $row->filter('td')->eq($at)->filter('a[data-chip]');

        return 0 === $link->count() ? null : $link->attr('href');
    }

    private function day(string $when): string
    {
        // The English date of the index.
        return new \DateTimeImmutable($when)->format('Y-m-d');
    }

    private function projectOf(string $name): ?string
    {
        return $this->em->getRepository(Project::class)->findOneBy(['name' => $name])?->id();
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr [data-row-link]')->each(static fn (Crawler $link): string => trim($link->text()));
    }
}
