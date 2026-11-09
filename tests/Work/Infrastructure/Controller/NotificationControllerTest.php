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
use App\Work\Domain\Notification;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\WorkflowTemplate;
use App\Work\Domain\WorkShare;
use Doctrine\ORM\EntityManagerInterface;
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
    private TenantContext $context;
    private TenantId $tenant;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em, $this->context);
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
        // Done, in the row, for this row; no prefetch on hover, which would read the row.
        $done = $crawler->filter('[data-notifications] tbody tr')->first()->filter('[data-row-actions] form')->first();
        self::assertSame('/notifications/'.$this->notificationId('Fix the form', 'owner').'/done', $done->attr('action'));
        self::assertSame('Done', $done->filter('button')->attr('aria-label'));
        self::assertSame('false', $crawler->filter('[data-notifications]')->attr('data-turbo-prefetch'));
        // The header reads them all; there are two unread, and the sidebar says so in its main group.
        self::assertSame('/notifications/read', $crawler->filter('[data-notifications-read-all]')->attr('action'));
        self::assertSame('2', trim($crawler->filter('#sidebar [data-nav-group="main"] a[href="/notifications"] [data-nav-count]')->text()));
        self::assertSame('page', $crawler->filter('#sidebar a[href="/notifications"]')->attr('aria-current'));
        self::assertSame(['/', '/notifications'], $crawler->filter('#sidebar [data-nav-group="main"] li > a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertSame(['New', 'Done'], $crawler->filter('main nav[aria-label="Notification views"] a')->each(static fn (Crawler $a): string => trim($a->text())));
        self::assertCount(0, $crawler->filter('[data-notifications-kept]'));
        self::assertCount(0, $crawler->filter('main nav[aria-label="Pagination"]'));
    }

    public function testTheDoneTabListsTheDoneRowsAndSaysHowLongTheyAreKept(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/notifications?tab=done');

        self::assertResponseIsSuccessful();
        self::assertSame(['Old news'], $this->titles($crawler));
        self::assertStringContainsString('Jana Example: Shared the task with you', $crawler->filter('[data-notifications] tbody tr')->text());
        self::assertSame('Done notifications are kept for 3 months, then deleted.', trim($crawler->filter('[data-notifications-kept]')->text()));
        $back = $crawler->filter('[data-notifications] [data-row-actions] form')->first();
        self::assertSame('/notifications/'.$this->notificationId('Old news', 'owner').'/undone?tab=done', $back->attr('action'));
        self::assertSame('Back to new', $back->filter('button')->attr('aria-label'));
        self::assertSame('done', $crawler->filter('[data-notifications-filter] input[name="tab"]')->attr('value'));
        self::assertSame('/notifications/read?tab=done', $crawler->filter('[data-notifications-read-all]')->attr('action'));
    }

    public function testTheFilterNarrowsToMentionsOrToAssignments(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/notifications');
        self::assertSame(['All', 'Mentions', 'Assigned'], $crawler->filter('#filter-kind option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertCount(0, $crawler->filter('[data-notifications-filter] a'));
        $crawler = $this->client->submit($crawler->filter('[data-notifications-filter]')->form(['filter' => 'mentions']));
        self::assertSame(['Fix the form'], $this->titles($crawler));
        self::assertSame('mentions', $crawler->filter('#filter-kind option[selected]')->attr('value'));
        self::assertSame('/notifications', $crawler->filter('[data-notifications-filter] a')->attr('href'));
        self::assertSame('/notifications?tab=done&filter=mentions', $crawler->filter('main nav a')->reduce(static fn (Crawler $a): bool => 'Done' === trim($a->text()))->attr('href'));

        $crawler = $this->client->request('GET', '/notifications?filter=assigned');
        self::assertSame(['Call the client'], $this->titles($crawler));

        $crawler = $this->client->request('GET', '/notifications?tab=done&filter=assigned');
        self::assertSame('Nothing done yet', $this->emptyTitle($crawler));
        self::assertSame('Nothing here with this filter.', $this->emptyText($crawler));
        self::assertSame('/notifications?tab=done', $crawler->filter('[data-notifications-filter] a')->attr('href'));

        $crawler = $this->client->request('GET', '/notifications?filter=nonsense');
        self::assertSame(['Fix the form', 'Call the client', 'Write the copy'], $this->titles($crawler));
    }

    public function testMarkingAllReadClearsTheCountAndKeepsTheView(): void
    {
        $this->seed();
        $crawler = $this->client->request('GET', '/notifications?filter=mentions');

        $this->client->submit($crawler->filter('[data-notifications-read-all]')->form());

        self::assertResponseRedirects('/notifications?filter=mentions');
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

        self::assertResponseRedirects('/notifications?filter=mentions');
        self::assertNotNull($this->field('Fix the form', 'owner', 'done_at'));
        // Done reads it too.
        self::assertNotNull($this->field('Fix the form', 'owner', 'read_at'));
        $crawler = $this->client->followRedirect();
        self::assertSame('No new notifications', $this->emptyTitle($crawler));
        self::assertSame('1', trim($crawler->filter('#sidebar a[href="/notifications"] [data-nav-count]')->text()));

        $crawler = $this->client->request('GET', '/notifications?tab=done');
        self::assertSame(['Fix the form', 'Old news'], $this->titles($crawler));
        $this->client->submit($crawler->filter('[data-notifications] [data-row-actions] form')->first()->form());

        self::assertResponseRedirects('/notifications?tab=done');
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
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: Role::Member);
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
        self::assertResponseRedirects('/notifications?filter=mentions');
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
        self::assertStringContainsString('Example Company: Shared the task with you', $crawler->filter('[data-notifications] tbody tr')->text());
        self::assertSame(['/shared', '/notifications'], $crawler->filter('#sidebar [data-nav-group="main"] li > a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
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
        [$this->tenant] = $this->browser->seed(['Example Organisation']);
        $this->browser->signIn();
        $jana = new User('jana@example.com', 'not a hash', 'Jana Example');
        $marek = new User('marek@example.com', 'not a hash', 'Marek Member');
        $guest = new User(self::GUEST, password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Guest Example');
        foreach ([$jana, $marek, $guest] as $user) {
            $this->em->persist($user);
        }
        $this->em->flush();
        $this->browser->fixtures->join($jana, $this->tenant, Role::Member);
        $this->browser->fixtures->join($marek, $this->tenant, Role::Member);
        $this->browser->fixtures->join($guest, $this->tenant, Role::Guest);
        $this->context->switchTo($this->tenant);
        try {
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
        } finally {
            $this->context->reset();
        }
    }

    private function membership(string $email): Membership
    {
        $id = $this->em->getConnection()->fetchOne('SELECT m.id FROM membership m JOIN app_user u ON u.id = m.user_id WHERE u.email = ?', [$email]);
        self::assertIsString($id);

        return $this->em->find(Membership::class, $id) ?? throw new \LogicException('Membership.');
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
