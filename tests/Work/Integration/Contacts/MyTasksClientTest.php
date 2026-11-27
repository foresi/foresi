<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Contacts;

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
 * My tasks narrowed by the client of their project, where the address book is installed:
 * the old ?client= of the sheet and a client condition of the builder, on 20 November 2026.
 */
final class MyTasksClientTest extends WebTestCase
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

    public function testTheBuilderNarrowsByTheClientAndItsChangesAreSavedAsANewView(): void
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
}
