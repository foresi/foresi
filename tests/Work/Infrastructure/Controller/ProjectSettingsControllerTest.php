<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Controller;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Identity\Domain\Role;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\WorkflowTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The settings of a project: its general part and archiving, its sections and
 * its statuses, each change a form of its own that comes back to the page.
 */
final class ProjectSettingsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em, $this->context);
    }

    public function testTheProjectPagesLinkToTheSettings(): void
    {
        $this->signIn(Role::Member);
        $id = $this->createProject('Website');

        foreach (['/projects/'.$id, '/projects/'.$id.'/board'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame('/projects/'.$id.'/settings', $crawler->filter('[data-project-settings]')->attr('href'), $url);
        }
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Settings');
        self::assertSame('Website', $crawler->filter('#name')->attr('value'));
        self::assertSame(['To do', 'In progress', 'Done'], $this->values($crawler, '[data-settings-status] input[name="name"]'));
        self::assertSame(['open', 'active', 'done'], $crawler->filter('[data-settings-status] select[name="type"] option[selected]')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['Open', 'Active', 'Done', 'Closed'], $crawler->filter('#status-add-type option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSelectorTextContains('[data-settings-sections]', 'The project has no sections yet.');

        $this->client->request('GET', '/projects/01900000-0000-7000-8000-000000000000/settings');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestIsDenied(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/projects/01900000-0000-7000-8000-000000000000/settings');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheGeneralPartSavesTheNameTheClientAndTheDescription(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $client = $this->contact('Example Client');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');

        $crawler = $this->client->submit($crawler->filter('#project-settings-form')->form(['name' => ' ', 'description' => 'Kept.']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter the name of the project.', trim($crawler->filter('#name-error')->text()));
        self::assertSame('Kept.', $crawler->filter('#description')->text());

        $crawler = $this->client->submit($crawler->filter('#project-settings-form')->form(['name' => 'Website', 'clientId' => '01900000-0000-7000-8000-000000000000']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Choose an existing contact as the client.', trim($crawler->filter('#client-error')->text()));

        $this->client->submit($crawler->filter('#project-settings-form')->form(['name' => 'New website', 'clientId' => $client, 'description' => 'The new site.']));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Saved.');
        self::assertSame('Example Client', $crawler->filter('#client')->attr('value'));
        self::assertSame(['name' => 'New website', 'client_id' => $client, 'description' => 'The new site.'], $this->em->getConnection()->fetchAssociative('SELECT name, client_id, description FROM project WHERE id = ?', [$id]));
    }

    public function testAnOwnerChoosesWhetherTheTimeOfTheProjectIsBillable(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        // An internal project starts with its time not billable.
        self::assertNull($crawler->filter('#billable')->attr('checked'));
        self::assertSame('New time on this project starts as billable.', trim($crawler->filter('#billable-help')->text()));

        $this->client->submit($crawler->filter('#project-settings-form')->form(['billable' => '1']));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $crawler = $this->client->followRedirect();
        self::assertNotNull($crawler->filter('#billable')->attr('checked'));
        self::assertTrue($this->billable($id));

        // The change is in the history of the project, as its other fields are.
        $this->context->switchTo($this->tenant);
        try {
            $updates = array_values(array_filter(
                self::getContainer()->get(AuditTrail::class)->rootHistory(Project::class, $id),
                static fn (AuditEntry $entry): bool => Project::class === $entry->entityClass() && AuditAction::Updated === $entry->action(),
            ));
        } finally {
            $this->context->reset();
        }
        self::assertSame([false, true], $updates[0]->changes()['billableByDefault'] ?? null);

        $form = $crawler->filter('#project-settings-form')->form();
        $checkbox = $form['billable'];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->untick();
        $this->client->submit($form);
        self::assertFalse($this->billable($id));
    }

    public function testAGuestCannotChangeWhetherTheTimeIsBillable(): void
    {
        $this->signIn(Role::Guest);
        $this->context->switchTo($this->tenant);
        try {
            $userId = $this->em->getConnection()->fetchOne('SELECT id FROM app_user LIMIT 1');
            self::assertIsString($userId);
            $project = new Project('Website', null, WorkflowTemplate::Simple, $userId, new \DateTimeImmutable());
            $this->em->persist($project);
            $this->em->flush();
            $id = $project->id();
        } finally {
            $this->context->reset();
        }

        $this->client->request('POST', '/projects/'.$id.'/settings', ['name' => 'Website', 'billable' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->billable($id));
    }

    public function testAProjectIsArchivedAndRestoredAfterAQuestion(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        $form = $crawler->filter('[data-project-archiving]');
        self::assertSame('confirm-submit', $form->attr('data-controller'));
        self::assertSame('Archive the project Website?', $form->attr('data-confirm-title'));

        $this->client->submit($form->form());
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The project is archived.');
        self::assertNotNull($this->em->getConnection()->fetchOne('SELECT archived_at FROM project WHERE id = ?', [$id]));
        // An archived project takes no new section.
        self::assertCount(0, $crawler->filter('[data-settings-section-add]'));

        $form = $crawler->filter('[data-project-archiving]');
        self::assertStringEndsWith('/restore', (string) $form->attr('action'));
        $this->client->submit($form->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The project is restored.');
        self::assertNull($this->em->getConnection()->fetchOne('SELECT archived_at FROM project WHERE id = ?', [$id]));
    }

    public function testSectionsAreAddedRenamedMovedAndRemoved(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        foreach (['Design', ' ', 'Build', 'Launch'] as $name) {
            $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
            $this->client->submit($crawler->filter('[data-settings-section-add]')->form(['name' => $name]));
            self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        }
        self::assertSame(['Design', 'Build', 'Launch'], $this->sections($id));

        $design = $this->sectionId($id, 'Design');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        $this->client->submit($crawler->filter('[data-settings-section="'.$design.'"] form[action$="/'.$design.'"]')->form(['name' => 'Visual design']));
        self::assertSame(['Visual design', 'Build', 'Launch'], $this->sections($id));

        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        self::assertNotNull($crawler->filter('[data-settings-section="'.$design.'"] form[action$="/move"]')->eq(0)->filter('button')->attr('disabled'));
        $this->move($crawler, '[data-settings-section="'.$design.'"]', 'down');
        self::assertSame(['Build', 'Visual design', 'Launch'], $this->sections($id));
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        $launch = $this->sectionId($id, 'Launch');
        $this->move($crawler, '[data-settings-section="'.$launch.'"]', 'up');
        self::assertSame(['Build', 'Launch', 'Visual design'], $this->sections($id));

        $this->task($id, 'Wireframes', $design);
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        $remove = $crawler->filter('[data-settings-section="'.$design.'"] form[action$="/remove"]');
        self::assertSame('Tasks of the section move to No section.', $remove->attr('data-confirm-text'));
        $this->client->submit($remove->form());
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        self::assertSame(['Build', 'Launch'], $this->sections($id));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT section_id FROM task WHERE title = ?', ['Wireframes']));
    }

    public function testASectionOfAnotherProjectIsNotFound(): void
    {
        $this->signIn();
        $website = $this->createProject('Website');
        $other = $this->createProject('Other');
        $crawler = $this->client->request('GET', '/projects/'.$other.'/settings');
        $this->client->submit($crawler->filter('[data-settings-section-add]')->form(['name' => 'Elsewhere']));
        $crawler = $this->client->request('GET', '/projects/'.$other.'/settings');
        $token = (string) $crawler->filter('[data-settings-section-add] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/projects/'.$website.'/sections/'.$this->sectionId($other, 'Elsewhere').'/remove', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['Elsewhere'], $this->sections($other));
    }

    public function testStatusesAreAddedSavedAndMovedAndTheLastOfATypeIsKept(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        $this->client->submit($crawler->filter('[data-settings-status-add]')->form(['name' => 'Review', 'type' => 'active']));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        self::assertSame([['To do', 'open'], ['In progress', 'active'], ['Done', 'done'], ['Review', 'active']], $this->statuses($id));

        $review = $this->statusId($id, 'Review');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        $this->client->submit($crawler->filter('[data-settings-status="'.$review.'"] form[action$="/'.$review.'"]')->form(['name' => 'Approved', 'type' => 'closed']));
        self::assertSame([['To do', 'open'], ['In progress', 'active'], ['Done', 'done'], ['Approved', 'closed']], $this->statuses($id));

        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        $this->move($crawler, '[data-settings-status="'.$review.'"]', 'up');
        self::assertSame(['To do', 'In progress', 'Approved', 'Done'], array_column($this->statuses($id), 0));

        $done = $this->statusId($id, 'Done');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        $this->client->submit($crawler->filter('[data-settings-status="'.$done.'"] form[action$="/'.$done.'"]')->form(['name' => 'Done', 'type' => 'active']));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The project must keep at least one status of type done.');
        self::assertSame('done', $this->em->getConnection()->fetchOne('SELECT type FROM project_status WHERE id = ?', [$done]));
    }

    public function testARemovedStatusMovesItsTasksToTheChosenOne(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $inProgress = $this->statusId($id, 'In progress');
        $done = $this->statusId($id, 'Done');
        $this->task($id, 'Wireframes', null, $inProgress);

        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        $link = $crawler->filter('[data-settings-status="'.$inProgress.'"] a[data-dialog]');
        self::assertSame('/projects/'.$id.'/statuses/'.$inProgress.'/remove', $link->attr('href'));
        $crawler = $this->client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertSame(['To do', 'Done'], $crawler->filter('#target option')->each(static fn (Crawler $option): string => trim($option->text())));

        $this->client->submit($crawler->filter('#status-remove-form')->form(['target' => $done]));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The status In progress is removed.');
        self::assertSame(['To do', 'Done'], array_column($this->statuses($id), 0));
        self::assertSame($done, $this->em->getConnection()->fetchOne('SELECT status_id FROM task WHERE title = ?', ['Wireframes']));

        // The last open status stays, and the dialog says why.
        $toDo = $this->statusId($id, 'To do');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/statuses/'.$toDo.'/remove');
        $crawler = $this->client->submit($crawler->filter('#status-remove-form')->form(['target' => $done]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('The project must keep at least one status of type open.', trim($crawler->filter('[data-error-summary]')->text()));
        self::assertSame(['To do', 'Done'], array_column($this->statuses($id), 0));
    }

    private function signIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: $role);
        $this->browser->signIn();
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

    private function move(Crawler $crawler, string $item, string $direction): void
    {
        $this->client->submit($crawler->filter($item.' form[action$="/move"]')->reduce(static fn (Crawler $form): bool => $direction === $form->filter('input[name="direction"]')->attr('value'))->form());
        self::assertResponseRedirects();
    }

    private function task(string $projectId, string $title, ?string $sectionId, ?string $statusId = null): void
    {
        $this->context->switchTo($this->tenant);
        try {
            $project = $this->em->find(Project::class, $projectId) ?? throw new \LogicException('Project.');
            $status = null === $statusId
                ? $this->em->getRepository(ProjectStatus::class)->findOneBy(['project' => $project], ['position' => 'ASC'])
                : $this->em->find(ProjectStatus::class, $statusId);
            $section = null === $sectionId ? null : $this->em->find(Section::class, $sectionId);
            self::assertInstanceOf(ProjectStatus::class, $status);
            $userId = $this->em->getConnection()->fetchOne('SELECT id FROM app_user LIMIT 1');
            self::assertIsString($userId);
            $this->em->persist(new Task($project, $title, $status, 0, $userId, new \DateTimeImmutable(), $section));
            $this->em->flush();
        } finally {
            $this->context->reset();
        }
    }

    private function billable(string $project): bool
    {
        return (bool) $this->em->getConnection()->fetchOne('SELECT billable_by_default FROM project WHERE id = ?', [$project]);
    }

    private function contact(string $name): string
    {
        $this->context->switchTo($this->tenant);
        try {
            $party = new Party(PartyKind::Person, $name, null, null, '');
            $this->em->persist($party);
            $this->em->flush();

            return $party->id();
        } finally {
            $this->context->reset();
        }
    }

    /**
     * @return list<string>
     */
    private function values(Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector)->each(static fn (Crawler $input): string => (string) $input->attr('value'));
    }

    /**
     * @return list<string>
     */
    private function sections(string $project): array
    {
        return array_map($this->text(...), $this->em->getConnection()->fetchFirstColumn('SELECT name FROM project_section WHERE project_id = ? ORDER BY position', [$project]));
    }

    /**
     * @return list<array{string, string}>
     */
    private function statuses(string $project): array
    {
        return array_map(fn (array $row): array => [$this->text($row[0] ?? null), $this->text($row[1] ?? null)], $this->em->getConnection()->fetchAllNumeric('SELECT name, type FROM project_status WHERE project_id = ? ORDER BY position', [$project]));
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('A text column.');
    }

    private function sectionId(string $project, string $name): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project_section WHERE project_id = ? AND name = ?', [$project, $name]);
        self::assertIsString($id);

        return $id;
    }

    private function statusId(string $project, string $name): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project_status WHERE project_id = ? AND name = ?', [$project, $name]);
        self::assertIsString($id);

        return $id;
    }
}
