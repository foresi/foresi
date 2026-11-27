<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Contacts;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectVisibility;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The client of a project where the address book is installed: picked in the dialog of a
 * new project from any contact, shown under the project's header and in a column of the
 * index, and a condition of the index's filter sheet.
 */
final class ProjectClientScreensTest extends WebTestCase
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

    public function testANewProjectGoesToItsPage(): void
    {
        $this->signIn();
        $client = $this->contact('Example Client');

        $crawler = $this->client->request('GET', '/projects/new');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#project-form [name="workflow"]'));
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website', 'clientId' => $client, 'description' => 'The new site.']));

        $id = $this->projectId('Website');
        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$id);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main h1', 'Website');
        self::assertSame('/contacts/'.$client, $crawler->filter('[data-project-client] a')->attr('href'));
        self::assertSame(['To do', 'In progress', 'Done'], $this->statusNames($id));

        $crawler = $this->client->request('GET', '/projects');
        self::assertSame('Website', trim($crawler->filter('tbody tr td')->eq(0)->filter('[data-row-link]')->text()));
        self::assertSame('Example Client', trim($crawler->filter('tbody tr td')->eq(1)->text()));
        self::assertSame('0', trim($crawler->filter('tbody tr td')->eq(2)->text()));
    }

    public function testAProjectNeedsANameAndAnExistingClient(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/projects/new');

        $crawler = $this->client->submit($crawler->filter('#project-form')->form(['name' => ' ', 'description' => 'Kept.']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter the name of the project.', trim($crawler->filter('#name-error')->text()));
        self::assertSame('true', $crawler->filter('#name')->attr('aria-invalid'));
        self::assertSelectorTextContains('[data-error-summary]', 'Name: Enter the name of the project.');
        self::assertSame('Kept.', $crawler->filter('#description')->text());

        $crawler = $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website', 'clientId' => '01900000-0000-7000-8000-000000000000']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Choose an existing contact as the client.', trim($crawler->filter('#client-error')->text()));
        self::assertSame([], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM project'));
    }

    public function testTheClientFieldOffersAnyContact(): void
    {
        $this->signIn();
        $this->contact('Example Client');

        $this->client->request('GET', '/projects/clients?q=Example');

        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($answer);
        $results = $answer['results'] ?? null;
        self::assertIsArray($results);
        self::assertSame(['Example Client'], array_column($results, 'label'));
    }

    public function testAGuestFindsNoClients(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/projects/clients?q=exa');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheBuilderNarrowsByTheClientAndItsChangesAreSavedAsANewView(): void
    {
        $this->signIn();
        $client = $this->contact('Example Client');
        $this->createProject('Internal');
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website', 'clientId' => $client]));

        $this->client->request('GET', '/projects?'.http_build_query(['conditions' => '1', 'c' => [['field' => 'client', 'op' => 'is', 'value' => $client]]]));
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();

        self::assertSame(['Website'], $this->projectNames($crawler));
        self::assertSame('All, unsaved changes', $this->viewTabs($crawler)[0]);
        self::assertStringContainsString('Example Client', $crawler->filter('main')->text(), 'the chip names the client');
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'For Example']));
        $crawler = $this->client->followRedirect();
        self::assertSame(['Website'], $this->projectNames($crawler));
        self::assertContains('For Example', $this->viewTabs($crawler));
    }

    public function testTheClientConditionsNarrowTheList(): void
    {
        $this->signIn();
        $client = $this->contact('Example Client');
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website', 'clientId' => $client]));
        $this->createProject('Internal');
        $this->createProject('Old site');
        $this->archive('Old site');
        $this->scoped(function (): void {
            $project = $this->em->getRepository(Project::class)->findOneBy(['name' => 'Internal']) ?? throw new \LogicException('Project.');
            $project->changeVisibility(ProjectVisibility::Chosen);
            $this->em->flush();
        });

        $shown = function (array $conditions, string $view = ''): array {
            $this->client->request('GET', '/projects?'.http_build_query(['view' => $view, 'conditions' => '1', 'c' => $conditions]));
            self::assertResponseStatusCodeSame(303);

            return $this->projectNames($this->client->followRedirect());
        };

        self::assertSame(['Internal', 'Website'], $shown([['field' => 'archived', 'op' => 'is', 'value' => '0']]));
        self::assertSame(['Old site'], $shown([['field' => 'archived', 'op' => 'is', 'value' => '1']]));
        self::assertSame(['Internal'], $shown([['field' => 'client', 'op' => 'is_empty']]));
        self::assertSame(['Internal'], $shown([['field' => 'client', 'op' => 'is_not', 'value' => $client]]));
        self::assertSame(['Internal'], $shown([['field' => 'visibility', 'op' => 'is', 'values' => ['chosen']]]));
        self::assertSame(['Website'], $shown([['field' => 'visibility', 'op' => 'is', 'values' => ['organisation']]]));
        self::assertSame([], $shown([['field' => 'archived', 'op' => 'is', 'value' => '1'], ['field' => 'client', 'op' => 'is', 'value' => $client]], 'archived'));
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
    }

    private function createProject(string $name): string
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => $name]));
        self::assertResponseStatusCodeSame(303);

        return $this->projectId($name);
    }

    private function archive(string $name): void
    {
        $this->scoped(function () use ($name): void {
            $project = $this->em->getRepository(Project::class)->findOneBy(['name' => $name]) ?? throw new \LogicException('Project.');
            $project->archive(new \DateTimeImmutable());
            $this->em->flush();
        });
    }

    /**
     * @return list<string>
     */
    private function projectNames(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }

    /**
     * @return list<string>
     */
    private function viewTabs(Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Project views"] a')->each(static fn (Crawler $tab): string => trim($tab->text()));
    }

    private function contact(string $name): string
    {
        return $this->scoped(function () use ($name): string {
            $party = new Party(PartyKind::Person, $name, null, null, '');
            $this->em->persist($party);
            $this->em->flush();

            return $party->id();
        });
    }

    private function projectId(string $name): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project WHERE name = ?', [$name]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<string>
     */
    private function statusNames(string $project): array
    {
        return array_map(static fn (mixed $name): string => \is_string($name) ? $name : throw new \LogicException('A status name.'), $this->em->getConnection()->fetchFirstColumn('SELECT name FROM project_status WHERE project_id = ? ORDER BY position', [$project]));
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function scoped(callable $work): mixed
    {
        return $work();
    }
}
