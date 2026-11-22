<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Work\Application\View\NotificationLists;
use Foresi\Work\Domain\Notification;
use Foresi\Work\Domain\NotificationKind;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\WorkflowTemplate;
use Foresi\Work\Domain\WorkShare;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The inbox on 20 November 2026: Jana Example mentioned me on "Fix the form",
 * assigned me "Call the client" and then commented on it, changed the status
 * of "Write the copy" (read already) and shared "Old news" with me (done).
 * Marek Member has a row of his own. Opening a task reads its row.
 */
final class NotificationControllerTest extends WebTestCase
{
    private const string GUEST = 'guest@example.com';
    private const array RECIPIENTS = ['owner' => IdentityFixtures::EMAIL, 'marek' => 'marek@example.com', 'guest' => self::GUEST];

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

    public function testTheInboxListsOneRowPerTaskNewestFirstWithItsLatestEvent(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Notifications');
        self::assertSame(['Fix the form', 'Call the client', 'Write the copy'], $this->titles($crawler));
        self::assertSame(['true', 'true', 'false'], $crawler->filter('[data-notifications] tbody tr [data-unread]')->each(static fn (Crawler $mark): string => (string) $mark->attr('data-unread')));
        $cells = $crawler->filter('[data-notifications] tbody tr')->each(static fn (Crawler $row): array => array_map(trim(...), $row->filter('td')->slice(1, 3)->each(static fn (Crawler $cell): string => $cell->text())));
        self::assertSame([
            ['Website', 'Jana Example: Mentioned you in a comment', '2026-11-20 10:00'],
            ['Website', 'Jana Example: New comment (+1 more)', '2026-11-20 09:30'],
            ['Website', 'Jana Example: Changed the status', '2026-11-20 08:00'],
        ], $cells);
        self::assertSame('Website · Jana Example: Mentioned you in a comment · 2026-11-20 10:00', trim($crawler->filter('[data-row-secondary]')->first()->text()));
        self::assertSame('/tasks/'.$this->taskId('Fix the form'), $crawler->filter('[data-notifications] [data-row-link]')->first()->attr('href'));
        // Done, in the row menu, for this row; no prefetch on hover, which would read the row.
        $done = $crawler->filter('[data-notifications] tbody tr')->first()->filter('[data-row-actions] form')->first();
        self::assertSame('/notifications/'.$this->notificationId('Fix the form', 'owner').'/done', $done->attr('action'));
        self::assertSame('Done', $done->filter('button[role="menuitem"]')->text());
        self::assertSame('false', $crawler->filter('[data-notifications]')->attr('data-turbo-prefetch'));
        // The header reads them all; there are two unread, and the sidebar says so in its main group.
        self::assertSame('/notifications/read', $crawler->filter('[data-notifications-read-all]')->attr('action'));
        self::assertSame('2', trim($crawler->filter('#sidebar [data-nav-area="overview"] [data-nav-section] a[href="/notifications"] [data-nav-count]')->text()));
        self::assertSame('page', $crawler->filter('#sidebar a[href="/notifications"]')->attr('aria-current'));
        self::assertSame(['/', '/notifications'], $crawler->filter('#sidebar [data-nav-area="overview"] [data-nav-section] li > a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertSame(['All', 'Done'], $crawler->filter('main nav[aria-label="Notification views"] a')->each(static fn (Crawler $a): string => trim($a->text())));
        self::assertCount(0, $crawler->filter('[data-notifications-kept]'));
        self::assertCount(0, $crawler->filter('main nav[aria-label="Pagination"]'));
    }

    public function testTheDoneViewListsTheDoneRowsAndSaysHowLongTheyAreKept(): void
    {
        $this->seed();

        // The old address of the tab opens the view it became.
        foreach (['/notifications?tab=done', '/notifications?view=done'] as $url) {
            $crawler = $this->client->request('GET', $url);

            self::assertResponseIsSuccessful();
            self::assertSame(['Old news'], $this->titles($crawler), $url);
            self::assertSame('page', $crawler->filter('main nav[aria-label="Notification views"] a')->eq(1)->attr('aria-current'), $url);
        }
        $crawler = $this->client->request('GET', '/notifications?tab=done');
        self::assertStringContainsString('Jana Example: Shared the task with you', $crawler->filter('[data-notifications] tbody tr')->text());
        self::assertSame('Done notifications are kept for 3 months, then deleted.', trim($crawler->filter('[data-notifications-kept]')->text()));
        $back = $crawler->filter('[data-notifications] [data-row-actions] form')->first();
        self::assertSame('/notifications/'.$this->notificationId('Old news', 'owner').'/undone?view=done', $back->attr('action'));
        self::assertSame('Back to new', $back->filter('button[role="menuitem"]')->text());
        self::assertSame('done', $crawler->filter('[data-notifications-filter] [data-filter-sheet-form] input[name="view"]')->attr('value'));
        self::assertSame('/notifications/read?view=done', $crawler->filter('[data-notifications-read-all]')->attr('action'));
        self::assertSame(['/notifications', '/notifications?view=done'], $crawler->filter('main nav[aria-label="Notification views"] a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
    }

    public function testTheOldFilterIsAConditionOfTheBuilder(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/notifications?filter=mentions');
        self::assertSame(['Fix the form'], $this->titles($crawler));
        // No fixed select sits above "+ Filter" (spec D11); the condition is a chip, and the links carry it in f=.
        self::assertCount(0, $crawler->filter('select[name="filter"]'));
        self::assertSame(['All, unsaved changes', 'Done'], $this->tabs($crawler));
        self::assertStringContainsString('Reason: Mentions', $crawler->filter('[data-notifications-filter]')->text());
        self::assertSame($this->mentions(), $crawler->filter('[data-notifications-filter] [data-filter-sheet-form] input[name="f"]')->attr('value'));
        self::assertSame('/notifications/read?f='.$this->mentions(), $crawler->filter('[data-notifications-read-all]')->attr('action'));
        self::assertSame(['mentions', 'assigned'], $crawler->filter('[data-filter-rows] [data-filter-row="reason"] input[type="checkbox"]')->each(static fn (Crawler $box): string => $box->attr('value') ?? ''));

        $crawler = $this->client->request('GET', '/notifications?filter=assigned');
        self::assertSame(['Call the client'], $this->titles($crawler));

        $crawler = $this->client->request('GET', '/notifications?tab=done&filter=assigned');
        self::assertSame('Nothing done yet', $this->emptyTitle($crawler));
        self::assertSame('Nothing matches the filters.', $this->emptyText($crawler));
        self::assertSame('/notifications?view=done', $crawler->filter('[data-notifications-empty] a')->attr('href'));

        // A value the select did not have narrows nothing, as before.
        foreach (['all', 'nonsense'] as $value) {
            $crawler = $this->client->request('GET', '/notifications?filter='.$value);
            self::assertSame(['Fix the form', 'Call the client', 'Write the copy'], $this->titles($crawler), $value);
            self::assertSame(['All', 'Done'], $this->tabs($crawler), $value);
        }
        // A condition of the address wins over the old select.
        $assigned = WorkingFilter::encode(ViewFilter::all(new Condition(NotificationLists::REASON, 'is', NotificationLists::ASSIGNED)));
        self::assertSame(['Call the client'], $this->titles($this->client->request('GET', '/notifications?filter=mentions&f='.$assigned)));
    }

    public function testTheBuilderNarrowsTheInboxAndItsChangesAreSavedAsANewView(): void
    {
        $this->seed();
        $unread = '/notifications?f='.WorkingFilter::encode(ViewFilter::all(new Condition(NotificationLists::UNREAD, 'is', true)));

        $this->client->request('GET', '/notifications?'.http_build_query(['filter' => 'assigned', 'page' => '2', 'conditions' => '1', 'c' => [
            ['field' => 'unread', 'op' => 'is', 'value' => '1'],
        ]]));
        self::assertResponseStatusCodeSame(303);
        self::assertSame($unread, $this->client->getResponse()->headers->get('Location'), 'The old select and the page go; All is the bare list.');
        $crawler = $this->client->followRedirect();
        self::assertSame(['Fix the form', 'Call the client'], $this->titles($crawler));
        self::assertSame(['All, unsaved changes', 'Done'], $this->tabs($crawler));
        // The Done view says which of done it wants: its read rows.
        $this->client->request('GET', '/notifications?'.http_build_query(['view' => 'done', 'conditions' => '1', 'c' => [['field' => 'done', 'op' => 'is', 'value' => '1'], ['field' => 'unread', 'op' => 'is', 'value' => '0']]]));
        self::assertSame(['Old news'], $this->titles($this->client->followRedirect()));

        $crawler = $this->client->request('GET', $unread);
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'Unread']));
        $crawler = $this->client->followRedirect();
        self::assertSame(['All', 'Done', 'Unread'], $this->tabs($crawler));
        self::assertSame(['Fix the form', 'Call the client'], $this->titles($crawler));
    }

    public function testATimeOrAReasonNarrowsTheInbox(): void
    {
        $this->seed();
        $at = static fn (string $op, string $day): string => '/notifications?f='.WorkingFilter::encode(ViewFilter::all(new Condition('at', $op, $day)));

        self::assertSame(['Fix the form', 'Call the client', 'Write the copy'], $this->titles($this->client->request('GET', $at('is', '2026-11-20'))));
        self::assertSame([], $this->titles($this->client->request('GET', $at('before', '2026-11-20'))));
        // The task and the project are text fields of the catalogue (D4).
        $text = static fn (string $field, string $value): string => '/notifications?f='.WorkingFilter::encode(ViewFilter::all(new Condition($field, 'contains', $value)));
        self::assertSame(['Call the client'], $this->titles($this->client->request('GET', $text('task', 'client'))));
        self::assertSame(['Fix the form', 'Call the client', 'Write the copy'], $this->titles($this->client->request('GET', $text('project', 'web'))));
        self::assertSame([], $this->titles($this->client->request('GET', $text('project', 'nothing'))));
        $crawler = $this->client->request('GET', '/notifications?f='.WorkingFilter::encode(ViewFilter::all(new Condition(NotificationLists::DONE, 'is', false), new Condition(NotificationLists::REASON, 'is_none_of', [NotificationLists::MENTIONS, NotificationLists::ASSIGNED]))));
        self::assertSame(['Write the copy'], $this->titles($crawler));
        // A view of its own that holds nothing says so in words of its own.
        $crawler = $this->client->request('GET', $at('before', '2026-11-20'));
        self::assertSame('No new notifications', $this->emptyTitle($crawler));
    }

    public function testTheColumnsSheetHidesAColumn(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/notifications?cols=task,at');

        self::assertSame(['Task', 'When', 'Actions'], $crawler->filter('[data-notifications] thead th')->reduce(static fn (Crawler $th): bool => '' !== trim($th->text()))->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertSame(['Fix the form', 'Call the client', 'Write the copy'], $this->titles($crawler));
        self::assertSame('/notifications/'.$this->notificationId('Fix the form', 'owner').'/done?cols=task,at', urldecode((string) $crawler->filter('[data-notifications] tbody tr')->first()->filter('[data-row-actions] form')->attr('action')));
    }

    public function testMarkingAllReadClearsTheCountAndKeepsTheView(): void
    {
        $this->seed();
        $crawler = $this->client->request('GET', '/notifications?filter=mentions');

        $this->client->submit($crawler->filter('[data-notifications-read-all]')->form());

        self::assertResponseRedirects('/notifications?f='.$this->mentions());
        $crawler = $this->client->followRedirect();
        self::assertSame(['false'], $crawler->filter('[data-notifications] [data-unread]')->each(static fn (Crawler $mark): string => (string) $mark->attr('data-unread')));
        self::assertCount(0, $crawler->filter('#sidebar a[href="/notifications"] [data-nav-count]'));
        self::assertCount(0, $crawler->filter('[data-notifications-read-all]'));
        self::assertSame([null, null], [$this->field('Fix the form', 'owner', 'done_at'), $this->field('Call the client', 'owner', 'done_at')]);
        self::assertNotNull($this->field('Call the client', 'owner', 'read_at'));
        // Marek's row is his.
        self::assertNull($this->field('Fix the form', 'marek', 'read_at'));
    }

    public function testARowIsDoneAndBackAgain(): void
    {
        $this->seed();
        $crawler = $this->client->request('GET', '/notifications?filter=mentions');

        $this->client->submit($crawler->filter('[data-notifications] [data-row-actions] form')->first()->form());

        self::assertResponseRedirects('/notifications?f='.$this->mentions());
        self::assertNotNull($this->field('Fix the form', 'owner', 'done_at'));
        // Done reads it too.
        self::assertNotNull($this->field('Fix the form', 'owner', 'read_at'));
        $crawler = $this->client->followRedirect();
        self::assertSame('No new notifications', $this->emptyTitle($crawler));
        self::assertSame('1', trim($crawler->filter('#sidebar a[href="/notifications"] [data-nav-count]')->text()));

        $crawler = $this->client->request('GET', '/notifications?view=done');
        self::assertSame(['Fix the form', 'Old news'], $this->titles($crawler));
        $this->client->submit($crawler->filter('[data-notifications] [data-row-actions] form')->first()->form());

        self::assertResponseRedirects('/notifications?view=done');
        self::assertNull($this->field('Fix the form', 'owner', 'done_at'));
        $crawler = $this->client->request('GET', '/notifications');
        self::assertSame(['Fix the form', 'Call the client', 'Write the copy'], $this->titles($crawler));
    }

    public function testEveryActionNeedsTheToken(): void
    {
        $this->seed();
        $id = $this->notificationId('Fix the form', 'owner');

        foreach (['/notifications/read', '/notifications/'.$id.'/done', '/notifications/'.$id.'/undone'] as $url) {
            $this->client->request('POST', $url, ['_token' => 'wrong']);
            self::assertResponseStatusCodeSame(403, $url);
        }
        self::assertNull($this->field('Fix the form', 'owner', 'read_at'));
        self::assertNull($this->field('Fix the form', 'owner', 'done_at'));
    }

    public function testTheRowOfSomeoneElseIsNotFound(): void
    {
        $this->seed();
        $crawler = $this->client->request('GET', '/notifications');
        $token = (string) $crawler->filter('[data-notifications-read-all] input[name="_token"]')->attr('value');
        $marek = $this->notificationId('Fix the form', 'marek');

        foreach (['done', 'undone'] as $action) {
            $this->client->request('POST', '/notifications/'.$marek.'/'.$action, ['_token' => $token]);
            self::assertResponseStatusCodeSame(404, $action);
        }
        self::assertNull($this->field('Fix the form', 'marek', 'done_at'));
        $this->client->request('POST', '/notifications/not-an-id/done', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOpeningTheTaskReadsItsRowButAPrefetchDoesNot(): void
    {
        $this->seed();
        $task = '/tasks/'.$this->taskId('Fix the form');

        $this->client->request('GET', $task, server: ['HTTP_X_SEC_PURPOSE' => 'prefetch']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->field('Fix the form', 'owner', 'read_at'));

        $crawler = $this->client->request('GET', $task);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->field('Fix the form', 'owner', 'read_at'));
        self::assertNull($this->field('Fix the form', 'owner', 'done_at'));
        self::assertNull($this->field('Fix the form', 'marek', 'read_at'));
        // The page itself already counts one fewer.
        self::assertSame('1', trim($crawler->filter('#sidebar a[href="/notifications"] [data-nav-count]')->text()));
    }

    public function testAnEmptyInboxSaysSoWithoutTheHeaderActionOrACount(): void
    {
        $this->browser->seed(role: Role::Member);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSame('No new notifications', $this->emptyTitle($crawler));
        self::assertSame('Mentions, tasks assigned to you and news on the tasks you watch show up here.', $this->emptyText($crawler));
        self::assertCount(0, $crawler->filter('[data-notifications-read-all]'));
        self::assertCount(1, $crawler->filter('#sidebar a[href="/notifications"]'));
        self::assertCount(0, $crawler->filter('#sidebar a[href="/notifications"] [data-nav-count]'));

        $crawler = $this->client->request('GET', '/notifications?tab=done');
        self::assertSame('Nothing done yet', $this->emptyTitle($crawler));
        self::assertSame('Notifications you mark as done stay here for 3 months.', $this->emptyText($crawler));
    }

    public function testManyRowsComeInPagesAndTheSidebarCountStopsAt99(): void
    {
        $this->seed(extra: 100);

        $crawler = $this->client->request('GET', '/notifications');
        self::assertCount(50, $crawler->filter('[data-notifications] tbody tr'));
        self::assertSame('99+', trim($crawler->filter('#sidebar a[href="/notifications"] [data-nav-count]')->text()));
        self::assertSame('/notifications?page=2', $crawler->filter('main nav[aria-label="Pagination"] a[aria-label="Next page"]')->attr('href'));

        $crawler = $this->client->request('GET', '/notifications?page=2');
        self::assertCount(50, $crawler->filter('[data-notifications] tbody tr'));
        self::assertSame('/notifications/'.$this->notificationId('Extra 50', 'owner').'/done?page=2', $crawler->filter('[data-notifications] tbody tr')->first()->filter('[data-row-actions] form')->attr('action'));
        $crawler = $this->client->request('GET', '/notifications?page=3');
        self::assertSame(['Fix the form', 'Call the client', 'Write the copy'], $this->titles($crawler));
        self::assertSame('/notifications/read?page=3', $crawler->filter('[data-notifications-read-all]')->attr('action'));

        // A page past the end goes back to the first.
        $this->client->request('GET', '/notifications?page=9&filter=mentions');
        self::assertResponseRedirects('/notifications?f='.$this->mentions());
    }

    public function testAGuestHasTheInboxOfWhatIsSharedWithThem(): void
    {
        $this->seed();
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => self::GUEST, '_password' => IdentityFixtures::PASSWORD]));

        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame(['Sign the lease'], $this->titles($crawler));
        // A guest has no saved views: the tabs and the filter, without the views' menu, actions or dialogs.
        self::assertSame(['All', 'Done'], $this->tabs($crawler));
        self::assertCount(0, $crawler->filter('[data-view-save-as-open], [data-view-manage-open], [data-view-save-as-form]'));
        self::assertCount(1, $crawler->filter('[data-notifications-filter] [data-filter-sheet-form]'));
        $crawler = $this->client->request('GET', '/notifications?cols=task&f='.WorkingFilter::encode(ViewFilter::all(new Condition(NotificationLists::UNREAD, 'is', true))));
        self::assertSame(['Sign the lease'], $this->titles($crawler));
        self::assertCount(0, $crawler->filter('[data-view-actions]'));
        $crawler = $this->client->request('GET', '/notifications');
        self::assertStringContainsString('Example Company: Shared the task with you', $crawler->filter('[data-notifications] tbody tr')->text());
        self::assertSame(['/shared', '/notifications', '/connected-apps'], $crawler->filter('#sidebar [data-nav-area="overview"] [data-nav-section] li > a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertSame('1', trim($crawler->filter('#sidebar a[href="/notifications"] [data-nav-count]')->text()));

        // The row of the owner is not theirs.
        $token = (string) $crawler->filter('[data-notifications-read-all] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/notifications/'.$this->notificationId('Fix the form', 'owner').'/done', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/notifications/'.$this->notificationId('Sign the lease', 'guest').'/done', ['_token' => $token]);
        self::assertResponseRedirects('/notifications');
        self::assertNotNull($this->field('Sign the lease', 'guest', 'done_at'));
    }

    /**
     * The owner (me) with Jana Example, Marek Member and a guest, the project
     * Website open to all, and Office move with one task shared with the guest.
     */
    private function seed(int $extra = 0): void
    {
        $this->browser->seed();
        $this->browser->signIn();
        $jana = new User('jana@example.com', 'not a hash', 'Jana Example');
        $marek = new User('marek@example.com', 'not a hash', 'Marek Member');
        $guest = new User(self::GUEST, password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Guest Example');
        foreach ([$jana, $marek, $guest] as $user) {
            $this->em->persist($user);
        }
        $this->em->flush();
        $this->browser->fixtures->join($jana, Role::Member);
        $this->browser->fixtures->join($marek, Role::Member);
        $this->browser->fixtures->join($guest, Role::Guest);
        $me = $this->membership(IdentityFixtures::EMAIL);
        $janaMember = $this->membership('jana@example.com');
        $marekMember = $this->membership('marek@example.com');
        $guestMember = $this->membership(self::GUEST);
        $userId = $me->user()->id();
        $now = new \DateTimeImmutable('2026-11-01');
        $website = new Project('Website', null, WorkflowTemplate::Simple, $userId, $now);
        $office = new Project('Office move', null, WorkflowTemplate::Simple, $userId, $now);
        $office->changeVisibility(ProjectVisibility::Chosen);
        $this->em->persist($website);
        $this->em->persist($office);
        $open = new ProjectStatus($website, 'To do', StatusType::Open, 0);
        $officeOpen = new ProjectStatus($office, 'To do', StatusType::Open, 0);
        $this->em->persist($open);
        $this->em->persist($officeOpen);
        $tasks = [];
        foreach (['Fix the form', 'Call the client', 'Write the copy', 'Old news'] as $position => $title) {
            $tasks[$title] = new Task($website, $title, $open, $position, $userId, $now);
            $this->em->persist($tasks[$title]);
        }
        $lease = new Task($office, 'Sign the lease', $officeOpen, 0, $userId, $now);
        $this->em->persist($lease);
        $this->em->persist(WorkShare::of($lease, $guestMember, $now));
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-11-20 '.$time, new \DateTimeZone('UTC'));

        $this->em->persist(new Notification($me, $tasks['Fix the form'], NotificationKind::Mentioned, $janaMember, $at('10:00')));
        $assigned = new Notification($me, $tasks['Call the client'], NotificationKind::Assigned, $janaMember, $at('09:00'));
        $assigned->record(NotificationKind::Commented, $janaMember, $at('09:30'));
        $this->em->persist($assigned);
        $status = new Notification($me, $tasks['Write the copy'], NotificationKind::StatusChanged, $janaMember, $at('08:00'));
        $status->markRead($at('08:30'));
        $this->em->persist($status);
        $old = new Notification($me, $tasks['Old news'], NotificationKind::Shared, $janaMember, $at('07:00'));
        $old->markDone($at('07:30'));
        $this->em->persist($old);
        $this->em->persist(new Notification($marekMember, $tasks['Fix the form'], NotificationKind::Commented, $janaMember, $at('10:00')));
        $this->em->persist(new Notification($guestMember, $lease, NotificationKind::Shared, $me, $at('06:00')));
        for ($i = 1; $i <= $extra; ++$i) {
            $task = new Task($website, 'Extra '.$i, $open, 10 + $i, $userId, $now);
            $this->em->persist($task);
            $this->em->persist(new Notification($me, $task, NotificationKind::Commented, $janaMember, $at('11:00')->modify('+'.$i.' seconds')));
        }
        $this->em->flush();
    }

    private function membership(string $email): Membership
    {
        $id = $this->em->getConnection()->fetchOne('SELECT m.id FROM membership m JOIN app_user u ON u.id = m.user_id WHERE u.email = ?', [$email]);
        self::assertIsString($id);

        return $this->em->find(Membership::class, $id) ?? throw new \LogicException('Membership.');
    }

    private function mentions(): string
    {
        return WorkingFilter::encode(ViewFilter::all(new Condition(NotificationLists::REASON, 'is', NotificationLists::MENTIONS)));
    }

    /**
     * @return list<string>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('main nav[aria-label="Notification views"] a')->each(static fn (Crawler $tab): string => trim((string) preg_replace('/\s+/', ' ', $tab->text())));
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('[data-notifications] tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $link): string => trim($link->text()));
    }

    private function emptyTitle(Crawler $crawler): string
    {
        return trim($crawler->filter('[data-notifications-empty] h2, [data-notifications-empty] h3')->first()->text());
    }

    private function emptyText(Crawler $crawler): string
    {
        return trim($crawler->filter('[data-notifications-empty] p')->first()->text());
    }

    private function taskId(string $title): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM task WHERE title = ?', [$title]);
        self::assertIsString($id);

        return $id;
    }

    private function notificationId(string $title, string $recipient): string
    {
        $id = $this->field($title, $recipient, 'n.id');
        self::assertIsString($id);

        return $id;
    }

    private function field(string $title, string $recipient, string $column): mixed
    {
        $column = str_contains($column, '.') ? $column : 'n.'.$column;
        $value = $this->em->getConnection()->fetchOne(
            'SELECT '.$column.' FROM notification n JOIN task t ON t.id = n.task_id JOIN membership m ON m.id = n.recipient_id JOIN app_user u ON u.id = m.user_id WHERE t.title = ? AND u.email = ?',
            [$title, self::RECIPIENTS[$recipient]],
        );

        return false === $value ? throw new \LogicException('No row of '.$title.' for '.$recipient.'.') : $value;
    }
}
