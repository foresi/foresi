<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Controller;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Work\Domain\Label;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;
use App\Work\Domain\WorkShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The guest side of the work: what is shared with a guest is their start page,
 * the project or the task they see works for them as for the staff, without
 * what only the people of the organisation do; what is not shared with them is
 * not found, and the screens of the staff are denied.
 *
 * Website is open to the organisation, its section "Shared with client" shared
 * with the guest; Office move is open to chosen people only, with one task of
 * it shared with the guest on its own; Hidden is shared with nobody.
 */
final class GuestWorkTest extends WebTestCase
{
    private const string GUEST = 'guest@example.com';

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

    public function testTheStartPageListsTheSharedProjectThenTheTaskSharedOnItsOwnByTheNameOfItsProject(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/');
        self::assertResponseRedirects('/shared');
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        $project = $crawler->filter('[data-shared-projects] tbody tr');
        self::assertCount(1, $project);
        self::assertSame('/projects/'.$this->projectId('Website'), $project->filter('a')->attr('href'));
        self::assertStringContainsString('Shared with client', $project->text());
        $group = $crawler->filter('[data-shared-group]');
        self::assertCount(1, $group);
        self::assertSame('Project: Office move', trim($group->filter('h3')->text()));
        self::assertSame(['Sign the lease'], $group->filter('tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        // The project of the task is known by its name only: no link to it anywhere.
        self::assertCount(0, $crawler->filter('main a[href="/projects/'.$this->projectId('Office move').'"]'));
        self::assertStringNotContainsString('Hidden', $crawler->filter('main')->text());
        self::assertSame(['/shared', '/my-tasks'], $crawler->filter('#sidebar [data-nav-group]:not([data-nav-group="extensions"]) li > a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testAGuestWithNothingSharedSeesTheEmptyStartPage(): void
    {
        $this->seed(shares: false);

        $this->client->request('GET', '/shared');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-shared-empty]', 'Nothing is shared with you yet');
    }

    public function testTheProjectShowsTheSharedSectionAloneWithoutTheActionsOfTheStaff(): void
    {
        $this->seed();
        $website = $this->projectId('Website');

        foreach (['/projects/'.$website, '/projects/'.$website.'/board'] as $page) {
            $crawler = $this->client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
            $main = $crawler->filter('main')->text();
            self::assertStringContainsString('Design the homepage', $main, $page);
            foreach (['Fix the contact form', 'Brief', 'Price offer', 'Backlog'] as $hidden) {
                self::assertStringNotContainsString($hidden, $main, $page.' '.$hidden);
            }
            self::assertCount(0, $crawler->filter('[data-project-settings]'), $page);
            self::assertCount(0, $crawler->filter('[data-project-share]'), $page);
            self::assertCount(0, $crawler->filter('[data-project-trash]'), $page);
            self::assertCount(1, $crawler->filter('main header [data-task-new-open]'), $page);
            self::assertCount(0, $crawler->filter('[data-project-client] a'), $page);
            self::assertSame('/shared', $crawler->filter('main header nav a')->first()->attr('href'), $page);
            self::assertCount(0, $crawler->filter('main a[href*="/delete"], main form[action*="/delete"]'), $page);
        }
        // The filter of who is responsible offers the people of the organisation and no other guest.
        $crawler = $this->client->request('GET', '/projects/'.$website);
        self::assertSame(['Anyone', 'Me', 'Example Company', 'Jana Example'], $crawler->filter('#filter-responsible option')->each(static fn (Crawler $option): string => trim($option->text())));
    }

    public function testWhatIsNotSharedIsNotFound(): void
    {
        $this->seed();

        foreach ([
            '/projects/'.$this->projectId('Hidden'),
            '/projects/'.$this->projectId('Office move'),
            '/projects/'.$this->projectId('Office move').'/board',
            '/projects/'.$this->projectId('Office move').'/tasks/new',
            '/tasks/'.$this->taskId('Fix the contact form'),
            '/tasks/'.$this->taskId('Price offer'),
            '/tasks/'.$this->taskId('Pack the boxes'),
            '/tasks/'.$this->taskId('Fix the contact form').'/labels',
            '/tasks/'.$this->taskId('Fix the contact form').'/mention-candidates',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId('Design the homepage'));
        $token = (string) $crawler->filter('form[data-comment-composer] input[name="_token"]')->attr('value');
        foreach (['comments', 'status', 'title', 'watch'] as $action) {
            $this->client->request('POST', '/tasks/'.$this->taskId('Fix the contact form').'/'.$action, ['_token' => $token, 'body' => 'Hello', 'title' => 'Mine', 'status' => '']);
            self::assertResponseStatusCodeSame(404, $action);
        }
    }

    public function testTheScreensOfTheStaffAreDenied(): void
    {
        $this->seed();
        $website = $this->projectId('Website');
        $homepage = $this->taskId('Design the homepage');

        $this->client->request('GET', '/projects');
        self::assertResponseRedirects('/shared');
        foreach (['GET' => ['/projects/new', '/projects/'.$website.'/settings', '/projects/'.$website.'/trash', '/projects/'.$website.'/sharing', '/tasks/'.$homepage.'/sharing', '/settings/labels'], 'POST' => ['/tasks/'.$homepage.'/delete', '/tasks/'.$homepage.'/restore', '/tasks/'.$homepage.'/sharing/private']] as $method => $urls) {
            foreach ($urls as $url) {
                $this->client->request($method, $url);
                self::assertResponseStatusCodeSame(403, $method.' '.$url);
            }
        }
    }

    public function testATaskSharedOnItsOwnNamesItsProjectWithoutALinkAndShowsNothingElseOfIt(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId('Sign the lease'));

        self::assertResponseIsSuccessful();
        $crumbs = $crawler->filter('main header nav li');
        self::assertSame(['Shared with me', 'Office move', 'Sign the lease'], $crumbs->each(static fn (Crawler $crumb): string => trim($crumb->text())));
        self::assertSame('/shared', $crumbs->eq(0)->filter('a')->attr('href'));
        self::assertCount(0, $crumbs->eq(1)->filter('a'));
        self::assertSame('p', $crawler->filter('[data-task-project]')->nodeName());
        self::assertStringNotContainsString('Pack the boxes', $crawler->filter('main')->text());
        // No section to move it to: the guest sees none of the project.
        self::assertCount(0, $crawler->filter('[data-task-property="section"]'));
        self::assertCount(0, $crawler->filter('[data-task-share], [data-task-menu]'));
        // It may be given to the guest, who sees it, and to the people of the organisation.
        self::assertSame(['Nobody', 'Example Company', 'Guest Example'], $crawler->filter('#task-responsible option')->each(static fn (Crawler $option): string => trim($option->text())));
    }

    public function testAGuestCommentsInTheOpenWithoutSeeingInternalComments(): void
    {
        $this->seed();
        $homepage = $this->taskId('Design the homepage');

        $crawler = $this->client->request('GET', '/tasks/'.$homepage);
        self::assertStringContainsString('Looks good to the client.', $crawler->filter('[data-task-discussion]')->text());
        self::assertStringNotContainsString('Internal note on the budget.', $crawler->filter('main')->text());
        self::assertCount(0, $crawler->filter('#comment-internal'));
        self::assertSelectorTextContains('[data-comment-composer]', 'Everyone who sees this task will see the comment.');

        $form = $crawler->filter('form[data-comment-composer]')->form(['body' => 'Please make the logo **bigger**.']);
        $values = $form->getPhpValues();
        $values['internal'] = '1';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(303);
        // A guest never writes an internal comment, whatever the form sends.
        self::assertFalse((bool) $this->em->getConnection()->fetchOne('SELECT internal FROM task_comment WHERE body = ?', ['Please make the logo **bigger**.']));
        $comment = $this->fetchId('SELECT id FROM task_comment WHERE body = ?', ['Please make the logo **bigger**.']);

        $crawler = $this->client->request('GET', '/tasks/'.$homepage.'?edit_comment='.$comment);
        $this->client->submit($crawler->filter('form[action="/comments/'.$comment.'/edit"]')->form(['body' => 'Please make the logo larger.']));
        self::assertResponseRedirects('/tasks/'.$homepage.'#comment-'.$comment);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM task_comment WHERE id = ? AND body = ?', [$comment, 'Please make the logo larger.']));
        $crawler = $this->client->request('GET', '/tasks/'.$homepage);
        $this->client->submit($crawler->filter('form[action="/comments/'.$comment.'/delete"]')->form());
        self::assertResponseRedirects('/tasks/'.$homepage.'#comment-'.$comment);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM task_comment WHERE id = ? AND deleted_at IS NOT NULL', [$comment]));
    }

    public function testAGuestAddsATaskOnlyWhereTheySeeASectionAndASubtaskToATaskSharedWithThem(): void
    {
        $this->seed();
        $website = $this->projectId('Website');

        $crawler = $this->client->request('GET', '/projects/'.$website.'/tasks/new');
        self::assertResponseIsSuccessful();
        self::assertSame(['Shared with client'], $crawler->filter('#task-new-section option')->each(static fn (Crawler $option): string => trim($option->text())));
        $form = $crawler->filter('form[data-task-new]')->form(['title' => 'Send the logo']);
        $values = $form->getPhpValues();
        $values['section'] = $this->sectionId('Backlog');
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM task WHERE title = ?', ['Send the logo']));
        $this->client->submit($form);
        self::assertResponseRedirects('/projects/'.$website);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM task WHERE title = ? AND section_id = ?', ['Send the logo', $this->sectionId('Shared with client')]));

        $lease = $this->taskId('Sign the lease');
        $crawler = $this->client->request('GET', '/tasks/'.$lease.'/subtasks/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form')->reduce(static fn (Crawler $form): bool => str_contains((string) $form->attr('action'), '/subtasks/new'))->form(['title' => 'Read the contract']));
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM task WHERE title = ? AND parent_id = ?', ['Read the contract', $lease]));
    }

    public function testAGuestMovesTheStatusSetsLabelsWatchesAndAttachesAFile(): void
    {
        $this->seed();
        $homepage = $this->taskId('Design the homepage');

        $crawler = $this->client->request('GET', '/tasks/'.$homepage);
        $statuses = $crawler->filter('#task-status option');
        $this->client->submit($crawler->filter('form[data-task-property="status"]')->form(['status' => (string) $statuses->eq(1)->attr('value')]));
        self::assertResponseRedirects('/tasks/'.$homepage);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM task WHERE id = ? AND status_id = ?', [$homepage, (string) $statuses->eq(1)->attr('value')]));

        $crawler = $this->client->request('GET', '/tasks/'.$homepage.'/labels');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->reduce(static fn (Crawler $form): bool => str_ends_with((string) $form->attr('action'), '/labels'))->form();
        $values = $form->getPhpValues();
        $values['labels'] = [(string) $crawler->filter('input[name="labels[]"]')->attr('value')];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM task_label WHERE task_id = ?', [$homepage]));

        $crawler = $this->client->request('GET', '/tasks/'.$homepage);
        $this->client->submit($crawler->filter('form[data-task-watch]')->form());
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM task_watcher w JOIN membership m ON m.id = w.membership_id JOIN app_user u ON u.id = m.user_id WHERE w.task_id = ? AND u.email = ?', [$homepage, self::GUEST]));

        $crawler = $this->client->request('GET', '/tasks/'.$homepage);
        $token = (string) $crawler->filter('form[data-attachment-upload] input[name="_token"]')->attr('value');
        $file = tempnam(sys_get_temp_dir(), 'guest');
        file_put_contents($file, 'The brief of the client.');
        $this->client->request('POST', '/attachments', ['_token' => $token, 'owner_kind' => 'task', 'owner_id' => $homepage], ['file' => new UploadedFile($file, 'brief.txt', 'text/plain', null, true)]);
        self::assertResponseStatusCodeSame(201);
        $attachment = $this->fetchId('SELECT id FROM attachment WHERE file_name = ?', ['brief.txt']);
        $this->client->request('GET', '/attachments/'.$attachment);
        self::assertResponseIsSuccessful();
    }

    public function testMyTasksOfAGuestNameAProjectTheyDoNotSeeWithoutALink(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/my-tasks');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('tbody tr')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'Sign the lease'));
        self::assertCount(1, $row);
        self::assertStringContainsString('Office move', $row->text());
        self::assertCount(0, $row->filter('a[href^="/projects/"]'));
    }

    public function testTheStaffOpeningTheGuestStartPageGoToTheProjects(): void
    {
        $this->seed(signInAsGuest: false);

        $this->client->request('GET', '/shared');

        self::assertResponseRedirects('/projects');
    }

    /**
     * The three projects, as the owner made them; then the guest signs in, unless told not to.
     */
    private function seed(bool $shares = true, bool $signInAsGuest = true): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation']);
        $this->browser->signIn();
        foreach (['Website', 'Office move', 'Hidden'] as $name) {
            $crawler = $this->client->request('GET', '/projects/new');
            $this->client->submit($crawler->filter('#project-form')->form(['name' => $name]));
        }
        $jana = new User('jana@example.com', 'not a hash', 'Jana Example');
        $guest = new User(self::GUEST, password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Guest Example');
        $other = new User('other-guest@example.com', 'not a hash', 'Other Guest');
        foreach ([$jana, $guest, $other] as $user) {
            $this->em->persist($user);
        }
        $this->em->flush();
        $this->browser->fixtures->join($jana, $this->tenant, Role::Member);
        $this->browser->fixtures->join($guest, $this->tenant, Role::Guest);
        $this->browser->fixtures->join($other, $this->tenant, Role::Guest);
        $this->scoped(function () use ($shares): void {
            $owner = $this->fetchId('SELECT id FROM app_user WHERE email = ?', [IdentityFixtures::EMAIL]);
            $now = new \DateTimeImmutable();
            $website = $this->project('Website');
            [$open] = $this->em->getRepository(ProjectStatus::class)->findBy(['project' => $website], ['position' => 'ASC']);
            $shared = new Section($website, 'Shared with client', 0);
            $backlog = new Section($website, 'Backlog', 1);
            $homepage = new Task($website, 'Design the homepage', $open, 0, $owner, $now, $shared);
            $offer = new Task($website, 'Price offer', $open, 1, $owner, $now, $shared);
            $offer->makePrivate(true);
            $office = $this->project('Office move');
            $office->changeVisibility(ProjectVisibility::Chosen);
            [$officeOpen] = $this->em->getRepository(ProjectStatus::class)->findBy(['project' => $office], ['position' => 'ASC']);
            $lease = new Task($office, 'Sign the lease', $officeOpen, 0, $owner, $now);
            $hidden = $this->project('Hidden');
            $hidden->changeVisibility(ProjectVisibility::Chosen);
            [$hiddenOpen] = $this->em->getRepository(ProjectStatus::class)->findBy(['project' => $hidden], ['position' => 'ASC']);
            $ownerMembership = $this->membership(IdentityFixtures::EMAIL);
            $guestMembership = $this->membership(self::GUEST);
            $lease->assign($guestMembership);
            foreach ([
                $shared, $backlog, $homepage, $offer, $lease,
                new Task($website, 'Fix the contact form', $open, 2, $owner, $now, $backlog),
                new Task($website, 'Brief', $open, 3, $owner, $now),
                new Task($office, 'Pack the boxes', $officeOpen, 1, $owner, $now),
                new Task($hidden, 'Secret plan', $hiddenOpen, 0, $owner, $now),
                new TaskComment($homepage, $ownerMembership, 'Internal note on the budget.', true, $now),
                new TaskComment($homepage, $ownerMembership, 'Looks good to the client.', false, $now->modify('+1 second')),
                new Label('Design', LabelColour::Green),
            ] as $entity) {
                $this->em->persist($entity);
            }
            if ($shares) {
                $this->em->persist(WorkShare::of($shared, $guestMembership, $now));
                $this->em->persist(WorkShare::of($lease, $guestMembership, $now));
            }
            $this->em->flush();
        });
        if ($signInAsGuest) {
            $this->client->getCookieJar()->clear();
            $crawler = $this->client->request('GET', '/login');
            $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => self::GUEST, '_password' => IdentityFixtures::PASSWORD]));
        }
    }

    private function project(string $name): Project
    {
        return $this->em->getRepository(Project::class)->findOneBy(['name' => $name]) ?? throw new \LogicException('Project '.$name.'.');
    }

    private function membership(string $email): Membership
    {
        return $this->em->find(Membership::class, $this->fetchId('SELECT m.id FROM membership m JOIN app_user u ON u.id = m.user_id WHERE u.email = ?', [$email])) ?? throw new \LogicException('Membership.');
    }

    private function projectId(string $name): string
    {
        return $this->fetchId('SELECT id FROM project WHERE name = ?', [$name]);
    }

    private function sectionId(string $name): string
    {
        return $this->fetchId('SELECT id FROM project_section WHERE name = ?', [$name]);
    }

    private function taskId(string $title): string
    {
        return $this->fetchId('SELECT id FROM task WHERE title = ?', [$title]);
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
