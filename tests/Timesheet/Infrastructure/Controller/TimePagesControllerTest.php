<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Contacts\ContactsBundle;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Timesheet\Domain\TimeEntry;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\WorkShare;
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

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->browser = new SignedInBrowser($this->client, $this->em);
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
        self::assertSame('/time', $crawler->filter('#sidebar [data-nav-area="projects"] [data-nav-section] li > a[href="/time"]')->attr('href'));
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
        self::assertSame(['Me', 'Jana Example'], $crawler->filter('[data-filter-sheet-form] select[name=person] option')->each(static fn (Crawler $option): string => $option->text()));
        $crawler = $this->client->submit($crawler->filter('[data-time-person-filter] [data-filter-sheet-form]')->form(['person' => $jana]));
        self::assertSame('1 h', trim($crawler->filter('[data-time-week-total]')->text()));
        self::assertCount(0, $crawler->filter('a[data-time-new-open]'));
        // The owner changes the entries of anyone, and comes back to their week.
        self::assertStringContainsString('person='.$jana, urldecode((string) $crawler->filter('a[data-time-edit]')->attr('href')));

        $this->signInAs(self::JANA);
        $crawler = $this->client->request('GET', '/time?person='.$owner);
        self::assertResponseIsSuccessful();
        self::assertSame('1 h', trim($crawler->filter('[data-time-week-total]')->text()));
        self::assertCount(0, $crawler->filter('[data-time-person-filter]'));
    }

    public function testTheReportOpensOnThisMonthAndGroupsTheTime(): void
    {
        $this->seed();
        $today = $this->today();
        $this->logged('Wireframes', 90, $today, billable: true);
        $this->logged(null, 30, $today);
        $this->logged('Brief', 60, $today, self::JANA, billable: true);
        $this->logged(null, 45, $today, billable: true, project: 'Secret');
        $this->logged('Brief', 120, $today->modify('first day of last month'));
        $this->invoiced($this->entryId(90));

        $crawler = $this->client->request('GET', '/time/report');
        self::assertResponseIsSuccessful();
        // Saved views, D12: All first (D7), then the periods the report offered; This month is where it opens.
        self::assertSame(['All', 'This month', 'Last month'], $this->reportTabs($crawler));
        self::assertSame('This month', trim($crawler->filter('nav[aria-label="Report views"] [aria-current="page"]')->text()));
        self::assertCount(1, $crawler->filter('[data-filter-sheet-form]'));
        self::assertCount(1, $crawler->filter('[data-column-picker-form]'));
        self::assertCount(0, $crawler->filter('[data-filter-sheet-form] select[name=period]'), 'no fixed select is left (D11)');
        self::assertStringContainsString($today->modify('first day of this month')->format('Y-m-d'), $crawler->filter('#report-range')->text());
        self::assertSame(['3 h 45 min', '3 h 15 min', '30 min', '4'], $this->kpis($crawler));
        self::assertSame([['Website', '3 h'], ['Secret', '45 min']], $this->totals($crawler));
        self::assertSame('3 h 45 min', trim($crawler->filter('[data-time-report-totals] tfoot td')->last()->text()));
        self::assertCount(4, $crawler->filter('[data-time-report-entries] tbody tr'));

        $crawler = $this->client->request('GET', '/time/report?view=last_month');
        self::assertSame('2 h', $this->kpis($crawler)[0]);
        $crawler = $this->client->request('GET', '/time/report?view=all');
        self::assertSame('5 h 45 min', $this->kpis($crawler)[0]);
        self::assertSame('All time', trim($crawler->filter('#report-range')->text()));
        // Reset of All names no view, only the view's own display.
        self::assertSame('5 h 45 min', $this->kpis($this->client->request('GET', '/time/report?widths='))[0]);

        $crawler = $this->client->request('GET', '/time/report?group=person');
        self::assertSame([['Example Company', '2 h 45 min'], ['Jana Example', '1 h']], $this->totals($crawler));
        self::assertSame('This month, unsaved changes', $this->reportTabs($crawler)[1], 'the grouping is the view\'s display');
        $crawler = $this->client->request('GET', '/time/report');
        self::assertSame([['Example Company', '2 h 45 min'], ['Jana Example', '1 h']], $this->totals($crawler), 'the grouping last used on the view comes back (D-display-3)');
        $crawler = $this->client->request('GET', '/time/report?view=this_month&group=task');
        self::assertSame([['Wireframes', '1 h 30 min'], ['Brief', '1 h'], ['No task, the project itself', '45 min'], ['No task, the project itself', '30 min']], $this->totals($crawler));
        self::assertSame('This month', $this->reportTabs($this->client->request('GET', '/time/report?group=project'))[1], 'the default grouping is no change');

        $crawler = $this->client->request('GET', '/time/report?view=this_month&f=nonsense');
        self::assertSame('3 h 45 min', $this->kpis($crawler)[0], 'a filter the report cannot read shows the view');
    }

    public function testTheGroupingIsSavedWithTheView(): void
    {
        $this->seed();
        $today = $this->today();
        $this->logged('Wireframes', 90, $today);
        $this->logged('Brief', 60, $today, self::JANA);

        $crawler = $this->client->request('GET', '/time/report?view=this_month&group=person');
        $this->client->submit($crawler->filter('form[action="/views/save"]')->form());
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->request('GET', '/time/report');
        self::assertSame([['Example Company', '1 h 30 min'], ['Jana Example', '1 h']], $this->totals($crawler));
        self::assertCount(0, $crawler->filter('form[action="/views/save"]'), 'nothing is left unsaved');

        $crawler = $this->client->request('GET', '/time/report?view=all&group=task');
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'By task']));
        $crawler = $this->client->followRedirect();
        self::assertContains('By task', $this->reportTabs($crawler));
        self::assertSame([['Wireframes', '1 h 30 min'], ['Brief', '1 h']], $this->totals($crawler));
    }

    public function testTheBuilderNarrowsTheReport(): void
    {
        $this->seed();
        $today = $this->today();
        $this->logged('Wireframes', 90, $today, billable: true);
        $this->logged(null, 30, $today);
        $this->logged('Brief', 60, $today, self::JANA, billable: true);
        $this->logged(null, 45, $today, billable: true, project: 'Secret');
        $this->logged('Brief', 120, $today->modify('first day of last month'));
        $this->invoiced($this->entryId(90));

        // The rows of the sheet are the whole working filter, so on All they hold no day.
        $total = function (array $conditions, string $view = 'all'): string {
            $this->client->request('GET', '/time/report?'.http_build_query(['view' => $view, 'conditions' => '1', 'c' => $conditions]));
            self::assertResponseStatusCodeSame(303);

            return $this->kpis($this->client->followRedirect())[0];
        };

        self::assertSame('30 min', $total([['field' => 'billable', 'op' => 'is', 'value' => '0'], ['field' => 'worked_on', 'op' => 'is', 'from' => ['rule' => 'this_month']]], 'this_month'));
        self::assertSame('2 h 30 min', $total([['field' => 'billable', 'op' => 'is', 'value' => '0']]));
        self::assertSame('1 h 30 min', $total([['field' => 'invoiced', 'op' => 'is', 'value' => '1']]));
        self::assertSame('4 h 15 min', $total([['field' => 'invoiced', 'op' => 'is', 'value' => '0']]));
        self::assertSame('1 h', $total([['field' => 'person', 'op' => 'is', 'value' => $this->membershipId(self::JANA)]]));
        self::assertSame('45 min', $total([['field' => 'project', 'op' => 'is', 'value' => $this->projectId('Secret')]]));
        self::assertSame('45 min', $total([['field' => 'client', 'op' => 'is', 'value' => $this->clientId()]]));
        self::assertSame('5 h', $total([['field' => 'client', 'op' => 'is_empty']]));
        self::assertSame('0 min', $total([['field' => 'project', 'op' => 'is', 'value' => 'not-an-id']]), 'a reference that is no id matches nothing');
        self::assertSame('2 h', $total([['field' => 'worked_on', 'op' => 'before', 'from' => ['rule' => 'this_month']]], 'all'));

        $crawler = $this->client->getCrawler();
        self::assertSame('All, unsaved changes', $this->reportTabs($crawler)[0]);
        self::assertSame('All', trim($crawler->filter('#report-range')->text()), 'a day that is no period shows the view');

        // The address book names a contact; without it the chip says the client is unknown.
        if (class_exists(ContactsBundle::class)) {
            $this->client->request('GET', '/time/report?'.http_build_query(['view' => 'this_month', 'conditions' => '1', 'c' => [['field' => 'client', 'op' => 'is', 'value' => $this->clientId()]]]));
            $crawler = $this->client->followRedirect();
            self::assertStringContainsString('Example Client', $crawler->filter('main')->text(), 'the chip names the client');
        }
        $this->client->request('GET', '/time/report?'.http_build_query(['view' => 'this_month', 'conditions' => '1', 'c' => [['field' => 'person', 'op' => 'is', 'value' => $this->membershipId(self::JANA)], ['field' => 'project', 'op' => 'is', 'value' => $this->projectId('Secret')]]]));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Jana Example', $crawler->filter('main')->text(), 'the chip names the person');
        self::assertStringContainsString('Secret', $crawler->filter('main')->text(), 'the chip names the project');

        self::assertSame([['id' => $this->projectId('Secret'), 'label' => 'Secret']], $this->picked('/time/report/projects?q=SEC'));
        self::assertSame([['id' => $this->membershipId(self::JANA), 'label' => 'Jana Example']], $this->picked('/time/report/people?q=jana'));
    }

    public function testTheOldAddressesOpenTheSameTime(): void
    {
        $this->seed();
        $today = $this->today();
        $this->logged('Wireframes', 90, $today, billable: true);
        $this->logged(null, 30, $today);
        $this->logged('Brief', 60, $today, self::JANA, billable: true);
        $this->logged(null, 45, $today, billable: true, project: 'Secret');
        $this->logged('Brief', 120, $today->modify('first day of last month'));
        $this->invoiced($this->entryId(90));
        $last = $today->modify('first day of last month');

        $addresses = [
            'period=this_month' => '3 h 45 min',
            'period=last_month' => '2 h',
            'billable=no' => '30 min',
            'billable=yes' => '3 h 15 min',
            'invoiced=yes' => '1 h 30 min',
            'invoiced=no' => '2 h 15 min',
            'person='.$this->membershipId(self::JANA) => '1 h',
            'project='.$this->projectId('Secret') => '45 min',
            'client='.$this->clientId() => '45 min',
            // A custom period's days in either order.
            'period=custom&from='.$today->format('Y-m-d').'&until='.$last->format('Y-m-d') => '5 h 45 min',
            'period=custom&from=2020-01-01' => '3 h 45 min',
            'period=last_month&group=task&shown=last_month' => '2 h',
        ];
        foreach ($addresses as $query => $total) {
            $this->client->request('GET', '/time/report?'.$query);
            self::assertResponseStatusCodeSame(303, $query);
            self::assertSame($total, $this->kpis($this->client->followRedirect())[0], $query);
        }
        self::assertStringContainsString('group=task', $this->client->getRequest()->getUri(), 'the grouping goes along');
        self::assertSame('/time/report?view=last_month', $this->redirectOf('period=last_month'));

        $crawler = $this->client->request('GET', '/time/report?period=custom&from=2020-01-01&until=2020-01-31');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-time-report-empty]', 'No time in this period');
        self::assertStringContainsString('2020-01-01', $crawler->filter('#report-range')->text());
        self::assertCount(0, $crawler->filter('form[data-time-report-export]'));
    }

    public function testTheCsvHoldsOnlyTheTimeTheMemberSees(): void
    {
        $this->seed();
        $today = $this->today();
        $this->logged('Wireframes', 90, $today, billable: true, note: 'Sketches');
        $this->logged(null, 25, $today, self::JANA, note: '=SUM(A1)');
        $this->logged(null, 45, $today, billable: true, project: 'Secret');
        $this->signInAs(self::JANA);

        $crawler = $this->client->request('GET', '/time/report');
        self::assertSame([['Website', '1 h 55 min']], $this->totals($crawler));
        self::assertSame([['id' => $this->projectId('Website'), 'label' => 'Website']], $this->picked('/time/report/projects'));
        $this->client->request('GET', '/time/report?project='.$this->projectId('Secret'));
        self::assertSame('0 min', $this->kpis($this->client->followRedirect())[0], 'a project the member does not see holds no time');

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

        // The download keeps the view and the filter.
        $this->client->request('GET', '/time/report?billable=no');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[data-time-report-export]')->form());
        self::assertCount(2, explode("\n", trim($this->client->getInternalResponse()->getContent())));
        $crawler = $this->client->request('GET', '/time/report?view=all');
        $this->client->submit($crawler->filter('form[data-time-report-export]')->form());
        self::assertStringContainsString("filename*=utf-8''time-report.csv", (string) $this->client->getResponse()->headers->get('Content-Disposition'), 'all the time names no days');
    }

    public function testAGuestHasNeitherTheWeekNorTheReport(): void
    {
        $this->seed();
        $this->signInAs(self::GUEST);

        $crawler = $this->client->request('GET', '/my-tasks');
        self::assertCount(0, $crawler->filter('#sidebar a[href="/time"]'));
        foreach (['GET' => ['/time', '/time/report', '/time/report/projects', '/time/report/people', '/time/new'], 'POST' => ['/time/report/export']] as $method => $urls) {
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
        // One box-less wrapper holds the timer, the buttons and the menu, so a phone keeps the timer and the menu on one row; the timer, the main action, comes first and the menu last.
        self::assertCount(1, $crawler->filter('[data-page-actions]')->children());
        $actions = $crawler->filter('[data-page-actions] > [data-more-actions]')->children();
        self::assertSame(['form', 'hidden sm:contents', 'menu'], $actions->each(static fn (Crawler $child): string => 'form' === $child->nodeName() ? 'form' : (null !== $child->attr('data-more-actions-menu') ? 'menu' : (string) $child->attr('class'))));
        $menu = $crawler->filter('#task-menu');
        // The title is changed where it is read, so the header has no Edit of its own.
        self::assertCount(0, $menu->filter('[data-task-menu-edit]'));
        self::assertSame('/tasks/'.$id.'/subtasks/new', $menu->filter('[data-more-actions-phone] a[data-task-menu-subtask]')->attr('href'));
        self::assertSame('hidden sm:contents', $crawler->filter('[data-more-actions-buttons]')->attr('class'));
        // Sharing is in the menu on every screen.
        self::assertNotNull($menu->filter('a[data-task-share]')->attr('data-dialog'));
        self::assertCount(0, $menu->filter('[data-more-actions-phone] a[data-task-share]'));
    }

    /**
     * Website, internal and open to the organisation, with Brief and Wireframes;
     * Secret for a client and open only to whom it is shared with, no one but the
     * owner. Jana Example is a colleague and a guest has Website shared. The
     * owner is signed in.
     */
    private function seed(): void
    {
        $this->browser->seed();
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
        $this->browser->fixtures->join($jana, Role::Member);
        $this->browser->fixtures->join($guest, Role::Guest);
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
    private function reportTabs(Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Report views"] a')->each(static fn (Crawler $tab): string => trim($tab->text()));
    }

    /**
     * What a picker of the builder offers.
     */
    private function picked(string $url): mixed
    {
        $this->client->request('GET', $url);
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($answer);

        return $answer['results'] ?? null;
    }

    private function redirectOf(string $query): string
    {
        $this->client->request('GET', '/time/report?'.$query);

        return (string) $this->client->getResponse()->headers->get('Location');
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
        return $this->scoped(fn (): \DateTimeImmutable => self::getContainer()->get(InstanceClock::class)->today()->setTime(0, 0));
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
        return $work();
    }
}
