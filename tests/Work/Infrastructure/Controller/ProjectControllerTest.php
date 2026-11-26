<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Foresi\Work\Domain\Label;
use Foresi\Work\Domain\LabelColour;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskLabel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The projects index, a new project in its dialog, the list of a project section
 * by section with its filters, the dialog of a new task, and the task page.
 */
final class ProjectControllerTest extends WebTestCase
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

    public function testTheStaffFindTheProjectsInTheSidebarAndStartWithAnEmptyIndex(): void
    {
        $this->signIn(Role::Member);

        $crawler = $this->client->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Projects');
        self::assertContains('/projects', $crawler->filter('#sidebar [data-nav-area="projects"] [data-nav-section] li > a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSelectorTextContains('main', 'No projects yet');
        // The empty state and the header both open the dialog of a new project.
        self::assertCount(2, $crawler->filter('main a[href="/projects/new"][data-dialog]'));
    }

    public function testAGuestHasNoIndexNorNewProjectsAndFindsNoProjectNotSharedWithThem(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/projects');
        self::assertResponseRedirects('/shared');
        foreach (['/projects/new', '/projects/clients?q=exa'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('GET', '/projects/01900000-0000-7000-8000-000000000000/tasks/new');
        self::assertResponseStatusCodeSame(404);
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

    public function testTheTabsAreTheShippedViewsAndTheOldAddressesAlias(): void
    {
        // Saved views, D12: the tabs the index had, after All (D7), none with a count (D9).
        $this->signIn();
        $this->createProject('Website');
        $this->createProject('Old site');
        $this->archive('Old site');

        $crawler = $this->client->request('GET', '/projects');
        self::assertSame(['All', 'Archived'], $this->viewTabs($crawler));
        self::assertSame('/projects?view=archived', $crawler->filter('nav[aria-label="Project views"] a')->eq(1)->attr('href'));
        self::assertCount(1, $crawler->filter('[data-filter-sheet-form]'));
        self::assertCount(1, $crawler->filter('[data-column-picker-form]'));

        foreach (['' => 'Website', '?tab=active' => 'Website', '?view=archived' => 'Old site', '?tab=archived' => 'Old site', '?view=friends' => 'Website'] as $address => $shown) {
            $crawler = $this->client->request('GET', '/projects'.$address);
            self::assertSame([$shown], $this->projectNames($crawler), $address);
        }
        $crawler = $this->client->request('GET', '/projects?view=archived');
        self::assertSame('Archived', trim($crawler->filter('nav[aria-label="Project views"] [aria-current="page"]')->text()));
    }

    public function testTheBuilderNarrowsTheViewAndItsChangesAreSavedAsANewView(): void
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

    public function testTheBuilderFieldsNarrowTheList(): void
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
        self::assertSame(['Website'], $shown([['field' => 'billable', 'op' => 'is', 'value' => '1']]), 'a project for a client bills its time');
        self::assertSame(['Internal'], $shown([['field' => 'visibility', 'op' => 'is', 'values' => ['chosen']]]));
        self::assertSame(['Website'], $shown([['field' => 'visibility', 'op' => 'is', 'values' => ['organisation']]]));
        self::assertSame([], $shown([['field' => 'archived', 'op' => 'is', 'value' => '1'], ['field' => 'client', 'op' => 'is', 'value' => $client]], 'archived'));
    }

    public function testTheIndexSortsByNameEitherWay(): void
    {
        $this->signIn();
        $this->createProject('Beta');
        $this->createProject('Alpha');

        self::assertSame(['Alpha', 'Beta'], $this->projectNames($this->client->request('GET', '/projects')));
        self::assertSame(['Beta', 'Alpha'], $this->projectNames($this->client->request('GET', '/projects?sort=-name')));
        self::assertSame(['Alpha', 'Beta'], $this->projectNames($this->client->request('GET', '/projects?sort=due')), 'an unknown sort is the index\'s own');
        $crawler = $this->client->request('GET', '/projects?sort=-name');
        self::assertSame('All, unsaved changes', $this->viewTabs($crawler)[0], 'a changed sort is an unsaved change');
    }

    public function testTheColumnsSheetHidesAColumn(): void
    {
        $this->signIn();
        $this->createProject('Website');

        $crawler = $this->client->request('GET', '/projects?cols=name,open');

        self::assertSame(['Name', 'Open tasks'], $crawler->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertSame(['Website'], $this->projectNames($crawler));
    }

    public function testAViewNothingMatchesSaysSoAndOffersToClearTheFilters(): void
    {
        $this->signIn();
        $this->createProject('Website');

        $crawler = $this->client->request('GET', '/projects?view=archived');
        self::assertSelectorTextContains('main', 'No archived projects');

        $this->client->request('GET', '/projects?'.http_build_query(['conditions' => '1', 'c' => [['field' => 'billable', 'op' => 'is', 'value' => '1']]]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Nothing matches these filters');
        self::assertSame('/projects', $crawler->selectLink('Clear the filters')->attr('href'));
    }

    public function testTheListShowsTheSectionsInOrderWithSubtasksUnderTheirTask(): void
    {
        $this->signIn();
        $id = $this->seedList();

        $crawler = $this->client->request('GET', '/projects/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSame(['none', $this->sectionId($id, 'Design'), $this->sectionId($id, 'Build')], $crawler->filter('[data-section]')->each(static fn (Crawler $section): string => (string) $section->attr('data-section')));
        // The tasks in no section are the list's own: no heading, a table named after the project.
        self::assertSame(['Design (1)', 'Build (0)'], $crawler->filter('[data-section] h2')->each(static fn (Crawler $heading): string => preg_replace('/\s+/', ' ', trim($heading->text())) ?? ''));
        $loose = $crawler->filter('[data-section="none"]');
        self::assertCount(0, $loose->filter('h2'));
        self::assertStringStartsWith('Tasks of ', trim($loose->filter('table caption')->text()));
        self::assertStringNotContainsString('No section', $crawler->filter('main')->text());

        $design = $crawler->filter('[data-section]')->eq(1);
        self::assertSame(['Wireframes', 'Logo'], $design->filter('tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertCount(1, $design->filter('tbody tr[data-row-child]'));
        // A phone hides who and when in columns of their own: the line under the title says them.
        self::assertStringStartsWith('Example Company · ', trim($design->filter('tbody tr')->eq(0)->filter('[data-row-secondary]')->text()));
        self::assertSame('Nobody', trim($design->filter('tbody tr[data-row-child] [data-row-secondary]')->text()));
        self::assertSame('Logo', trim($design->filter('tbody tr[data-row-child] [data-row-link]')->text()));
        $wireframes = $design->filter('tbody tr')->eq(0)->filter('td');
        self::assertSame('/tasks/'.$this->taskId('Wireframes'), $wireframes->eq(0)->filter('a')->attr('href'));
        // The star comes right after the title (plan 37.2).
        self::assertSame('false', $wireframes->eq(1)->filter('button[aria-pressed]')->attr('aria-pressed'));
        // The status, the responsible person, the due date and the priority change in the row.
        self::assertSame('Status: In progress', $this->shown($wireframes->eq(2)));
        self::assertCount(1, $wireframes->eq(2)->filter('[data-inline-edit-trigger] .bg-badge-amber'));
        self::assertSame(['To do', 'In progress', 'Done'], $wireframes->eq(2)->filter('[role=menu] button')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame('Responsible: Example Company', $this->shown($wireframes->eq(3)));
        self::assertSame(['Nobody', 'Example Company'], $wireframes->eq(3)->filter('[role=menu] button .truncate')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertStringStartsWith('Due date: Overdue ', $this->shown($wireframes->eq(4)));
        self::assertCount(1, $wireframes->eq(4)->filter('[data-inline-edit-trigger] .bg-badge-red'));
        self::assertSame('Priority: High', $this->shown($wireframes->eq(5)));
        // A priority reads as a badge in its tone, as the status does, and its menu shows the tones.
        self::assertCount(1, $wireframes->eq(5)->filter('[data-inline-edit-trigger] .bg-badge-amber'));
        self::assertSame(['No priority', 'Low', 'Medium', 'High', 'Urgent'], $wireframes->eq(5)->filter('[role=menu] button')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertCount(4, $wireframes->eq(5)->filter('[role=menu] button span.rounded-sm'));
        self::assertCount(1, $wireframes->eq(5)->filter('[role=menu] button .bg-badge-red-text'));
        self::assertSame(['/tasks/'.$this->taskId('Wireframes').'/status', '/tasks/'.$this->taskId('Wireframes').'/responsible', '/tasks/'.$this->taskId('Wireframes').'/due', '/tasks/'.$this->taskId('Wireframes').'/priority'], $wireframes->slice(2, 4)->each(static fn (Crawler $cell): string => (string) $cell->filter('form')->attr('action')));
        self::assertSame(['/projects/'.$id], array_values(array_unique($wireframes->filter('input[name="return"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')))));
        self::assertSame(['Design'], $wireframes->eq(6)->filter('[data-badges] > *')->each(static fn (Crawler $badge): string => trim($badge->text())));
        // No priority and no date read as a dash, which a screen reader hears as what it is.
        $logo = $design->filter('tbody tr[data-row-child] td');
        self::assertSame('Priority: No priority', $this->shown($logo->eq(5)));
        self::assertSame('Due date: No due date', $this->shown($logo->eq(4)));
        self::assertSame('Responsible: Nobody', $this->shown($logo->eq(3)));

        // The finished task is left out until the filter asks for it.
        self::assertStringNotContainsString('Launch', $crawler->filter('main')->text());
        self::assertSelectorTextContains('[data-section="'.$this->sectionId($id, 'Build').'"]', 'No tasks yet.');
    }

    public function testTheViewsAreTabsUnderListAndBoardAndAllShowsTheUnfinishedTasks(): void
    {
        // Saved views, D7 and D12: All leaves out the finished tasks, as the list's default did.
        $this->signIn();
        $id = $this->seedList();

        $crawler = $this->client->request('GET', '/projects/'.$id);
        // The tabs of Work itself; where Activities adds its section between them is tested with it (Integration/Activities).
        self::assertSame(['List', 'Board', 'Budget'], array_values(array_diff($crawler->filter('nav[aria-label="Project views"] a')->each(static fn (Crawler $tab): string => trim($tab->text())), ['Activities'])));
        self::assertSame(['All', 'Mine', 'Finished'], $this->tabs($crawler));
        self::assertSame(['/projects/'.$id, '/projects/'.$id.'?view=mine', '/projects/'.$id.'?view=finished'], $crawler->filter('nav[aria-label="Task views"] a')->each(static fn (Crawler $tab): string => (string) $tab->attr('href')));
        self::assertSame('page', $crawler->filter('nav[aria-label="Task views"] a')->eq(0)->attr('aria-current'));
        self::assertSame(['Brief', 'Wireframes', 'Logo'], $this->titles($crawler));
        // No fixed select sits in the filter sheet above its conditions (spec D11), and no view is changed.
        self::assertCount(0, $crawler->filter('main select[name="responsible"], main select[name="status"], main select[name="due"]'));
        self::assertCount(0, $crawler->filter('[data-view-actions]'));
        self::assertCount(1, $crawler->filter('#view-save-as'));

        $crawler = $this->client->request('GET', '/projects/'.$id.'?view=mine');
        self::assertSame(['Wireframes', 'Logo'], $this->titles($crawler));
        self::assertSame('page', $crawler->filter('nav[aria-label="Task views"] a')->eq(1)->attr('aria-current'));
        self::assertStringContainsString('Responsible: Me', $crawler->filter('main')->text());

        $crawler = $this->client->request('GET', '/projects/'.$id.'?view=finished');
        self::assertSame(['Launch'], $this->titles($crawler));
        // The tasks in no section are left out when none of them is shown.
        self::assertCount(0, $crawler->filter('[data-section="none"]'));
    }

    public function testTheOldFiltersAreConditionsOfTheBuilder(): void
    {
        $this->signIn();
        $id = $this->seedList();

        foreach ([
            'status=finished' => ['Launch'],
            'status=all' => ['Brief', 'Wireframes', 'Logo', 'Launch'],
            'responsible=me' => ['Wireframes', 'Logo'],
            'due=overdue' => ['Wireframes', 'Logo'],
            'due=none' => ['Brief'],
            'status=all&due=next_7_days' => ['Launch'],
        ] as $old => $titles) {
            $crawler = $this->client->request('GET', '/projects/'.$id.'?'.$old);
            self::assertSame($titles, $this->titles($crawler), $old);
            self::assertSame('All, unsaved changes', $this->tabs($crawler)[0], $old);
        }
        self::assertSelectorTextContains('[data-section="'.$this->sectionId($id, 'Design').'"]', 'No task matches the filters.');
        $crawler = $this->client->request('GET', '/projects/'.$id.'?responsible='.$this->membershipId());
        self::assertSame(['Wireframes', 'Logo'], $this->titles($crawler));
        self::assertStringContainsString('Responsible: Example Company', $crawler->filter('main')->text());

        // Values it does not know are no filter; ?status=open was the default.
        foreach (['status=nonsense&due=later&responsible=nobody', 'status=open'] as $old) {
            $crawler = $this->client->request('GET', '/projects/'.$id.'?'.$old);
            self::assertSame(['Brief', 'Wireframes', 'Logo'], $this->titles($crawler), $old);
            self::assertSame(['All', 'Mine', 'Finished'], $this->tabs($crawler), $old);
        }
    }

    public function testTheBuilderNarrowsTheListAndItsChangesAreSavedAsANewView(): void
    {
        $this->signIn();
        $id = $this->seedList();

        $this->client->request('GET', '/projects/'.$id.'?'.http_build_query(['view' => 'all', 'due' => 'overdue', 'conditions' => '1', 'c' => [
            ['field' => 'responsible', 'op' => 'is_empty'],
            ['field' => 'status', 'op' => 'is_any_of', 'values' => ['open', 'finished']],
        ]]));
        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/projects/'.$id.'?f=', $location);
        self::assertStringNotContainsString('due=', $location);
        self::assertStringNotContainsString('view=', $location, 'All is the bare list.');
        $crawler = $this->client->followRedirect();

        self::assertSame(['Brief', 'Launch'], $this->titles($crawler));
        self::assertSame('All, unsaved changes', $this->tabs($crawler)[0]);
        self::assertStringContainsString('Responsible: empty', $crawler->filter('main')->text());
        self::assertCount(1, $crawler->filter('[data-view-actions] [data-view-reset][href="/projects/'.$id.'?widths="]'));
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'Nobody\'s']));
        $crawler = $this->client->followRedirect();
        self::assertSame(['Brief', 'Launch'], $this->titles($crawler));
        self::assertContains('Nobody\'s', $this->tabs($crawler));

        // The views are the same in every project.
        $other = $this->createProject('Shop');
        $crawler = $this->client->request('GET', '/projects/'.$other);
        self::assertContains('Nobody\'s', $this->tabs($crawler));
    }

    public function testThePeopleOfAResponsibleConditionAreFoundByName(): void
    {
        $this->signIn();

        $this->client->request('GET', '/projects/people?q=exam');
        self::assertResponseIsSuccessful();
        self::assertSame([['id' => 'me', 'label' => 'Me', 'hint' => ''], ['id' => $this->membershipId(), 'label' => 'Example Company', 'hint' => '']], $this->results());
        $this->client->request('GET', '/projects/people?q=ME');
        self::assertSame(['me'], array_column($this->results(), 'id'));
        $this->client->request('GET', '/projects/people?q=nobody');
        self::assertSame([], $this->results());
    }

    public function testTheColumnsSheetHidesAColumnAndKeepsTheView(): void
    {
        $this->signIn();
        $id = $this->seedList();

        $crawler = $this->client->request('GET', '/projects/'.$id.'?view=mine&cols=title,due');

        self::assertSame(['Title', 'Due date', 'Actions'], $crawler->filter('[data-section]')->eq(0)->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertSame(['Wireframes', 'Logo'], $this->titles($crawler));
        self::assertSame('Mine, unsaved changes', $this->tabs($crawler)[1]);
        self::assertStringStartsWith('Due date: Overdue ', $this->shown($crawler->filter('[data-section] tbody tr')->eq(0)->filter('td')->eq(1)));
    }

    public function testTheHeaderOfTheListAndTheBoardOpensTheDialogOfANewTask(): void
    {
        $this->signIn();
        $id = $this->seedList();

        foreach (['/projects/'.$id.'?due=overdue', '/projects/'.$id.'/board?due=overdue'] as $url) {
            $crawler = $this->client->request('GET', $url);
            $open = $crawler->filter('main a[data-task-new-open]');
            self::assertCount(1, $open, $url);
            $href = (string) $open->attr('href');
            self::assertStringStartsWith('/projects/'.$id.'/tasks/new?', $href);
            parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
            self::assertSame(['return' => $url], $query);
            self::assertNotNull($open->attr('data-dialog'));
            self::assertSame('follow', $open->attr('data-dialog-then'));
            self::assertSame('New task', trim($open->text()));
            // No line to add a task in the content any more.
            self::assertCount(0, $crawler->filter('main input[name="title"]'), $url);
        }
    }

    public function testTheDialogAddsATaskToTheChosenSectionAndGoesBackToTheListWithItsFilters(): void
    {
        $this->signIn();
        $id = $this->seedList();
        $design = $this->sectionId($id, 'Design');
        $dialog = ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1'];

        $crawler = $this->client->request('GET', '/projects/'.$id.'/tasks/new?section='.$design.'&return='.rawurlencode('/projects/'.$id.'?due=overdue'), server: $dialog);

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('dialog#dialog-page form#task-new-form');
        self::assertCount(1, $form);
        self::assertSame(['No section', 'Design', 'Build'], $form->filter('#task-new-section option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame($design, $form->filter('#task-new-section option[selected]')->attr('value'));
        self::assertSame('/projects/'.$id.'?due=overdue', $form->filter('input[name="return"]')->attr('value'));

        $this->client->submit($form->form(['title' => '  Colours  ']), serverParameters: $dialog);

        self::assertResponseIsSuccessful();
        self::assertSame(['location' => '/projects/'.$id.'?due=overdue'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $crawler = $this->client->request('GET', '/projects/'.$id);
        self::assertSelectorTextContains('main', 'The task “Colours” is added.');
        self::assertSame(['Wireframes', 'Logo', 'Colours'], $crawler->filter('[data-section="'.$design.'"] tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('To do', $this->statusOf('Colours'));
    }

    public function testTheDialogGoesBackToTheViewWithItsFilterAndColumns(): void
    {
        $this->signIn();
        $id = $this->seedList();
        $dialog = ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1'];
        $from = '/projects/'.$id.'?view=mine&f=abc&cols=title&widths=&page=2&status=open&due=today';

        $crawler = $this->client->request('GET', '/projects/'.$id.'/tasks/new?return='.rawurlencode($from), server: $dialog);

        self::assertSame('/projects/'.$id.'?view=mine&f=abc&cols=title&due=today', $crawler->filter('form#task-new-form input[name="return"]')->attr('value'));
        // The board keeps only its own filters.
        $crawler = $this->client->request('GET', '/projects/'.$id.'/tasks/new?return='.rawurlencode('/projects/'.$id.'/board?view=mine&due=today'), server: $dialog);
        self::assertSame('/projects/'.$id.'/board?due=today', $crawler->filter('form#task-new-form input[name="return"]')->attr('value'));
    }

    public function testWithoutJavaScriptTheDialogIsAPageThatAddsToNoSectionByDefault(): void
    {
        $this->signIn();
        $id = $this->seedList();

        $crawler = $this->client->request('GET', '/projects/'.$id.'/tasks/new?section=01900000-0000-7000-8000-000000000000');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'New task');
        // A section the project does not have is no choice; the page goes back to the list.
        self::assertSame('', $crawler->filter('#task-new-section option[selected]')->attr('value'));
        self::assertSame('/projects/'.$id, $crawler->filter('input[name="return"]')->attr('value'));
        $this->client->submit($crawler->filter('#task-new-form')->form(['title' => 'Kick-off']));

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$id);
        $crawler = $this->client->followRedirect();
        self::assertSame(['Brief', 'Kick-off'], $crawler->filter('[data-section="none"] tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    public function testANewTaskNeedsATitleAndASectionOfTheProjectAndKeepsWhatWasTyped(): void
    {
        $this->signIn();
        $id = $this->seedList();
        $build = $this->sectionId($id, 'Build');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/tasks/new');

        $crawler = $this->client->submit($crawler->filter('#task-new-form')->form(['title' => '   ', 'section' => $build]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter the title of the task.', trim($crawler->filter('#task-new-title-error')->text()));
        self::assertSame('true', $crawler->filter('#task-new-title')->attr('aria-invalid'));
        self::assertSelectorTextContains('[data-error-summary]', 'Title: Enter the title of the task.');
        self::assertSame($build, $crawler->filter('#task-new-section option[selected]')->attr('value'));

        $long = str_repeat('a', 501);
        $crawler = $this->client->submit($crawler->filter('#task-new-form')->form(['title' => $long]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter a name that is not too long.', trim($crawler->filter('#task-new-title-error')->text()));
        self::assertSame($long, $crawler->filter('#task-new-title')->attr('value'));

        $this->client->request('POST', '/projects/'.$id.'/tasks/new', ['_token' => $crawler->filter('#task-new-form input[name="_token"]')->attr('value'), 'title' => 'Colours', 'section' => '01900000-0000-7000-8000-000000000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Choose a section of this project.', trim($this->client->getCrawler()->filter('#task-new-section-error')->text()));
        self::assertSame('Colours', $this->client->getCrawler()->filter('#task-new-title')->attr('value'));
        self::assertCount(4, $this->em->getConnection()->fetchFirstColumn('SELECT id FROM task'));
    }

    public function testFromTheBoardTheTaskStartsInTheFirstOpenStatusAndTheBoardComesBack(): void
    {
        $this->signIn();
        $id = $this->seedList();
        $crawler = $this->client->request('GET', '/projects/'.$id.'/tasks/new?return='.rawurlencode('/projects/'.$id.'/board?responsible=me&status=finished&due=nonsense'));

        $this->client->submit($crawler->filter('#task-new-form')->form(['title' => 'Sitemap']));

        self::assertResponseRedirects('/projects/'.$id.'/board?responsible=me');
        self::assertSame('To do', $this->statusOf('Sitemap'));
        $crawler = $this->client->request('GET', '/projects/'.$id.'/board');
        self::assertContains('Sitemap', $this->board($crawler)['To do']);
        self::assertSelectorTextContains('main', 'The task “Sitemap” is added.');
    }

    public function testAReturnPathElsewhereGoesBackToTheList(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');

        foreach (['https://evil.example.com/projects/'.$id.'/board', '//evil.example.com', '/contacts?status=all', 'nonsense'] as $return) {
            $crawler = $this->client->request('GET', '/projects/'.$id.'/tasks/new?return='.rawurlencode($return));
            self::assertSame('/projects/'.$id, $crawler->filter('input[name="return"]')->attr('value'), $return);
        }
        $crawler = $this->client->request('GET', '/projects/'.$id.'/tasks/new?return='.rawurlencode('https://evil.example.com/'));
        $this->client->submit($crawler->filter('#task-new-form')->form(['title' => 'Brief']));
        self::assertResponseRedirects('/projects/'.$id);
    }

    public function testAnArchivedProjectOffersNoNewTask(): void
    {
        $this->signIn();
        $id = $this->seedList();
        $this->scoped(function () use ($id): void {
            ($this->em->find(Project::class, $id) ?? throw new \LogicException('Project.'))->archive(new \DateTimeImmutable());
            $this->em->flush();
        });

        foreach (['/projects/'.$id, '/projects/'.$id.'/board'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertCount(0, $crawler->filter('a[data-task-new-open]'), $url);
            // Nor changes in a row or on a card: the board does not move either.
            self::assertCount(0, $crawler->filter('form[data-inline-edit]'), $url);
        }
        $this->client->request('GET', '/projects/'.$id.'/tasks/new?return='.rawurlencode('/projects/'.$id.'/board'));
        self::assertResponseRedirects('/projects/'.$id.'/board');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The project is archived. Restore it first.');
        $this->client->request('POST', '/projects/'.$id.'/tasks/new', ['_token' => 'any', 'title' => 'Late']);
        self::assertResponseRedirects('/projects/'.$id);
        self::assertCount(4, $this->em->getConnection()->fetchFirstColumn('SELECT id FROM task'));
    }

    public function testANewTaskNeedsItsTokenAndAnExistingProject(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');

        $this->client->request('POST', '/projects/'.$id.'/tasks/new', ['_token' => 'wrong', 'title' => 'Brief']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/projects/01900000-0000-7000-8000-000000000000/tasks/new');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM task'));
    }

    public function testAProjectWithoutTasksShowsAnEmptyStateThatAddsTheFirst(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');

        $crawler = $this->client->request('GET', '/projects/'.$id);

        self::assertCount(0, $crawler->filter('[data-section]'));
        self::assertSelectorTextContains('[data-project-empty]', 'No tasks yet');
        self::assertCount(1, $crawler->filter('[data-project-empty] a[data-task-new-open]'));
        self::assertCount(2, $crawler->filter('main a[data-task-new-open]'));

        $crawler = $this->client->request('GET', '/projects/'.$id.'?status=finished');
        self::assertSelectorTextContains('[data-project-empty]', 'No task matches the filters.');
        self::assertCount(0, $crawler->filter('[data-project-empty] a'));
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

    public function testTheBoardHasAColumnPerStatusWithTheTopLevelTasksAsCards(): void
    {
        $this->signIn();
        $id = $this->seedList();

        $crawler = $this->client->request('GET', '/projects/'.$id);
        self::assertSame('/projects/'.$id.'/board', $crawler->filter('nav a:contains("Board")')->attr('href'));

        $crawler = $this->client->request('GET', '/projects/'.$id.'/board');

        self::assertResponseIsSuccessful();
        self::assertSame('page', $crawler->filter('nav a:contains("Board")')->attr('aria-current'));
        // Every status has its column, so the status filter of the list is not offered.
        self::assertCount(0, $crawler->filter('[data-filter-sheet-form] select[name=status]'));
        self::assertCount(1, $crawler->filter('[data-filter-sheet-form] select[name=responsible]'));
        self::assertSame(['To do' => ['Brief'], 'In progress' => ['Wireframes'], 'Done' => ['Launch']], $this->board($crawler));
        self::assertSame(['1', '1', '1'], $crawler->filter('[data-board-count]')->each(static fn (Crawler $count): string => trim($count->text())));
        self::assertStringContainsString('Type: in progress', $crawler->filter('[data-board-column] h2')->eq(1)->text());

        $card = $crawler->filter('[data-board-card][data-task-id="'.$this->taskId('Wireframes').'"]');
        self::assertSame('/tasks/'.$this->taskId('Wireframes'), $card->filter('[data-card-title]')->attr('href'));
        self::assertStringContainsString('Example Company', $card->filter('[data-card-responsible]')->text());
        self::assertStringContainsString('Overdue', $card->filter('[data-card-due]')->text());
        self::assertStringContainsString('High', $card->filter('[data-card-priority]')->text());
        self::assertCount(1, $card->filter('[data-card-priority] [data-inline-edit-trigger] .bg-badge-amber'));
        self::assertSame('Design', trim($card->filter('[data-badges]')->text()));
        self::assertStringContainsString('0/1', $card->filter('[data-card-subtasks]')->text());
        self::assertCount(1, $card->filter('[data-board-handle]'));
        // The responsible person, the due date and the priority change on the card, and come back to the board.
        self::assertSame(['/tasks/'.$this->taskId('Wireframes').'/responsible', '/tasks/'.$this->taskId('Wireframes').'/due', '/tasks/'.$this->taskId('Wireframes').'/priority'], $card->filter('form[data-inline-edit]')->each(static fn (Crawler $form): string => (string) $form->attr('action')));
        self::assertSame(['/projects/'.$id.'/board'], array_values(array_unique($card->filter('input[name="return"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')))));
        // A card without a priority, a date or subtasks shows the icons of the first two only.
        $brief = $crawler->filter('[data-board-card][data-task-id="'.$this->taskId('Brief').'"]');
        self::assertSame('Priority: No priority', $this->shown($brief->filter('[data-card-priority]')));
        self::assertSame('Due date: No due date', $this->shown($brief->filter('[data-card-due]')));
        self::assertCount(1, $brief->filter('[data-card-priority] [data-inline-edit-trigger] svg'));
        self::assertCount(0, $brief->filter('[data-card-subtasks]'));
        // The menu moves to the other statuses, and not up or down in a column of one.
        self::assertSame(['hidden', null, null], $brief->filter('[data-move-status]')->each(static fn (Crawler $form): ?string => null === $form->attr('hidden') ? null : 'hidden'));
        self::assertSame(['hidden', 'hidden'], $brief->filter('[data-move]')->each(static fn (Crawler $form): string => null === $form->attr('hidden') ? 'shown' : 'hidden'));

        $this->client->request('GET', '/projects/01900000-0000-7000-8000-000000000000/board');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheBoardFiltersByResponsibleAndDueButNotByStatus(): void
    {
        $this->signIn();
        $id = $this->seedList();

        $crawler = $this->client->request('GET', '/projects/'.$id.'/board?responsible=me');
        self::assertSame(['To do' => [], 'In progress' => ['Wireframes'], 'Done' => []], $this->board($crawler));
        self::assertSame('me', $crawler->filter('[data-filter-sheet-form] select[name=responsible] option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', '/projects/'.$id.'/board?due=none&status=finished');
        self::assertSame(['To do' => ['Brief'], 'In progress' => [], 'Done' => []], $this->board($crawler));
    }

    public function testTheMenuMovesACardToAnotherStatusAndUpAndDownItsColumn(): void
    {
        $this->signIn();
        $id = $this->seedList();
        $crawler = $this->client->request('GET', '/projects/'.$id.'/board?due=next_7_days');
        $launch = $this->taskId('Launch');
        $toDo = $crawler->filter('[data-board-column]')->eq(0)->attr('data-board-column');

        $this->client->submit($crawler->filter('[data-task-id="'.$launch.'"] form[data-move-status="'.$toDo.'"]')->form());

        self::assertResponseRedirects('/projects/'.$id.'/board?due=next_7_days', 303);
        $crawler = $this->client->request('GET', '/projects/'.$id.'/board');
        self::assertSame(['To do' => ['Brief', 'Launch'], 'In progress' => ['Wireframes'], 'Done' => []], $this->board($crawler));
        self::assertSame('To do', $this->em->getConnection()->fetchOne('SELECT s.name FROM task t JOIN project_status s ON s.id = t.status_id WHERE t.id = ?', [$launch]));

        $this->client->submit($crawler->filter('[data-task-id="'.$launch.'"] form[data-move="up"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame(['Launch', 'Brief'], $this->board($crawler)['To do']);
        // One order for the project: the list reads it too.
        self::assertSame(['Launch', 'Brief', 'Wireframes'], $this->em->getConnection()->fetchFirstColumn('SELECT title FROM task WHERE parent_id IS NULL ORDER BY position'));

        // At the top already, up changes nothing; down goes back.
        $this->client->submit($crawler->filter('[data-task-id="'.$launch.'"] form[data-move="up"]')->form());
        self::assertSame(['Launch', 'Brief'], $this->board($this->client->followRedirect())['To do']);
        $this->client->submit($crawler->filter('[data-task-id="'.$launch.'"] form[data-move="down"]')->form());
        self::assertSame(['Brief', 'Launch'], $this->board($this->client->followRedirect())['To do']);
    }

    public function testADropIsSavedWithItsPlaceAndAnswersNoContent(): void
    {
        $this->signIn();
        $id = $this->seedList();
        $crawler = $this->client->request('GET', '/projects/'.$id.'/board');
        $token = (string) $crawler->filter('[data-controller="board"]')->attr('data-board-token-value');
        $done = $crawler->filter('[data-board-column]')->eq(2)->attr('data-board-column');
        $json = ['HTTP_ACCEPT' => 'application/json'];

        $this->client->request('POST', '/tasks/'.$this->taskId('Brief').'/move', ['_token' => $token, 'statusId' => $done, 'beforeTaskId' => $this->taskId('Launch')], server: $json);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('POST', '/tasks/'.$this->taskId('Wireframes').'/move', ['_token' => $token, 'statusId' => $done, 'beforeTaskId' => ''], server: $json);
        self::assertResponseStatusCodeSame(204);

        $crawler = $this->client->request('GET', '/projects/'.$id.'/board');
        self::assertSame(['To do' => [], 'In progress' => [], 'Done' => ['Brief', 'Launch', 'Wireframes']], $this->board($crawler));
        self::assertSame(['0', '0', '3'], $crawler->filter('[data-board-count]')->each(static fn (Crawler $count): string => trim($count->text())));

        // A place before a task of another column, or a status it does not have, is no place.
        $this->client->request('POST', '/tasks/'.$this->taskId('Brief').'/move', ['_token' => $token, 'statusId' => $crawler->filter('[data-board-column]')->eq(0)->attr('data-board-column'), 'beforeTaskId' => $this->taskId('Launch')], server: $json);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/tasks/'.$this->taskId('Brief').'/move', ['_token' => $token, 'statusId' => '01900000-0000-7000-8000-000000000000'], server: $json);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAMoveNeedsItsToken(): void
    {
        $this->signIn();
        $id = $this->seedList();
        $brief = $this->taskId('Brief');

        $this->client->request('POST', '/tasks/'.$brief.'/move', ['_token' => 'wrong', 'direction' => 'down']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['To do' => ['Brief'], 'In progress' => ['Wireframes'], 'Done' => ['Launch']], $this->board($this->client->request('GET', '/projects/'.$id.'/board')));
    }

    public function testAGuestFindsNoBoardNotSharedWithThem(): void
    {
        $this->signIn(Role::Guest);
        $id = '01900000-0000-7000-8000-000000000000';

        $this->client->request('GET', '/projects/'.$id.'/board');
        self::assertResponseStatusCodeSame(404);
        // The token comes first: a move without it is refused before the task is looked for.
        $this->client->request('POST', '/tasks/'.$id.'/move', ['direction' => 'down']);
        self::assertResponseStatusCodeSame(403);
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
     * @return array<string, list<string>> the card titles by column name
     */
    private function board(Crawler $crawler): array
    {
        $columns = [];
        $crawler->filter('[data-board-column]')->each(static function (Crawler $column) use (&$columns): void {
            $columns[trim($column->filter('h2 > span.truncate')->text())] = $column->filter('[data-card-title]')->each(static fn (Crawler $title): string => trim($title->text()));
        });

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Task views"] a')->each(static fn (Crawler $tab): string => trim((string) preg_replace('/\s+/', ' ', $tab->text())));
    }

    /**
     * The results of the last JSON answer.
     *
     * @phpstan-impure
     *
     * @return list<array<mixed>>
     */
    private function results(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['results'] ?? null);

        return array_values(array_filter($json['results'], \is_array(...)));
    }

    private function membershipId(): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM membership LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('[data-section] tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text()));
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

    private function statusOf(string $title): string
    {
        $name = $this->em->getConnection()->fetchOne('SELECT s.name FROM task t JOIN project_status s ON s.id = t.status_id WHERE t.title = ?', [$title]);
        self::assertIsString($name);

        return $name;
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

    /**
     * What a cell reads as: the text of its picker a screen reader hears (no avatar initials, no dash), else its text.
     */
    private function shown(Crawler $cell): string
    {
        $trigger = $cell->filter('[data-inline-edit-trigger], [data-person-picker-trigger]');
        if (0 === $trigger->count()) {
            return trim($cell->text());
        }
        $node = $trigger->getNode(0)?->cloneNode(true) ?? throw new \LogicException('A trigger.');
        $hidden = new \DOMXPath($node->ownerDocument ?? throw new \LogicException('A document.'))->query('.//*[@aria-hidden="true"]', $node);
        foreach (false === $hidden ? [] : iterator_to_array($hidden) as $element) {
            if ($element instanceof \DOMElement) {
                $element->parentNode?->removeChild($element);
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $node->textContent));
    }
}
