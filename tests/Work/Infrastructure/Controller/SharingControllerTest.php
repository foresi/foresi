<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Controller;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\WorkflowTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The sharing dialog of a project, a section and a task: the switch that
 * closes the item, the people it is shared with and the picker that adds one,
 * each change coming back to the dialog with its message.
 */
final class SharingControllerTest extends WebTestCase
{
    /** What the dialog controller sends, the header of DialogRequest::HEADER. */
    private const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1'];

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

    public function testTheProjectHeaderOpensTheDialogWithWhoSeesItAndWhomItCanBeSharedWith(): void
    {
        $this->signIn();
        $project = $this->seed();

        foreach (['/projects/'.$project, '/projects/'.$project.'/board'] as $page) {
            $crawler = $this->client->request('GET', $page.'?due=week');
            $open = $crawler->filter('main header a[data-project-share]');
            self::assertSame('/projects/'.$project.'/sharing?return='.$page.'?due%3Dweek', $open->attr('href'), $page);
            self::assertNotNull($open->attr('data-dialog'));
            self::assertSame('Share', trim($open->text()));
            self::assertCount(0, $crawler->filter('[data-header-badge]'));
        }

        $crawler = $this->client->request('GET', '/projects/'.$project.'/sharing?return='.rawurlencode('/projects/'.$project.'/board?due=week'), server: self::DIALOG);
        self::assertResponseIsSuccessful();
        $dialog = $crawler->filter('dialog#dialog-page');
        self::assertCount(1, $dialog);
        self::assertSame('0', $dialog->attr('data-dialog-reload'));
        self::assertSame('Share the project', trim($dialog->filter('h2')->first()->text()));
        self::assertSame('Website', trim($dialog->filter('#dialog-page-description')->text()));
        self::assertSame('organisation', $crawler->filter('input[name="visibility"][checked]')->attr('value'));
        self::assertSame(['The whole organisation', 'Only chosen people'], $crawler->filter('[data-sharing-private] label .font-medium')->each(static fn (Crawler $label): string => trim($label->text())));
        self::assertSelectorTextContains('[data-sharing-people]', 'Not shared with anyone yet.');
        // The owners see everything: no one to share with.
        self::assertSame(['Choose a person', 'Guest Example', 'Jana Example'], $crawler->filter('#sharing-person option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame('/projects/'.$project.'/board?due=week', $crawler->filter('[data-sharing-add] input[name="return"]')->attr('value'));
        self::assertCount(0, $crawler->filter('[data-sharing-history]'));
        self::assertCount(0, $crawler->filter('[data-sharing-messages]'));
        self::assertCount(1, $crawler->filter('dialog footer [data-sharing-done][data-action="dialog#dismiss"]'));
    }

    public function testWithoutJavaScriptTheDialogIsAPageThatGoesBackWhereItWasOpened(): void
    {
        $this->signIn();
        $project = $this->seed();

        $crawler = $this->client->request('GET', '/projects/'.$project.'/sharing?return='.rawurlencode('/projects/'.$project.'/board?due=week&evil=1'));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('dialog#dialog-page'));
        self::assertSelectorTextContains('main h1', 'Share');
        self::assertSame('/projects/'.$project.'/board?due=week', $crawler->filter('main a[data-sharing-done]')->attr('href'));
        self::assertCount(1, $crawler->filter('main noscript'));

        // Only a page of this project: anything else goes back to its list.
        foreach (['https://example.com/projects/'.$project, '/projects/'.$project.'/settings', '//example.com/x'] as $return) {
            $crawler = $this->client->request('GET', '/projects/'.$project.'/sharing?return='.rawurlencode($return));
            self::assertSame('/projects/'.$project, $crawler->filter('main a[data-sharing-done]')->attr('href'), $return);
        }
    }

    public function testAProjectIsSharedWithAPersonAndTakenBackAndTheDialogTellsEachStep(): void
    {
        $this->signIn();
        $project = $this->seed();
        $jana = $this->membershipId('jana@example.com');

        $crawler = $this->client->request('GET', '/projects/'.$project.'/sharing?return='.rawurlencode('/projects/'.$project.'/board'), server: self::DIALOG);
        // The open dialog shows itself again at once, with the message.
        $crawler = $this->client->submit($crawler->filter('[data-sharing-add]')->form(['person' => $jana]), serverParameters: self::DIALOG);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM work_share WHERE project_id = ? AND membership_id = ?', [$project, $jana]));
        self::assertSame('/projects/'.$project.'/board', $crawler->filter('[data-sharing-add] input[name="return"]')->attr('value'));
        self::assertSame('1', $crawler->filter('dialog#dialog-page')->attr('data-dialog-reload'));
        self::assertSelectorTextContains('dialog [data-sharing-messages]', 'Shared with Jana Example.');
        $person = $crawler->filter('[data-sharing-person="'.$jana.'"]');
        self::assertStringContainsString('Jana Example', $person->text());
        self::assertStringContainsString('Team member', $person->text());
        self::assertSame('JE', trim($person->filter('[aria-hidden="true"]')->text()));
        self::assertSame('Remove Jana Example', $person->filter('button')->attr('aria-label'));
        self::assertSame(['Choose a person', 'Guest Example'], $crawler->filter('#sharing-person option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertStringContainsString('Example Company: Shared with: Jana Example', $crawler->filter('[data-sharing-history]')->text());

        // Sharing again changes nothing; a person who cannot be chosen is refused.
        $this->client->request('POST', '/projects/'.$project.'/sharing/people', ['_token' => $this->token($crawler), 'person' => $jana]);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Choose an active person of the organisation to share with.');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM work_share WHERE project_id = ?', [$project]));
        $this->client->request('POST', '/projects/'.$project.'/sharing/people', ['_token' => $this->token($crawler), 'person' => $this->membershipId('testing@example.com')]);
        self::assertResponseRedirects('/projects/'.$project.'/sharing?return='.('/projects/'.$project));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM work_share WHERE project_id = ? AND membership_id = ?', [$project, $this->membershipId('testing@example.com')]));

        $crawler = $this->client->request('GET', '/projects/'.$project.'/sharing', server: self::DIALOG);
        self::assertSame('0', $crawler->filter('dialog#dialog-page')->attr('data-dialog-reload'));
        $crawler = $this->client->submit($crawler->filter('[data-sharing-person="'.$jana.'"] form')->form(), serverParameters: self::DIALOG);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM work_share WHERE project_id = ?', [$project]));
        self::assertSame('1', $crawler->filter('dialog#dialog-page')->attr('data-dialog-reload'));
        self::assertSelectorTextContains('dialog', 'Jana Example no longer has access.');
        self::assertSelectorTextContains('[data-sharing-people]', 'Not shared with anyone yet.');
        self::assertStringContainsString('Example Company: Stopped sharing with: Jana Example', $crawler->filter('[data-sharing-history]')->text());

        // Taking back what is not shared says nothing.
        $this->client->request('POST', '/projects/'.$project.'/sharing/people/'.$jana.'/remove', ['_token' => $this->token($crawler)]);
        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString('no longer has access', $crawler->filter('main')->text());
        self::assertStringNotContainsString('Choose an active person', $crawler->filter('main')->text());
    }

    public function testOnlyChosenPeopleSeeAProjectOnceItIsSoAndTheHeadersSayIt(): void
    {
        $this->signIn();
        $project = $this->seed();

        $crawler = $this->client->request('GET', '/projects/'.$project.'/sharing');
        $this->client->submit($crawler->filter('[data-sharing-private]')->form(['visibility' => 'chosen']));
        self::assertResponseRedirects('/projects/'.$project.'/sharing?return='.('/projects/'.$project));
        self::assertSame(ProjectVisibility::Chosen->value, $this->em->getConnection()->fetchOne('SELECT visibility FROM project WHERE id = ?', [$project]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Only chosen people see the project now.');
        self::assertSame('chosen', $crawler->filter('input[name="visibility"][checked]')->attr('value'));
        self::assertStringContainsString('Example Company: Only chosen people see the project', $crawler->filter('[data-sharing-history]')->text());
        // An owner sees every project: no share of their own is needed.
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM work_share WHERE project_id = ?', [$project]));

        foreach (['', '/board', '/settings'] as $page) {
            $crawler = $this->client->request('GET', '/projects/'.$project.$page);
            self::assertSame(['Only chosen people'], $crawler->filter('[data-header-badge]')->each(static fn (Crawler $badge): string => trim($badge->text())), $page);
        }

        $token = $this->token($this->client->request('GET', '/projects/'.$project.'/sharing'));
        $this->client->request('POST', '/projects/'.$project.'/sharing/private', ['_token' => $token, 'visibility' => 'everyone']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Choose who sees the project.');
        self::assertSame(ProjectVisibility::Chosen->value, $this->em->getConnection()->fetchOne('SELECT visibility FROM project WHERE id = ?', [$project]));

        $this->client->request('POST', '/projects/'.$project.'/sharing/private', ['_token' => $this->token($crawler), 'visibility' => 'organisation']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The whole organisation sees the project now.');
        self::assertSame(ProjectVisibility::Organisation->value, $this->em->getConnection()->fetchOne('SELECT visibility FROM project WHERE id = ?', [$project]));
        self::assertStringContainsString('Example Company: The whole organisation sees the project', $crawler->filter('[data-sharing-history] li')->last()->text());
    }

    public function testATaskIsMadePrivateAndSharedFromItsHeaderAndItsBadgesAndHistoryTellIt(): void
    {
        $this->signIn();
        $project = $this->seed();
        $task = $this->taskId('Wireframes');
        $jana = $this->membershipId('jana@example.com');

        $crawler = $this->client->request('GET', '/tasks/'.$task);
        $open = $crawler->filter('main header a[data-task-share]');
        self::assertSame('/tasks/'.$task.'/sharing', $open->attr('href'));
        self::assertNotNull($open->attr('data-dialog'));
        self::assertCount(0, $crawler->filter('[data-header-badge]'));

        $crawler = $this->client->request('GET', '/tasks/'.$task.'/sharing', server: self::DIALOG);
        self::assertSame('Share the task', trim($crawler->filter('dialog h2')->first()->text()));
        self::assertNull($crawler->filter('#sharing-private')->attr('checked'));
        self::assertSelectorTextContains('[data-sharing-private]', 'Private task');
        $crawler = $this->client->submit($crawler->filter('[data-sharing-private]')->form(['private' => '1']), serverParameters: self::DIALOG);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue((bool) $this->em->getConnection()->fetchOne('SELECT private FROM task WHERE id = ?', [$task]));
        self::assertSelectorTextContains('dialog', 'The task is private now.');
        self::assertNotNull($crawler->filter('#sharing-private')->attr('checked'));
        // A task tells its sharing in its own history.
        self::assertCount(0, $crawler->filter('[data-sharing-history]'));

        $this->client->submit($crawler->filter('[data-sharing-add]')->form(['person' => $jana]));
        self::assertResponseRedirects('/tasks/'.$task.'/sharing?return='.('/tasks/'.$task));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM work_share WHERE task_id = ? AND membership_id = ?', [$task, $jana]));

        $crawler = $this->client->request('GET', '/tasks/'.$task);
        self::assertSame(['Private'], $crawler->filter('[data-header-badge]')->each(static fn (Crawler $badge): string => trim($badge->text())));
        $history = implode("\n", $crawler->filter('[data-task-history] li')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertStringContainsString('Example Company: Made the task private', $history);
        self::assertStringContainsString('Example Company: Shared with: Jana Example', $history);

        $crawler = $this->client->request('GET', '/projects/'.$project);
        $row = $crawler->filter('[data-section] tbody tr')->reduce(static fn (Crawler $tr): bool => str_contains($tr->text(), 'Wireframes'));
        self::assertSame('Private', trim($row->filter('[data-flag-cell] span.rounded-sm')->text()));
        self::assertSame('/tasks/'.$task, $row->filter('[data-flag-cell] a')->attr('href'));
        self::assertCount(1, $crawler->filter('[data-flag-cell]'));
        $crawler = $this->client->request('GET', '/projects/'.$project.'/board');
        $card = $crawler->filter('[data-board-card][data-task-id="'.$task.'"]');
        self::assertSame('Private', trim($card->filter('[data-private-badge]')->text()));
        self::assertCount(1, $crawler->filter('[data-board-card] [data-private-badge]'));

        $this->client->request('POST', '/tasks/'.$task.'/sharing/private', ['_token' => $this->token($this->client->request('GET', '/tasks/'.$task.'/sharing'))]);
        self::assertFalse((bool) $this->em->getConnection()->fetchOne('SELECT private FROM task WHERE id = ?', [$task]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The task is no longer private.');
        $crawler = $this->client->request('GET', '/tasks/'.$task);
        self::assertStringContainsString('Example Company: Made the task open again', $crawler->filter('[data-task-history] li')->last()->text());

        $this->client->submit($this->client->request('GET', '/tasks/'.$task.'/sharing')->filter('[data-sharing-person="'.$jana.'"] form')->form());
        $crawler = $this->client->request('GET', '/tasks/'.$task);
        self::assertStringContainsString('Example Company: Stopped sharing with: Jana Example', $crawler->filter('[data-task-history] li')->last()->text());
    }

    public function testASectionIsSharedFromTheProjectSettingsAndItsBadgeShowsThereAndInTheList(): void
    {
        $this->signIn();
        $project = $this->seed();
        $section = $this->sectionId($project, 'Design');
        $jana = $this->membershipId('jana@example.com');

        $crawler = $this->client->request('GET', '/projects/'.$project.'/settings');
        $open = $crawler->filter('[data-settings-section="'.$section.'"] a[data-section-share]');
        self::assertSame('/sections/'.$section.'/sharing', $open->attr('href'));
        self::assertNotNull($open->attr('data-dialog'));
        self::assertSame('Sharing of the section Design', $open->attr('aria-label'));
        self::assertCount(0, $crawler->filter('[data-private-badge]'));

        $crawler = $this->client->request('GET', '/sections/'.$section.'/sharing', server: self::DIALOG);
        self::assertSame('Share the section', trim($crawler->filter('dialog h2')->first()->text()));
        self::assertSame('Design', trim($crawler->filter('#dialog-page-description')->text()));
        $crawler = $this->client->submit($crawler->filter('[data-sharing-private]')->form(['private' => '1']), serverParameters: self::DIALOG);
        self::assertTrue((bool) $this->em->getConnection()->fetchOne('SELECT private FROM project_section WHERE id = ?', [$section]));
        self::assertSelectorTextContains('dialog', 'The section is private now.');
        self::assertSame('/projects/'.$project.'/settings', $crawler->filter('[data-sharing-add] input[name="return"]')->attr('value'));
        $crawler = $this->client->submit($crawler->filter('[data-sharing-add]')->form(['person' => $jana]), serverParameters: self::DIALOG);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM work_share WHERE section_id = ? AND membership_id = ?', [$section, $jana]));
        self::assertSelectorTextContains('dialog', 'Shared with Jana Example.');
        $history = $crawler->filter('[data-sharing-history] li')->each(static fn (Crawler $item): string => trim($item->text()));
        self::assertCount(2, $history);
        self::assertStringContainsString('Example Company: Made the section private', $history[0]);
        self::assertStringContainsString('Example Company: Shared with: Jana Example', $history[1]);
        // The project's own dialog keeps to the project.
        self::assertCount(0, $this->client->request('GET', '/projects/'.$project.'/sharing')->filter('[data-sharing-history]'));

        $crawler = $this->client->request('GET', '/projects/'.$project.'/settings');
        self::assertCount(1, $crawler->filter('[data-settings-section="'.$section.'"] [data-private-badge]'));
        self::assertCount(1, $crawler->filter('[data-private-badge]'));
        $crawler = $this->client->request('GET', '/projects/'.$project);
        self::assertSame('Private', trim($crawler->filter('#section-'.$section.'-title [data-private-badge]')->text()));
        self::assertCount(0, $crawler->filter('#section-'.$this->sectionId($project, 'Build').'-title [data-private-badge]'));
    }

    public function testAMemberKeepsSightOfWhatTheyCloseAndFindsNothingTheyDoNotSee(): void
    {
        $this->signIn(Role::Member);
        $project = $this->seed();
        $me = $this->membershipId('testing@example.com');

        $crawler = $this->client->request('GET', '/projects/'.$project.'/sharing');
        $this->client->submit($crawler->filter('[data-sharing-private]')->form(['visibility' => 'chosen']));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM work_share WHERE project_id = ? AND membership_id = ?', [$project, $me]));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Team member', $crawler->filter('[data-sharing-person="'.$me.'"]')->text());

        $hidden = $this->hiddenProject();
        $token = $this->token($crawler);
        $this->client->request('GET', '/projects/'.$hidden.'/sharing');
        self::assertResponseStatusCodeSame(404);
        foreach (['/sharing/private' => ['visibility' => 'organisation'], '/sharing/people' => ['person' => $me], '/sharing/people/'.$me.'/remove' => []] as $path => $fields) {
            $this->client->request('POST', '/projects/'.$hidden.$path, ['_token' => $token] + $fields);
            self::assertResponseStatusCodeSame(404, $path);
        }
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM work_share WHERE project_id = ?', [$hidden]));
        self::assertSame(ProjectVisibility::Chosen->value, $this->em->getConnection()->fetchOne('SELECT visibility FROM project WHERE id = ?', [$hidden]));
        // Taking back their own share, they no longer see the project: the dialog sends them to the projects.
        $crawler = $this->client->request('GET', '/projects/'.$project.'/sharing', server: self::DIALOG);
        $this->client->submit($crawler->filter('[data-sharing-person="'.$me.'"] form')->form(), serverParameters: self::DIALOG);
        self::assertSame(['location' => '/projects'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM work_share WHERE project_id = ?', [$project]));
        $this->client->request('GET', '/projects/'.$project);
        self::assertResponseStatusCodeSame(404);
    }

    public function testARefusalInTheOpenDialogIsToldThereAndChangesNothing(): void
    {
        $this->signIn();
        $project = $this->seed();

        $crawler = $this->client->request('GET', '/projects/'.$project.'/sharing', server: self::DIALOG);
        $crawler = $this->client->request('POST', '/projects/'.$project.'/sharing/people', ['_token' => $this->token($crawler), 'person' => $this->membershipId('testing@example.com')], server: self::DIALOG);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('0', $crawler->filter('dialog#dialog-page')->attr('data-dialog-reload'));
        self::assertSelectorTextContains('dialog', 'Choose an active person of the organisation to share with.');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM work_share', []));
    }

    public function testAnUnknownItemIsNotFound(): void
    {
        $this->signIn();
        $unknown = '01900000-0000-7000-8000-000000000000';

        foreach (['projects', 'sections', 'tasks'] as $kind) {
            $this->client->request('GET', '/'.$kind.'/'.$unknown.'/sharing');
            self::assertResponseStatusCodeSame(404, $kind);
        }
        $this->client->request('GET', '/labels/'.$unknown.'/sharing');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEveryChangeNeedsItsToken(): void
    {
        $this->signIn();
        $project = $this->seed();
        $task = $this->taskId('Wireframes');
        $jana = $this->membershipId('jana@example.com');

        foreach (['/projects/'.$project.'/sharing/private' => ['visibility' => 'chosen'], '/tasks/'.$task.'/sharing/private' => ['private' => '1'], '/tasks/'.$task.'/sharing/people' => ['person' => $jana], '/tasks/'.$task.'/sharing/people/'.$jana.'/remove' => []] as $path => $fields) {
            $this->client->request('POST', $path, ['_token' => 'wrong'] + $fields);
            self::assertResponseStatusCodeSame(403, $path);
        }
        self::assertSame(ProjectVisibility::Organisation->value, $this->em->getConnection()->fetchOne('SELECT visibility FROM project WHERE id = ?', [$project]));
        self::assertFalse((bool) $this->em->getConnection()->fetchOne('SELECT private FROM task WHERE id = ?', [$task]));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM work_share', []));
    }

    public function testAGuestIsDenied(): void
    {
        $this->signIn(Role::Guest);
        $project = $this->hiddenProject();

        $this->client->request('GET', '/projects/'.$project.'/sharing');
        self::assertResponseStatusCodeSame(403);
        foreach (['/sharing/private', '/sharing/people', '/sharing/people/'.$project.'/remove'] as $path) {
            $this->client->request('POST', '/projects/'.$project.$path);
            self::assertResponseStatusCodeSame(403, $path);
        }
    }

    private function signIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: $role);
        $this->browser->signIn();
    }

    /**
     * Website, with Brief in no section and Wireframes in Design, a section Build;
     * Jana Example as a colleague and Guest Example as a guest.
     */
    private function seed(): string
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website']));
        $id = $this->fetchId('SELECT id FROM project WHERE name = ?', ['Website']);
        $jana = new User('jana@example.com', 'not a hash', 'Jana Example');
        $guest = new User('guest@example.com', 'not a hash', 'Guest Example');
        $this->em->persist($jana);
        $this->em->persist($guest);
        $this->em->flush();
        $this->browser->fixtures->join($jana, $this->tenant, Role::Member);
        $this->browser->fixtures->join($guest, $this->tenant, Role::Guest);
        $this->scoped(function () use ($id): void {
            $project = $this->em->find(Project::class, $id) ?? throw new \LogicException('Project.');
            [$open] = $this->em->getRepository(ProjectStatus::class)->findBy(['project' => $project], ['position' => 'ASC']);
            $userId = $this->fetchId('SELECT id FROM app_user WHERE email = ?', ['testing@example.com']);
            $design = new Section($project, 'Design', 0);
            $now = new \DateTimeImmutable();
            foreach ([$design, new Section($project, 'Build', 1), new Task($project, 'Brief', $open, 0, $userId, $now), new Task($project, 'Wireframes', $open, 1, $userId, $now, $design)] as $entity) {
                $this->em->persist($entity);
            }
            $this->em->flush();
        });

        return $id;
    }

    /**
     * A project only the people chosen see, shared with no one.
     */
    private function hiddenProject(): string
    {
        return $this->scoped(function (): string {
            $project = new Project('Hidden', null, WorkflowTemplate::Simple, $this->fetchId('SELECT id FROM app_user WHERE email = ?', ['testing@example.com']), new \DateTimeImmutable());
            $project->changeVisibility(ProjectVisibility::Chosen);
            $this->em->persist($project);
            $this->em->flush();

            return $project->id();
        });
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('[data-sharing-private] input[name="_token"]')->attr('value');
    }

    private function sectionId(string $project, string $name): string
    {
        return $this->fetchId('SELECT id FROM project_section WHERE project_id = ? AND name = ?', [$project, $name]);
    }

    private function taskId(string $title): string
    {
        return $this->fetchId('SELECT id FROM task WHERE title = ?', [$title]);
    }

    private function membershipId(string $email): string
    {
        return $this->fetchId('SELECT m.id FROM membership m JOIN app_user u ON u.id = m.user_id WHERE u.email = ?', [$email]);
    }

    /**
     * @param list<string> $parameters
     */
    private function rows(string $sql, array $parameters): int
    {
        $count = $this->em->getConnection()->fetchOne($sql, $parameters);

        return is_numeric($count) ? (int) $count : throw new \LogicException('A count.');
    }

    /**
     * @param list<string> $parameters
     */
    private function fetchId(string $sql, array $parameters): string
    {
        $id = $this->em->getConnection()->fetchOne($sql, $parameters);
        self::assertIsString($id);

        return $id;
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
