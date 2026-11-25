<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Foresi\Work\Contract\Field\ProjectExtension;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Task;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The custom fields of a project and of a task (ADR 0045) in the index of projects and
 * the task list of a project: a column, a filter and the search, with the test-only
 * fields lent to the record. The task list reads its tasks whole, so the query matches
 * the conditions on custom fields and the rest is matched on the rows.
 */
final class WorkCustomFieldsTest extends WebTestCase
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

    public function testTheIndexOfProjectsShowsFiltersAndSearchesTheFields(): void
    {
        $fields = LentFields::lend(self::getContainer(), ProjectExtension::RECORD);
        $this->signIn();
        $website = $this->project('Website');
        $shop = $this->project('Shop');
        $this->project('Intranet');
        $fields->write(ProjectExtension::RECORD, $website, ['test_fields_code' => 'WEB-1', 'test_fields_tags' => ['red']]);
        $fields->write(ProjectExtension::RECORD, $shop, ['test_fields_tags' => ['blue']]);
        $this->em->flush();

        $red = $this->client->request('GET', '/projects?cols=name,test_fields_code&f='.WorkingFilter::encode(ViewFilter::all(new Condition('test_fields_tags', 'has_any_of', ['red']))))->filter('table')->text();
        $found = $this->client->request('GET', '/projects?q=web-1')->filter('table')->text();

        self::assertStringContainsString('WEB-1', $red);
        self::assertStringNotContainsString('Shop', $red);
        self::assertStringContainsString('Website', $found);
        self::assertStringNotContainsString('Intranet', $found);
    }

    public function testTheTaskListMatchesTheConditionsAndTheSearchOnTheFields(): void
    {
        $fields = LentFields::lend(self::getContainer(), TaskExtension::RECORD);
        $this->signIn();
        $project = $this->project('Website');
        [$brief, $wireframes] = $this->tasks($project, ['Brief', 'Wireframes', 'Launch']);
        $fields->write(TaskExtension::RECORD, $brief, ['test_fields_seats' => 3, 'test_fields_code' => 'BR-7']);
        $fields->write(TaskExtension::RECORD, $wireframes, ['test_fields_seats' => 8]);
        $this->em->flush();

        $more = $this->titles('/projects/'.$project.'?cols=title,test_fields_code&f='.WorkingFilter::encode(ViewFilter::all(new Condition('test_fields_seats', 'gt', 5), new Condition('title', 'contains', 'i'))));
        $found = $this->titles('/projects/'.$project.'?q=br-7');
        $all = $this->titles('/projects/'.$project.'?f='.WorkingFilter::encode(ViewFilter::all(new Condition('title', 'contains', 'i'))));

        self::assertSame(['Wireframes'], $more, 'Both conditions hold: the field by the query, the title on the row.');
        self::assertSame(['Brief'], $found);
        self::assertSame(['Brief', 'Wireframes'], $all, 'Without a condition on a field every task is matched on its row.');
        self::assertStringContainsString('BR-7', $this->client->request('GET', '/projects/'.$project.'?cols=title,test_fields_code')->filter('table')->text());
    }

    private function signIn(): void
    {
        $this->browser->seed(role: Role::Owner);
        $this->browser->signIn();
    }

    private function project(string $name): string
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => $name]));
        self::assertResponseStatusCodeSame(303);
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project WHERE name = ?', [$name]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param list<string> $titles
     *
     * @return list<string> their ids
     */
    private function tasks(string $projectId, array $titles): array
    {
        $project = $this->em->find(Project::class, $projectId) ?? throw new \LogicException('Project.');
        $open = $this->em->getRepository(ProjectStatus::class)->findOneBy(['project' => $project], ['position' => 'ASC']) ?? throw new \LogicException('Status.');
        $userId = $this->browser->em()->getConnection()->fetchOne('SELECT id FROM app_user LIMIT 1');
        self::assertIsString($userId);
        $ids = [];
        foreach ($titles as $position => $title) {
            $task = new Task($project, $title, $open, $position, $userId, new \DateTimeImmutable());
            $this->em->persist($task);
            $ids[] = $task->id();
        }
        $this->em->flush();

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function titles(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-section] tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }
}
