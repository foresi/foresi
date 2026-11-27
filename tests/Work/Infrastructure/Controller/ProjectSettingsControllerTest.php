<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\Task;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The settings of a project: its general part and archiving, its sections and
 * its statuses, each change a form of its own that comes back to the page.
 */
final class ProjectSettingsControllerTest extends WebTestCase
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
        self::assertSame('/projects/'.$id.'/statuses', $crawler->filter('[data-settings-statuses] [data-settings-status-add]')->attr('href'));
        self::assertSame('/projects/'.$id.'/sections', $crawler->filter('[data-settings-sections] [data-settings-section-add]')->attr('href'));
        self::assertCount(0, $crawler->filter('main input#section-add, main input#status-add'));
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

    public function testTheGeneralPartSavesTheNameAndTheDescription(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');

        $crawler = $this->client->submit($crawler->filter('#project-settings-form')->form(['name' => ' ', 'description' => 'Kept.']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter the name of the project.', trim($crawler->filter('#name-error')->text()));
        self::assertSame('Kept.', $crawler->filter('#description')->text());

        $this->client->submit($crawler->filter('#project-settings-form')->form(['name' => 'New website', 'description' => 'The new site.']));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Saved.');
        self::assertSame(['name' => 'New website', 'description' => 'The new site.'], $this->em->getConnection()->fetchAssociative('SELECT name, description FROM project WHERE id = ?', [$id]));
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
        foreach (['Design', 'Build', 'Launch'] as $name) {
            $this->addSection($id, $name);
            self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        }
        self::assertSame(['Design', 'Build', 'Launch'], $this->sections($id));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The section Launch is added.');

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
        $this->addSection($other, 'Elsewhere');
        $crawler = $this->client->request('GET', '/projects/'.$other.'/settings');
        $token = (string) $crawler->filter('[data-settings-section] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/projects/'.$website.'/sections/'.$this->sectionId($other, 'Elsewhere').'/remove', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['Elsewhere'], $this->sections($other));
    }

    public function testStatusesAreAddedSavedAndMovedAndTheLastOfATypeIsKept(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $crawler = $this->dialog('/projects/'.$id.'/statuses');
        self::assertSame(['Open', 'Active', 'Done', 'Closed'], $crawler->filter('#status-type option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame('open', $crawler->filter('#status-type option[selected]')->attr('value'));
        $this->client->submit($crawler->filter('form[data-settings-status-new]')->form(['name' => 'Review', 'type' => 'active']));
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

    public function testANewSectionAndANewStatusAreNamedInADialogThatKeepsItsErrors(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');

        $crawler = $this->dialog('/projects/'.$id.'/sections');
        self::assertResponseIsSuccessful();
        self::assertSame('Add a section', trim($crawler->filter('dialog#dialog-page h2')->text()));
        self::assertCount(0, $crawler->filter('main'));
        $this->client->submit($crawler->filter('form[data-settings-section-new]')->form(['name' => '  ']), [], ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->getCrawler();
        self::assertSelectorTextContains('dialog#dialog-page', 'Enter a name for the section.');
        self::assertSame('true', $crawler->filter('#section-name')->attr('aria-invalid'));
        self::assertSame([], $this->sections($id));

        // A dialog answer that succeeds names the page to load next, which tells what happened.
        $this->client->submit($crawler->filter('form[data-settings-section-new]')->form(['name' => 'Design']), [], ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(['location' => '/projects/'.$id.'/settings'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(['Design'], $this->sections($id));

        $crawler = $this->dialog('/projects/'.$id.'/statuses');
        $this->client->submit($crawler->filter('form[data-settings-status-new]')->form(['name' => '', 'type' => 'done']), [], ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->getCrawler();
        self::assertSelectorTextContains('dialog#dialog-page', 'Enter a name for the status.');
        self::assertSame('done', $crawler->filter('#status-type option[selected]')->attr('value'));
        self::assertCount(3, $this->statuses($id));

        // Without JavaScript the same URLs are pages of their own.
        $this->client->request('GET', '/projects/'.$id.'/statuses');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Add a status');
    }

    public function testAnArchivedProjectRefusesANewSectionInTheDialog(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $this->em->getConnection()->executeStatement('UPDATE project SET archived_at = ? WHERE id = ?', ['2026-01-01 00:00:00', $id]);

        $crawler = $this->dialog('/projects/'.$id.'/sections');
        $this->client->submit($crawler->filter('form[data-settings-section-new]')->form(['name' => 'Design']), [], ['HTTP_X_FORESI_DIALOG' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('dialog#dialog-page', 'The project is archived.');
        self::assertSame([], $this->sections($id));
    }

    public function testAGuestCannotOpenTheNewSectionOrStatusDialog(): void
    {
        $this->signIn(Role::Guest);
        $id = '01900000-0000-7000-8000-000000000000';

        foreach (['/projects/'.$id.'/sections', '/projects/'.$id.'/statuses'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
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

    public function testAWorkflowTemplateReplacesTheStatusesAfterAQuestion(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $this->task($id, 'Wireframes', null, $this->statusId($id, 'In progress'));
        $this->task($id, 'Brief', null, $this->statusId($id, 'Done'));
        $this->em->getConnection()->executeStatement('UPDATE task SET completed_at = ? WHERE title = ?', ['2026-11-01 10:00:00', 'Brief']);

        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        $link = $crawler->filter('[data-settings-apply-workflow]');
        self::assertSame('/projects/'.$id.'/workflow', $link->attr('href'));
        $crawler = $this->client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertSame(['simple', 'development'], $crawler->filter('input[name="workflow"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        self::assertSame('simple', $crawler->filter('input[name="workflow"][checked]')->attr('value'));
        self::assertSelectorTextContains('#workflow-apply-form', 'closed tasks go to done');

        $this->client->submit($crawler->filter('#workflow-apply-form')->form(['workflow' => 'development']));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The statuses of the project now follow the Development template.');
        self::assertSame([['Open', 'open'], ['In progress', 'active'], ['Review', 'active'], ['Testing', 'active'], ['Blocked', 'active'], ['Done', 'done'], ['Closed', 'closed']], $this->statuses($id));
        self::assertSame($this->statusId($id, 'In progress'), $this->em->getConnection()->fetchOne('SELECT status_id FROM task WHERE title = ?', ['Wireframes']));
        self::assertSame($this->statusId($id, 'Done'), $this->em->getConnection()->fetchOne('SELECT status_id FROM task WHERE title = ?', ['Brief']));
        self::assertStringStartsWith('2026-11-01 10:00:00', $this->text($this->em->getConnection()->fetchOne('SELECT completed_at FROM task WHERE title = ?', ['Brief'])));
        self::assertSame('development', $this->em->getConnection()->fetchOne('SELECT workflow_template FROM project WHERE id = ?', [$id]));

        // An unknown template is told in the dialog and changes nothing.
        $crawler = $this->client->request('GET', '/projects/'.$id.'/workflow');
        $token = (string) $crawler->filter('#workflow-apply-form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/projects/'.$id.'/workflow', ['_token' => $token, 'workflow' => 'kanban']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(7, $this->statuses($id));
    }

    public function testNoTemplateIsAppliedWithoutAToken(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');

        $this->client->request('POST', '/projects/'.$id.'/workflow', ['workflow' => 'development']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(3, $this->statuses($id));
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
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project WHERE name = ?', [$name]);
        self::assertIsString($id);

        return $id;
    }

    private function dialog(string $url): Crawler
    {
        return $this->client->request('GET', $url, [], [], ['HTTP_X_FORESI_DIALOG' => '1']);
    }

    private function addSection(string $project, string $name): void
    {
        $crawler = $this->client->request('GET', '/projects/'.$project.'/sections');
        $this->client->submit($crawler->filter('form[data-settings-section-new]')->form(['name' => $name]));
    }

    private function move(Crawler $crawler, string $item, string $direction): void
    {
        $this->client->submit($crawler->filter($item.' form[action$="/move"]')->reduce(static fn (Crawler $form): bool => $direction === $form->filter('input[name="direction"]')->attr('value'))->form());
        self::assertResponseRedirects();
    }

    private function task(string $projectId, string $title, ?string $sectionId, ?string $statusId = null): void
    {
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
