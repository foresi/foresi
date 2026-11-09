<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Controller;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Tenancy\Application\TenantClock;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Ui\Dialog\DialogRequest;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use App\Work\Domain\WorkShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The pages of time: a person's week with its days and totals, the dialog that
 * picks the project and the task, an owner reading anyone's week; the report
 * with its filters, totals and CSV, holding only the time the person sees; and
 * neither for a guest.
 */
final class TimePagesControllerTest extends WebTestCase
{
    private const string GUEST = 'guest@example.com';
    private const string JANA = 'jana@example.com';

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

    public function testTheWeekShowsEachDayWithItsEntriesAndTotals(): void
    {
        $this->seed();
        $today = $this->today();
        $this->logged('Wireframes', 90, $today, note: 'Sketches');
        $this->logged(null, 30, $today);
        $this->logged('Brief', 45, $today->modify('-7 days'));

        $crawler = $this->client->request('GET', '/time');
        self::assertResponseIsSuccessful();
        self::assertSame('/time', $crawler->filter('#sidebar [data-nav-group="work"] li > a[href="/time"]')->attr('href'));
        self::assertSame('page', $crawler->filter('#sidebar a[href="/time"]')->attr('aria-current'));
        self::assertCount(7, $crawler->filter('[data-time-day]'));
        $day = $crawler->filter('[data-time-day="'.$today->format('Y-m-d').'"]');
        self::assertSame('2 h', trim($day->filter('[data-time-day-total]')->text()));
        self::assertCount(1, $day->filter('[data-time-today]'));
        self::assertSame('2 h', trim($crawler->filter('[data-time-week-total]')->text()));
        $entries = $day->filter('[data-time-entry]');
        self::assertCount(2, $entries);
        self::assertSame(['Wireframes', 'Website'], $entries->filter('[data-time-link]')->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertSame('/tasks/'.$this->taskId('Wireframes'), $entries->filter('[data-time-link]')->first()->attr('href'));
        self::assertSame('/projects/'.$this->projectId('Website'), $entries->filter('[data-time-link]')->last()->attr('href'));
        self::assertSame('Sketches', trim($entries->first()->filter('[data-time-note]')->text()));
        // Edited in the dialog and deleted from the line, both coming back to this week.
        self::assertStringEndsWith('/edit?return=/time', urldecode((string) $entries->first()->filter('a[data-time-edit]')->attr('href')));
        self::assertSame('/time', $entries->first()->filter('form[data-time-delete] input[name="return"]')->attr('value'));

        $monday = $today->setISODate((int) $today->format('o'), (int) $today->format('W'));
        $previous = '/time?week='.$monday->modify('-7 days')->format('Y-m-d');
        self::assertSame($previous, $crawler->filter('a[data-time-week-previous]')->attr('href'));
        self::assertSame('/time?week='.$monday->modify('+7 days')->format('Y-m-d'), $crawler->filter('a[data-time-week-next]')->attr('href'));
        self::assertSame('date', $crawler->filter('a[data-time-week-current]')->attr('aria-current'));
        $log = $crawler->filter('a[data-time-new-open]');
        self::assertSame('/time/new?day='.$today->format('Y-m-d').'&return=/time', urldecode((string) $log->attr('href')));

        $crawler = $this->client->request('GET', $previous);
        self::assertSame('45 min', trim($crawler->filter('[data-time-week-total]')->text()));
        self::assertSame('false', $crawler->filter('a[data-time-week-current]')->attr('aria-current'));
        self::assertSame('/time', $crawler->filter('a[data-time-week-current]')->attr('href'));
        // A week that is not this one logs on its Monday by default.
        self::assertSame('/time/new?day='.$monday->modify('-7 days')->format('Y-m-d').'&return='.$previous, urldecode((string) $crawler->filter('a[data-time-new-open]')->attr('href')));

        // Deleting from the week comes back to it.
        $entry = $this->entryId(30);
        $crawler = $this->client->request('GET', '/time');
        $this->client->submit($crawler->filter('#time-'.$entry.' form[data-time-delete]')->form());
        self::assertResponseRedirects('/time');
    }

    public function testTheDialogPicksTheProjectAndTheTaskAndStartsTheTimer(): void
    {
        $this->seed();
        $website = $this->projectId('Website');

        $crawler = $this->client->request('GET', '/time/new?return=/time', server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame('', $crawler->filter('#time-project option[selected]')->attr('value'));
        self::assertSame(['Choose a project', 'Secret', 'Website'], $crawler->filter('#time-project option')->each(static fn (Crawler $option): string => $option->text()));
        self::assertCount(0, $crawler->filter('#time-task'));
        self::assertCount(1, $crawler->filter('button[data-time-timer-start]'));

        // Nothing chosen is told; a project chosen shows its tasks and its billable default.
        $form = $crawler->filter('#time-entry-form')->form(['duration' => '1:00']);
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose a project.', $crawler->filter('#time-project-error')->text());
        $crawler = $this->client->submit($crawler->filter('#time-entry-form')->form(['project' => $website, 'duration' => '1:00']));
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM time_entry', []));
        self::assertSame(['No task, the project itself', 'Brief', 'Wireframes'], $crawler->filter('#time-task option')->each(static fn (Crawler $option): string => $option->text()));
        self::assertSame('1:00', $crawler->filter('#time-duration')->attr('value'));

        $crawler = $this->client->submit($crawler->filter('#time-entry-form')->form(['task' => $this->taskId('Wireframes')]));
        self::assertResponseRedirects('/time');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM time_entry WHERE task_id = ? AND minutes = 60', [$this->taskId('Wireframes')]));

        // The timer on the project itself, without a task: no duration needed.
        $crawler = $this->client->request('GET', '/time/new?project='.$website.'&return=/time');
        $this->client->submit($crawler->filter('button[data-time-timer-start]')->first()->form(['task' => '', 'note' => 'Planning']));
        self::assertResponseRedirects('/time');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM time_entry WHERE project_id = ? AND task_id IS NULL AND ended_at IS NULL AND note = ?', [$website, 'Planning']));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The timer is running.');
        self::assertSame('Website', trim($crawler->filter('[data-running-timer-link]')->text()));

        // A task of another project is not taken.
        $crawler = $this->client->request('GET', '/time/new?project='.$website);
        $form = $crawler->filter('#time-entry-form')->form(['duration' => '0:30']);
        $form->disableValidation();
        $form->setValues(['task' => '0190a0a0-0000-7000-8000-000000000000']);
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose a task, or none.', $crawler->filter('#time-task-error')->text());
    }

    public function testAnOwnerReadsTheWeekOfAnyoneAndAMemberOnlyTheirOwn(): void
    {
        $this->seed();
        $today = $this->today();
        $this->logged('Brief', 60, $today, self::JANA);
        $this->logged('Brief', 15, $today);
        $jana = $this->membershipId(self::JANA);
        $owner = $this->membershipId(IdentityFixtures::EMAIL);

        $crawler = $this->client->request('GET', '/time');
        self::assertSame(['Me', 'Jana Example'], $crawler->filter('#filter-person option')->each(static fn (Crawler $option): string => $option->text()));
        $crawler = $this->client->submit($crawler->filter('form[data-time-person-filter]')->form(['person' => $jana]));
        self::assertSame('1 h', trim($crawler->filter('[data-time-week-total]')->text()));
        self::assertCount(0, $crawler->filter('a[data-time-new-open]'));
        // The owner changes the entries of anyone, and comes back to their week.
        self::assertStringContainsString('person='.$jana, urldecode((string) $crawler->filter('a[data-time-edit]')->attr('href')));

        $this->signInAs(self::JANA);
        $crawler = $this->client->request('GET', '/time?person='.$owner);
        self::assertResponseIsSuccessful();
        self::assertSame('1 h', trim($crawler->filter('[data-time-week-total]')->text()));
        self::assertCount(0, $crawler->filter('form[data-time-person-filter]'));
    }

    public function testTheReportFiltersAndGroupsTheTime(): void
    {
        $this->seed();
        $today = $this->today();
        $this->logged('Wireframes', 90, $today, billable: true);
        $this->logged(null, 30, $today);
        $this->logged('Brief', 60, $today, self::JANA, billable: true);
        $this->logged(null, 45, $today, project: 'Secret', billable: true);
        $this->logged('Brief', 120, $today->modify('first day of last month'));
        $this->invoiced($this->entryId(90));

        $crawler = $this->client->request('GET', '/time/report');
        self::assertResponseIsSuccessful();
        self::assertSame('this_month', $crawler->filter('#report-period option[selected]')->attr('value'));
        self::assertSame($today->modify('first day of this month')->format('Y-m-d'), $crawler->filter('#report-from')->attr('value'));
        self::assertSame(['3 h 45 min', '3 h 15 min', '30 min', '4'], $this->kpis($crawler));
        self::assertSame([['Website', '3 h'], ['Secret', '45 min']], $this->totals($crawler));
        self::assertSame('3 h 45 min', trim($crawler->filter('[data-time-report-totals] tfoot td')->last()->text()));
        self::assertCount(4, $crawler->filter('[data-time-report-entries] tbody tr'));

        $crawler = $this->client->request('GET', '/time/report?group=person');
        self::assertSame([['Example Company', '2 h 45 min'], ['Jana Example', '1 h']], $this->totals($crawler));
        $crawler = $this->client->request('GET', '/time/report?group=task');
        self::assertSame([['Wireframes', '1 h 30 min'], ['Brief', '1 h'], ['No task, the project itself', '45 min'], ['No task, the project itself', '30 min']], $this->totals($crawler));

        $filters = [
            'billable=no' => ['30 min'],
            'billable=yes' => ['3 h 15 min'],
            'invoiced=yes' => ['1 h 30 min'],
            'invoiced=no' => ['2 h 15 min'],
            'person='.$this->membershipId(self::JANA) => ['1 h'],
            'project='.$this->projectId('Secret') => ['45 min'],
            'client='.$this->clientId() => ['45 min'],
            'period=last_month' => ['2 h'],
        ];
        foreach ($filters as $query => [$total]) {
            $crawler = $this->client->request('GET', '/time/report?'.$query);
            self::assertSame($total, $this->kpis($crawler)[0], $query);
        }

        // Dates changed under the period they were shown for make a custom one; their order does not matter.
        $last = $today->modify('first day of last month');
        $crawler = $this->client->request('GET', '/time/report');
        $crawler = $this->client->submit($crawler->filter('form[data-time-report-filter]')->form(['from' => $today->format('Y-m-d'), 'until' => $last->format('Y-m-d')]));
        self::assertSame('custom', $crawler->filter('#report-period option[selected]')->attr('value'));
        self::assertSame('5 h 45 min', $this->kpis($crawler)[0]);
        // Another period chosen with the dates left as they were shown takes that period.
        $crawler = $this->client->request('GET', '/time/report');
        $crawler = $this->client->submit($crawler->filter('form[data-time-report-filter]')->form(['period' => 'last_month']));
        self::assertSame('2 h', $this->kpis($crawler)[0]);

        $crawler = $this->client->request('GET', '/time/report?period=custom&from=2020-01-01&until=2020-01-31');
        self::assertSelectorTextContains('[data-time-report-empty]', 'No time in this period');
        self::assertCount(0, $crawler->filter('form[data-time-report-export]'));
    }

    public function testTheCsvHoldsOnlyTheTimeTheMemberSees(): void
    {
        $this->seed();
        $today = $this->today();
        $this->logged('Wireframes', 90, $today, billable: true, note: 'Sketches');
        $this->logged(null, 25, $today, self::JANA, note: '=SUM(A1)');
        $this->logged(null, 45, $today, project: 'Secret', billable: true);
        $this->signInAs(self::JANA);

        $crawler = $this->client->request('GET', '/time/report');
        self::assertSame([['Website', '1 h 55 min']], $this->totals($crawler));
        self::assertSame(['', $this->projectId('Website')], $crawler->filter('#report-project option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        $this->client->request('GET', '/time/report?project='.$this->projectId('Secret'));
        self::assertSame('1 h 55 min', $this->kpis($this->client->getCrawler())[0]);

        $this->client->submit($crawler->filter('form[data-time-report-export]')->form());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=utf-8');
        $month = $today->modify('first day of this month');
        self::assertStringContainsString('attachment; filename=time.csv; filename*=utf-8\'\'time-report_'.$month->format('Y-m-d').'_'.$month->modify('last day of this month')->format('Y-m-d').'.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $csv = $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}", $csv);
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3))), static fn (string $line): bool => '' !== $line));
        self::assertSame([
            'Date;Person;Client;Project;Task;Minutes;Hours;Billable;Note',
            $today->format('Y-m-d').';"Example Company";;Website;Wireframes;90;1.50;yes;Sketches',
            $today->format('Y-m-d').';"Jana Example";;Website;;25;0.42;no;\'=SUM(A1)',
        ], $this->sortedRows($lines));

        // The download keeps the filter.
        $crawler = $this->client->request('GET', '/time/report?billable=no');
        $this->client->submit($crawler->filter('form[data-time-report-export]')->form());
        self::assertCount(2, explode("\n", trim($this->client->getInternalResponse()->getContent())));
    }

    public function testAGuestHasNeitherTheWeekNorTheReport(): void
    {
        $this->seed();
        $this->signInAs(self::GUEST);

        $crawler = $this->client->request('GET', '/my-tasks');
        self::assertCount(0, $crawler->filter('#sidebar a[href="/time"]'));
        foreach (['GET' => ['/time', '/time/report', '/time/new'], 'POST' => ['/time/report/export']] as $method => $urls) {
            foreach ($urls as $url) {
                $this->client->request($method, $url);
                self::assertResponseStatusCodeSame(403, $method.' '.$url);
            }
        }
    }

    public function testThePhoneHeaderOfATaskMovesItsActionsIntoTheMenu(): void
    {
        $this->seed();
        $id = $this->taskId('Wireframes');

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $actions = $crawler->filter('[data-page-actions]')->children();
        // Two boxes on a phone, the timer and the menu, on one row; the rest is in the menu.
        self::assertCount(4, $actions);
        self::assertSame(['hidden sm:contents', 'form', 'hidden sm:contents', 'relative'], $actions->each(static fn (Crawler $child): string => 'form' === $child->nodeName() ? 'form' : (string) $child->attr('class')));
        $menu = $crawler->filter('#task-menu');
        self::assertSame('/tasks/'.$id.'?edit=title', $menu->filter('a[data-task-menu-edit]')->attr('href'));
        self::assertSame('/tasks/'.$id.'/subtasks/new', $menu->filter('a[data-task-menu-subtask]')->attr('href'));
        self::assertNotNull($menu->filter('a[data-task-menu-share]')->attr('data-dialog'));
        self::assertStringContainsString('sm:hidden', (string) $menu->filter('a[data-task-menu-edit]')->attr('class'));
    }

    /**
     * Website, internal and open to the organisation, with Brief and Wireframes;
     * Secret for a client and open only to whom it is shared with, no one but the
     * owner. Jana Example is a colleague and a guest has Website shared. The
     * owner is signed in.
     */
    private function seed(): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation']);
        $this->browser->signIn();
        foreach (['Website', 'Secret'] as $name) {
            $crawler = $this->client->request('GET', '/projects/new');
            $this->client->submit($crawler->filter('#project-form')->form(['name' => $name]));
        }
        $jana = new User(self::JANA, password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Jana Example');
        $guest = new User(self::GUEST, password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Guest Example');
        $this->em->persist($jana);
        $this->em->persist($guest);
        $this->em->flush();
        $this->browser->fixtures->join($jana, $this->tenant, Role::Member);
        $this->browser->fixtures->join($guest, $this->tenant, Role::Guest);
        $this->scoped(function (): void {
            $website = $this->project('Website');
            [$open] = $this->em->getRepository(ProjectStatus::class)->findBy(['project' => $website], ['position' => 'ASC']);
            $owner = $this->membership(IdentityFixtures::EMAIL);
            $now = new \DateTimeImmutable();
            $this->em->persist(new Task($website, 'Brief', $open, 0, $owner->user()->id(), $now));
            $this->em->persist(new Task($website, 'Wireframes', $open, 1, $owner->user()->id(), $now));
            $this->em->persist(WorkShare::of($website, $this->membership(self::GUEST), $now));
            $client = new Party(PartyKind::Person, 'Example Client', null, null, '');
            $this->em->persist($client);
            $secret = $this->project('Secret');
            $secret->assignClient($client);
            $secret->changeVisibility(ProjectVisibility::Chosen);
            $this->em->flush();
        });
    }

    /**
     * An entry on the task of Website, or on the project without one.
     */
    private function logged(?string $task, int $minutes, \DateTimeImmutable $day, string $email = IdentityFixtures::EMAIL, bool $billable = false, string $note = '', string $project = 'Website'): void
    {
        $this->scoped(function () use ($task, $minutes, $day, $email, $billable, $note, $project): void {
            $onTask = null === $task ? null : $this->em->find(Task::class, $this->taskId($task));
            $person = $this->membership($email);
            $this->em->persist(TimeEntry::logged($onTask?->project() ?? $this->project($project), $onTask, $person, $day, $minutes, null, null, $note, $billable, $person->user()->id(), new \DateTimeImmutable()));
            $this->em->flush();
        });
    }

    private function invoiced(string $entryId): void
    {
        $this->em->getConnection()->executeStatement('UPDATE time_entry SET invoice_line_id = ? WHERE id = ?', ['0190a0a0-0000-7000-8000-000000000001', $entryId]);
    }

    /**
     * @return list<string>
     */
    private function kpis(Crawler $crawler): array
    {
        return $crawler->filter('[data-time-report] [data-kpi] .num')->each(static fn (Crawler $value): string => trim($value->text()));
    }

    /**
     * The name and the time of each line of the totals.
     *
     * @return list<array{string, string}>
     */
    private function totals(Crawler $crawler): array
    {
        return $crawler->filter('[data-time-report-totals] tbody tr')->each(static function (Crawler $row): array {
            $cells = $row->filter('td');

            $secondary = $cells->first()->filter('[data-row-secondary]');
            $name = trim($cells->first()->text());

            return [$secondary->count() > 0 ? trim(substr($name, 0, -\strlen(trim($secondary->text())))) : $name, trim($cells->last()->text())];
        });
    }

    /**
     * The header first, then the lines by their text.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function sortedRows(array $lines): array
    {
        $header = array_shift($lines);
        sort($lines);

        return [(string) $header, ...$lines];
    }

    private function today(): \DateTimeImmutable
    {
        return $this->scoped(fn (): \DateTimeImmutable => self::getContainer()->get(TenantClock::class)->today()->setTime(0, 0));
    }

    private function project(string $name): Project
    {
        return $this->em->getRepository(Project::class)->findOneBy(['name' => $name]) ?? throw new \LogicException('Project.');
    }

    private function projectId(string $name): string
    {
        return $this->fetchId('SELECT id FROM project WHERE name = ?', [$name]);
    }

    private function clientId(): string
    {
        return $this->fetchId('SELECT client_id FROM project WHERE name = ?', ['Secret']);
    }

    private function entryId(int $minutes): string
    {
        return $this->fetchId('SELECT id FROM time_entry WHERE minutes = ?', [(string) $minutes]);
    }

    private function signInAs(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => $email, '_password' => IdentityFixtures::PASSWORD]));
    }

    private function membership(string $email): Membership
    {
        return $this->em->getRepository(Membership::class)->findOneBy(['user' => $this->fetchId('SELECT id FROM app_user WHERE email = ?', [$email])]) ?? throw new \LogicException('Membership.');
    }

    private function membershipId(string $email): string
    {
        return $this->scoped(fn (): string => $this->membership($email)->id());
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
