<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Controller;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Tenancy\Application\TenantClock;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Ui\Dialog\DialogRequest;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use App\Work\Domain\WorkShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

/**
 * Time on the task page: the block with the total against the estimate, the
 * entry dialog, editing and deleting by the author or an owner, the timer in
 * the header and in the bar over every page; and none of it for a guest.
 */
final class TimeControllerTest extends WebTestCase
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

    public function testTimeIsLoggedInTheDialogAndShownWithItsTotal(): void
    {
        $this->seed();
        $id = $this->taskId('Wireframes');

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame('0 min', trim($crawler->filter('[data-task-time-total]')->text()));
        self::assertSelectorTextContains('[data-task-time]', 'No time logged yet.');
        $open = $crawler->filter('[data-task-time] a[data-time-new-open]');
        self::assertSame('/tasks/'.$id.'/time/new', $open->attr('href'));
        self::assertNotNull($open->attr('data-dialog'));

        // The dialog: today, nothing typed, billable as the project says (an internal one does not bill).
        $crawler = $this->client->request('GET', '/tasks/'.$id.'/time/new', server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#dialog-page', 'Log time');
        self::assertSame($this->today(), $crawler->filter('#time-worked_on')->attr('value'));
        self::assertSame('', $crawler->filter('#time-duration')->attr('value'));
        self::assertNull($crawler->filter('#time-billable')->attr('checked'));

        // A duration it cannot read is told next to its field, which keeps what was typed.
        $crawler = $this->client->request('GET', '/tasks/'.$id.'/time/new');
        $crawler = $this->client->submit($crawler->filter('#time-entry-form')->form(['duration' => 'a while', 'note' => 'Sketches']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter the time as 1:30', trim($crawler->filter('#time-duration-error')->text()));
        self::assertSame('a while', $crawler->filter('#time-duration')->attr('value'));
        self::assertSame('Sketches', $crawler->filter('#time-note')->text());

        $this->client->submit($crawler->filter('#time-entry-form')->form(['duration' => '1h 30m', 'note' => 'Sketches', 'billable' => '1']));
        self::assertResponseRedirects('/tasks/'.$id.'#task-time-title');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', '1 h 30 min logged.');
        self::assertSame('1 h 30 min', trim($crawler->filter('[data-task-time-total]')->text()));
        $entry = $crawler->filter('[data-time-entry]');
        self::assertCount(1, $entry);
        self::assertSame('1 h 30 min', trim($entry->filter('[data-time-duration]')->text()));
        self::assertSame('Example Company', trim($entry->filter('[data-time-person]')->text()));
        self::assertSame('Sketches', trim($entry->filter('[data-time-note]')->text()));
        self::assertCount(1, $entry->filter('[data-time-billable]'));
        self::assertSame(['minutes' => 90, 'billable' => true, 'worked_on' => $this->today()], $this->entryRow());
    }

    public function testTheEstimateIsSetInThePanelAndShownAgainstTheTotal(): void
    {
        $this->seed();
        $id = $this->taskId('Wireframes');
        $this->logged($id, 200);

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $this->client->submit($crawler->filter('[data-task-property="estimate"]')->form(['estimate' => '5 h']));
        self::assertResponseRedirects('/tasks/'.$id);
        $crawler = $this->client->followRedirect();
        self::assertSame('3 h 20 min of 5 h', trim($crawler->filter('[data-task-time-total]')->text()));
        self::assertSame('5 h', $crawler->filter('#task-estimate')->attr('value'));

        $this->client->submit($crawler->filter('[data-task-property="estimate"]')->form(['estimate' => 'soon']));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Enter an estimate of at least a minute');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM task WHERE id = ? AND estimate_minutes = 300', [$id]));

        $this->client->submit($crawler->filter('[data-task-property="estimate"] button[name="clear"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame('3 h 20 min', trim($crawler->filter('[data-task-time-total]')->text()));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT estimate_minutes FROM task WHERE id = ?', [$id]));
    }

    public function testTheAuthorEditsAndDeletesAnEntryInTheDialog(): void
    {
        $this->seed();
        $id = $this->taskId('Wireframes');
        $entry = $this->logged($id, 90);

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $edit = $crawler->filter('#time-'.$entry.' a[data-time-edit]');
        self::assertSame('/time/'.$entry.'/edit', $edit->attr('href'));
        self::assertNotNull($edit->attr('data-dialog'));
        self::assertSame('Delete the logged time?', $crawler->filter('#time-'.$entry.' form[data-time-delete]')->attr('data-confirm-title'));

        $crawler = $this->client->request('GET', '/time/'.$entry.'/edit');
        self::assertSame('1 h 30 min', $crawler->filter('#time-duration')->attr('value'));
        $this->client->submit($crawler->filter('#time-entry-form')->form(['duration' => '2:15', 'worked_on' => '2026-11-02', 'note' => 'Second pass']));
        self::assertResponseRedirects('/tasks/'.$id.'#task-time-title');
        self::assertSame(['minutes' => 135, 'billable' => false, 'worked_on' => '2026-11-02'], $this->entryRow());

        $crawler = $this->client->request('GET', '/time/'.$entry.'/edit');
        $crawler = $this->client->submit($crawler->filter('#time-entry-form')->form(['worked_on' => 'yesterday']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter the day as a date.', trim($crawler->filter('#time-worked_on-error')->text()));

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $this->client->submit($crawler->filter('#time-'.$entry.' form[data-time-delete]')->form());
        self::assertResponseRedirects('/tasks/'.$id.'#task-time-title');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The time is deleted.');
        self::assertCount(0, $crawler->filter('[data-time-entry]'));
    }

    public function testAColleagueCannotChangeTheTimeOfAnotherButAnOwnerCan(): void
    {
        $this->seed();
        $id = $this->taskId('Wireframes');
        $janas = $this->logged($id, 60, 'jana@example.com');

        // The owner changes anyone's.
        $crawler = $this->client->request('GET', '/tasks/'.$id);
        self::assertCount(1, $crawler->filter('#time-'.$janas.' a[data-time-edit]'));

        $this->signInAs('jana@example.com');
        $mine = $this->logged($id, 30, 'jana@example.com');
        $owners = $this->logged($id, 45);
        $crawler = $this->client->request('GET', '/tasks/'.$id);
        self::assertCount(1, $crawler->filter('#time-'.$mine.' a[data-time-edit]'));
        self::assertCount(0, $crawler->filter('#time-'.$owners.' a[data-time-edit]'));
        self::assertCount(0, $crawler->filter('#time-'.$owners.' form[data-time-delete]'));
        $token = (string) $crawler->filter('#time-'.$mine.' form[data-time-delete] input[name="_token"]')->attr('value');

        $this->client->request('GET', '/time/'.$owners.'/edit');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/time/'.$owners.'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM time_entry WHERE id = ?', [$owners]));
    }

    public function testTheTimerRunsFromTheHeaderAndStopsFromTheBarOverEveryPage(): void
    {
        $project = $this->seed();
        $wireframes = $this->taskId('Wireframes');
        $brief = $this->taskId('Brief');

        $crawler = $this->client->request('GET', '/tasks/'.$wireframes);
        self::assertCount(0, $crawler->filter('[data-running-timer]'));
        $start = $crawler->filter('main form[data-task-timer="start"]');
        self::assertSame('Start timer', trim($start->text()));
        $this->client->submit($start->form());
        self::assertResponseRedirects('/tasks/'.$wireframes);
        $crawler = $this->client->followRedirect();
        self::assertSame('Stop timer', trim($crawler->filter('main form[data-task-timer="stop"]')->text()));
        $bar = $crawler->filter('[data-running-timer]');
        self::assertSame('Wireframes', trim($bar->filter('[data-running-timer-link]')->text()));
        self::assertSame('/tasks/'.$wireframes, $bar->filter('[data-running-timer-link]')->attr('href'));
        self::assertSame('elapsed', $bar->filter('[data-running-timer-elapsed]')->attr('data-controller'));
        self::assertMatchesRegularExpression('/^\d+:\d{2}:\d{2}$/', trim($bar->filter('[data-running-timer-elapsed]')->text()));
        self::assertSelectorTextContains('[data-time-entry]', 'Running');

        // Starting it on another task saves the running one.
        $crawler = $this->client->request('GET', '/tasks/'.$brief);
        $this->client->submit($crawler->filter('main form[data-task-timer="start"]')->form());
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM time_entry WHERE task_id = ? AND minutes IS NOT NULL AND ended_at IS NOT NULL', [$wireframes]));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM time_entry WHERE task_id = ? AND ended_at IS NULL', [$brief]));

        // The bar shows on any page and stops the timer back where it was stopped.
        $crawler = $this->client->request('GET', '/projects/'.$project);
        $bar = $crawler->filter('[data-running-timer]');
        self::assertSame('Brief', trim($bar->filter('[data-running-timer-link]')->text()));
        $this->client->submit($bar->filter('form[data-running-timer-stop]')->form());
        self::assertResponseRedirects('/projects/'.$project);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The timer is stopped, 1 min logged on “Brief”.');
        self::assertCount(0, $crawler->filter('[data-running-timer]'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM time_entry WHERE ended_at IS NULL', []));

        // An address elsewhere is not followed.
        $this->client->submit($this->startForm($brief));
        $this->client->followRedirect();
        $token = (string) $this->client->getCrawler()->filter('form[data-running-timer-stop] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/timer/stop', ['_token' => $token, 'return' => '//evil.example.com/']);
        self::assertResponseRedirects('/tasks/'.$brief);
    }

    public function testTimeWithoutATaskIsLoggedFromTheHeaderOfTheProject(): void
    {
        $project = $this->seed();

        foreach (['/projects/'.$project, '/projects/'.$project.'/board'] as $page) {
            $crawler = $this->client->request('GET', $page);
            self::assertSame('/projects/'.$project.'/time/new', $crawler->filter('main a[data-project-time-new]')->attr('href'), $page);
        }
        $crawler = $this->client->request('GET', '/projects/'.$project.'/time/new');
        $this->client->submit($crawler->filter('#time-entry-form')->form(['duration' => '0:45']));
        self::assertResponseRedirects('/projects/'.$project);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM time_entry WHERE project_id = ? AND task_id IS NULL AND minutes = 45', [$project]));
    }

    public function testAGuestSeesNoTimeAndIsDeniedItsScreens(): void
    {
        $project = $this->seed();
        $id = $this->taskId('Wireframes');
        $this->logged($id, 60);
        $this->signInAs(self::GUEST);

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-task-time]'));
        self::assertCount(0, $crawler->filter('[data-task-timer]'));
        self::assertCount(0, $crawler->filter('[data-task-property="estimate"]'));
        $crawler = $this->client->request('GET', '/projects/'.$project);
        self::assertCount(0, $crawler->filter('[data-project-time-new]'));

        foreach (['GET' => ['/tasks/'.$id.'/time/new', '/projects/'.$project.'/time/new'], 'POST' => ['/tasks/'.$id.'/timer', '/timer/stop', '/tasks/'.$id.'/estimate']] as $method => $urls) {
            foreach ($urls as $url) {
                $this->client->request($method, $url);
                self::assertResponseStatusCodeSame(403, $method.' '.$url);
            }
        }
    }

    /**
     * Website, internal, with Brief and Wireframes; Jana Example as a colleague
     * and a guest the project is shared with. The owner is signed in.
     */
    private function seed(): string
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation']);
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website']));
        $id = $this->fetchId('SELECT id FROM project WHERE name = ?', ['Website']);
        $jana = new User('jana@example.com', password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Jana Example');
        $guest = new User(self::GUEST, password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Guest Example');
        $this->em->persist($jana);
        $this->em->persist($guest);
        $this->em->flush();
        $this->browser->fixtures->join($jana, $this->tenant, Role::Member);
        $this->browser->fixtures->join($guest, $this->tenant, Role::Guest);
        $this->scoped(function () use ($id): void {
            $project = $this->em->find(Project::class, $id) ?? throw new \LogicException('Project.');
            [$open] = $this->em->getRepository(ProjectStatus::class)->findBy(['project' => $project], ['position' => 'ASC']);
            $owner = $this->membership(IdentityFixtures::EMAIL);
            $now = new \DateTimeImmutable();
            $this->em->persist(new Task($project, 'Brief', $open, 0, $owner->user()->id(), $now));
            $this->em->persist(new Task($project, 'Wireframes', $open, 1, $owner->user()->id(), $now));
            $this->em->persist(WorkShare::of($project, $this->membership(self::GUEST), $now));
            $this->em->flush();
        });

        return $id;
    }

    /**
     * An entry of today on the task, by the owner unless said otherwise.
     */
    private function logged(string $taskId, int $minutes, string $email = IdentityFixtures::EMAIL): string
    {
        return $this->scoped(function () use ($taskId, $minutes, $email): string {
            $task = $this->em->find(Task::class, $taskId) ?? throw new \LogicException('Task.');
            $person = $this->membership($email);
            $entry = TimeEntry::logged($task->project(), $task, $person, new \DateTimeImmutable('today'), $minutes, null, null, '', false, $person->user()->id(), new \DateTimeImmutable());
            $this->em->persist($entry);
            $this->em->flush();

            return $entry->id();
        });
    }

    private function startForm(string $taskId): Form
    {
        return $this->client->request('GET', '/tasks/'.$taskId)->filter('main form[data-task-timer="start"]')->form();
    }

    private function signInAs(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => $email, '_password' => IdentityFixtures::PASSWORD]));
    }

    /**
     * @return array{minutes: int, billable: bool, worked_on: string}
     */
    private function entryRow(): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT minutes, billable, worked_on FROM time_entry');
        self::assertIsArray($row);
        self::assertIsNumeric($row['minutes']);
        self::assertIsString($row['worked_on']);

        return ['minutes' => (int) $row['minutes'], 'billable' => (bool) $row['billable'], 'worked_on' => $row['worked_on']];
    }

    private function today(): string
    {
        return $this->scoped(fn (): string => self::getContainer()->get(TenantClock::class)->today()->format('Y-m-d'));
    }

    private function membership(string $email): Membership
    {
        return $this->em->getRepository(Membership::class)->findOneBy(['user' => $this->fetchId('SELECT id FROM app_user WHERE email = ?', [$email])]) ?? throw new \LogicException('Membership.');
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
