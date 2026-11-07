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
use App\Ui\Dialog\DialogRequest;
use App\Work\Domain\Label;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Priority;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\TaskLabel;
use App\Work\Domain\WorkflowTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The projects index, a new project in its dialog, the list of a project section
 * by section with its filters, the line that adds a task, and the task page.
 */
final class ProjectControllerTest extends WebTestCase
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

    public function testTheStaffFindTheProjectsInTheSidebarAndStartWithAnEmptyIndex(): void
    {
        $this->signIn(Role::Member);

        $crawler = $this->client->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Projects');
        self::assertContains('/projects', $crawler->filter('#sidebar [data-nav-group="work"] li > a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSelectorTextContains('main', 'No projects yet');
        // The empty state and the header both open the dialog of a new project.
        self::assertCount(2, $crawler->filter('main a[href="/projects/new"][data-dialog]'));
    }

    public function testAGuestIsDenied(): void
    {
        $this->signIn(Role::Guest);

        foreach (['/projects', '/projects/new', '/projects/clients?q=exa'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testANewProjectGoesToItsPage(): void
    {
        $this->signIn();
        $client = $this->contact('Example Client');

        $crawler = $this->client->request('GET', '/projects/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'To do, In progress, Done');
        self::assertSelectorTextContains('main', 'Open, In progress, Review, Testing, Blocked, Done, Closed');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website', 'clientId' => $client, 'workflow' => 'development', 'description' => 'The new site.']));

        $id = $this->projectId('Website');
        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$id);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main h1', 'Website');
        self::assertSame('/contacts/'.$client, $crawler->filter('[data-project-client] a')->attr('href'));
        self::assertSame(['Open', 'In progress', 'Review', 'Testing', 'Blocked', 'Done', 'Closed'], $this->statusNames($id));

        $crawler = $this->client->request('GET', '/projects');
        self::assertSame('Website', trim($crawler->filter('tbody tr td')->eq(0)->filter('[data-row-link]')->text()));
        self::assertSame('Example Client', trim($crawler->filter('tbody tr td')->eq(1)->text()));
        self::assertSame('0', trim($crawler->filter('tbody tr td')->eq(2)->text()));
    }

    public function testTheDialogFinishesWithThePageOfTheProject(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/projects/new', server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog#dialog-page form#project-form'));

        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website']), serverParameters: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame(['location' => '/projects/'.$this->projectId('Website')], json_decode((string) $this->client->getResponse()->getContent(), true));
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

    public function testArchivedProjectsHaveATabOfTheirOwn(): void
    {
        $this->signIn();
        $active = $this->createProject('Website');
        $this->createProject('Old site');
        $this->scoped(function (): void {
            $project = $this->em->getRepository(Project::class)->findOneBy(['name' => 'Old site']) ?? throw new \LogicException('Project.');
            $project->archive(new \DateTimeImmutable());
            $this->em->flush();
        });

        $crawler = $this->client->request('GET', '/projects');
        self::assertSame(['Website'], $crawler->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('/projects/'.$active, $crawler->filter('tbody a[data-row-link]')->attr('href'));

        $crawler = $this->client->request('GET', '/projects?tab=archived');
        self::assertSame(['Old site'], $crawler->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('true', $crawler->filter('[aria-current="page"]')->count() > 0 ? 'true' : 'false');
    }

    public function testTheListShowsTheSectionsInOrderWithSubtasksUnderTheirTask(): void
    {
        $this->signIn();
        $id = $this->seedList();

        $crawler = $this->client->request('GET', '/projects/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSame(['none', $this->sectionId($id, 'Design'), $this->sectionId($id, 'Build')], $crawler->filter('[data-section]')->each(static fn (Crawler $section): string => (string) $section->attr('data-section')));
        self::assertSame(['No section (1)', 'Design (1)', 'Build (0)'], $crawler->filter('[data-section] h2')->each(static fn (Crawler $heading): string => preg_replace('/\s+/', ' ', trim($heading->text())) ?? ''));

        $design = $crawler->filter('[data-section]')->eq(1);
        self::assertSame(['Wireframes', 'Logo'], $design->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertCount(1, $design->filter('tbody tr[data-row-child]'));
        self::assertSame('Logo', trim($design->filter('tbody tr[data-row-child] td')->eq(0)->text()));
        $wireframes = $design->filter('tbody tr')->eq(0)->filter('td');
        self::assertSame('/tasks/'.$this->taskId('Wireframes'), $wireframes->eq(0)->filter('a')->attr('href'));
        self::assertSame('In progress', trim($wireframes->eq(1)->text()));
        self::assertStringContainsString('bg-badge-amber', (string) $wireframes->eq(1)->filter('span')->attr('class'));
        self::assertSame('Example Company', trim($wireframes->eq(2)->filter('[data-avatar-cell] > span')->last()->text()));
        self::assertCount(1, $wireframes->eq(2)->filter('[data-avatar-cell]'));
        self::assertStringStartsWith('Overdue ', trim($wireframes->eq(3)->text()));
        self::assertStringContainsString('bg-badge-red', (string) $wireframes->eq(3)->filter('span')->attr('class'));
        self::assertSame('High', trim($wireframes->eq(4)->text()));
        self::assertSame(['Design'], $wireframes->eq(5)->filter('[data-badges] > *')->each(static fn (Crawler $badge): string => trim($badge->text())));
        // No priority shows nothing, a task without a date shows no date.
        $logo = $design->filter('tbody tr[data-row-child] td');
        self::assertSame('—', trim($logo->eq(4)->text()));
        self::assertSame('—', trim($logo->eq(3)->text()));

        // The finished task is left out until the filter asks for it.
        self::assertStringNotContainsString('Launch', $crawler->filter('main')->text());
        self::assertSelectorTextContains('[data-section="'.$this->sectionId($id, 'Build').'"]', 'No tasks yet.');
    }

    public function testTheFiltersNarrowTheTopLevelTasks(): void
    {
        $this->signIn();
        $id = $this->seedList();

        $crawler = $this->client->request('GET', '/projects/'.$id.'?status=finished');
        self::assertSame(['Launch'], $this->titles($crawler));
        // The tasks in no section are left out when none of them is shown.
        self::assertCount(0, $crawler->filter('[data-section="none"]'));
        self::assertSame('finished', $crawler->filter('#filter-status option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', '/projects/'.$id.'?status=all');
        self::assertSame(['Brief', 'Wireframes', 'Logo', 'Launch'], $this->titles($crawler));

        $crawler = $this->client->request('GET', '/projects/'.$id.'?responsible=me');
        self::assertSame(['Wireframes', 'Logo'], $this->titles($crawler));

        $crawler = $this->client->request('GET', '/projects/'.$id.'?due=overdue');
        self::assertSame(['Wireframes', 'Logo'], $this->titles($crawler));

        $crawler = $this->client->request('GET', '/projects/'.$id.'?due=none');
        self::assertSame(['Brief'], $this->titles($crawler));
        self::assertSelectorTextContains('[data-section="'.$this->sectionId($id, 'Design').'"]', 'No task matches the filters.');

        // Values it does not know are no filter.
        $crawler = $this->client->request('GET', '/projects/'.$id.'?status=nonsense&due=later&responsible=nobody');
        self::assertSame(['Brief', 'Wireframes', 'Logo'], $this->titles($crawler));
    }

    public function testTheLineAtTheEndOfASectionAddsATaskAndIsReadyForTheNext(): void
    {
        $this->signIn();
        $id = $this->seedList();
        $design = $this->sectionId($id, 'Design');
        $crawler = $this->client->request('GET', '/projects/'.$id.'?due=overdue');

        $form = $crawler->filter('[data-section="'.$design.'"] form[data-quick-add]')->form(['title' => '  Colours  ']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$id.'?due=overdue&add='.$design.'#add-'.$design);
        $this->client->request('GET', '/projects/'.$id.'?add='.$design);
        $crawler = $this->client->getCrawler();
        self::assertSame(['Wireframes', 'Logo', 'Colours'], $crawler->filter('[data-section="'.$design.'"] tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text())));
        $input = $crawler->filter('#add-'.$design);
        self::assertSame('', $input->attr('value'));
        self::assertNotNull($input->attr('autofocus'));
        self::assertCount(1, $crawler->filter('input[name="title"][autofocus]'));

        // An empty line adds nothing, and the tasks in no section get their own line.
        $this->client->submit($crawler->filter('[data-section="none"] form[data-quick-add]')->form(['title' => '   ']));
        self::assertResponseRedirects('/projects/'.$id.'?add=none#add-none');
        $this->client->submit($crawler->filter('[data-section="none"] form[data-quick-add]')->form(['title' => 'Kick-off']));
        $crawler = $this->client->followRedirect();
        self::assertSame(['Brief', 'Kick-off'], $crawler->filter('[data-section="none"] tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertCount(6, $this->em->getConnection()->fetchFirstColumn('SELECT id FROM task'));
    }

    public function testAQuickAddNeedsItsToken(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');

        $this->client->request('POST', '/projects/'.$id.'/tasks', ['_token' => 'wrong', 'title' => 'Brief', 'section' => 'none']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testANewProjectWithoutSectionsAddsToNoSection(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');

        $crawler = $this->client->request('GET', '/projects/'.$id);

        self::assertSame(['none'], $crawler->filter('[data-section]')->each(static fn (Crawler $section): string => (string) $section->attr('data-section')));
        self::assertCount(1, $crawler->filter('form[data-quick-add]'));
    }

    public function testTheTaskPageShowsWhatTheTaskIs(): void
    {
        $this->signIn();
        $id = $this->seedList();

        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId('Wireframes'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Wireframes');
        self::assertSelectorTextContains('main', 'Website');
        self::assertSelectorTextContains('main', 'In progress');
        self::assertSame('Three options.', trim($crawler->filter('[data-task-description]')->text()));
        self::assertCount(1, $crawler->filter('nav a[href="/projects/'.$id.'"]'));

        $this->client->request('GET', '/tasks/01900000-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAProjectOfAnotherOrganisationIsNotFound(): void
    {
        $this->signIn();
        $other = $this->browser->fixtures->tenant('Example Elsewhere');
        $this->context->switchTo($other);
        $project = new Project('Secret', null, WorkflowTemplate::Simple, '01900000-0000-7000-8000-000000000001', new \DateTimeImmutable());
        $this->em->persist($project);
        $this->em->flush();
        $this->context->reset();

        $this->client->request('GET', '/projects/'.$project->id());

        self::assertResponseStatusCodeSame(404);
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

        return $this->projectId($name);
    }

    /**
     * Website, with Brief in no section; Wireframes (overdue, high, mine, labelled)
     * with its subtask Logo in Design; Launch, done, in Build.
     */
    private function seedList(): string
    {
        $id = $this->createProject('Website');
        $this->scoped(function () use ($id): void {
            $project = $this->em->find(Project::class, $id) ?? throw new \LogicException('Project.');
            $statuses = $this->em->getRepository(ProjectStatus::class)->findBy(['project' => $project], ['position' => 'ASC']);
            [$open, $active, $done] = $statuses;
            $me = $this->em->getRepository(Membership::class)->findOneBy([]) ?? throw new \LogicException('Membership.');
            $design = new Section($project, 'Design', 0);
            $build = new Section($project, 'Build', 1);
            $now = new \DateTimeImmutable();
            $userId = $me->user()->id();
            $brief = new Task($project, 'Brief', $open, 0, $userId, $now);
            $wireframes = new Task($project, 'Wireframes', $active, 1, $userId, $now, $design);
            $wireframes->assign($me);
            $wireframes->dueOn(new \DateTimeImmutable('-3 days'));
            $wireframes->prioritise(Priority::High);
            $wireframes->describe('Three options.');
            $logo = new Task($project, 'Logo', $open, 2, $userId, $now, $design, $wireframes);
            $launch = new Task($project, 'Launch', $done, 3, $userId, $now, $build);
            $launch->dueOn(new \DateTimeImmutable('+2 days'));
            $label = new Label('Design', LabelColour::Green);
            foreach ([$design, $build, $brief, $wireframes, $logo, $launch, $label, new TaskLabel($wireframes, $label)] as $entity) {
                $this->em->persist($entity);
            }
            self::assertSame(StatusType::Done, $done->type());
            $this->em->flush();
        });

        return $id;
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('[data-section] tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text()));
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

    private function sectionId(string $project, string $name): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project_section WHERE project_id = ? AND name = ?', [$project, $name]);
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
        $this->context->switchTo($this->tenant);
        try {
            return $work();
        } finally {
            $this->context->reset();
        }
    }
}
