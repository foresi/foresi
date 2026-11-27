<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Contacts;

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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The client of a task's project in the list of all tasks, where the address book is
 * installed: a column, a grouping and a condition of the filter, on 20 November 2026.
 */
final class TaskListClientTest extends WebTestCase
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

    public function testTheListGroupsByTheClient(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/tasks?view=all&group=client');

        self::assertSame(['Example Client (3)', 'No client (2)'], $crawler->filter('[data-task-group] h2')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertContains('client', $crawler->filter('select[name="group"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
    }

    public function testTheFilterNarrowsByTheClientAndNewTaskGoesInItsProject(): void
    {
        $this->seed();
        $client = $this->em->getRepository(Party::class)->findOneBy(['displayName' => 'Example Client']) ?? throw new \LogicException('Client.');
        $website = $this->em->getRepository(Project::class)->findOneBy(['name' => 'Website']) ?? throw new \LogicException('Project.');

        $crawler = $this->filtered(new Condition(TaskLists::CLIENT, 'is', $client->id()));

        self::assertSame(['Fix the form', 'Call the client', 'Plan the launch'], $this->titles($crawler));
        self::assertStringContainsString('project='.$website->id(), (string) $crawler->filter('main [data-quick-task-new], header [data-quick-task-new]')->attr('href'));
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
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('[data-task-group] tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }
}
