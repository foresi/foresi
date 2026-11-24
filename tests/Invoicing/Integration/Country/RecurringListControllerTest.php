<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\IssueRecurringInvoices;
use Foresi\Invoicing\Application\IssueRecurringTemplate;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\RecurringAmounts;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInput;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringLifecycle;
use Foresi\Invoicing\Application\RecurringOverview;
use Foresi\Invoicing\Application\SaveRecurringInvoice;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\RecurringDelivery;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Invoicing\Domain\TaxPointRule;
use Foresi\Invoicing\Infrastructure\View\RecurringRecordCounter;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RecurringListControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1'];
    private const array ACTIONS = ['pause', 'resume', 'end', 'delete', 'issue_now'];

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private LegalEntity $firm;
    private Series $series;
    private Party $customer;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheListShowsNextIssueAndInterval(): void
    {
        $this->signedIn();
        $hosting = $this->template(description: 'Hosting {month/year}', name: 'Example hosting');
        $this->template(recurrence: $this->recurrence(unit: RecurrenceUnit::Year), description: 'Domain');
        $this->template(recurrence: $this->recurrence(every: 12, end: RecurrenceEnd::AfterCount, endAfter: 3), description: 'Licence', delivery: RecurringDelivery::Review);

        $crawler = $this->client->request('GET', '/invoices/recurring');

        self::assertResponseIsSuccessful();
        self::assertSame(['All 3', 'Active 3', 'Paused 0', 'Ended 0', 'Failing 0', 'Trash 0'], $this->tabs($crawler));
        self::assertSame(['Template name', 'Customer', 'Description', 'Repeats', 'Next issue', 'After issue', 'Amount'], $this->headers($crawler));
        $rows = $this->rowTexts($crawler);
        self::assertCount(3, $rows);
        $first = $crawler->filter('tbody tr')->eq(0)->filter('td')->eq(0)->filter('a');
        self::assertSame('Example hosting', trim($first->text()), 'the name leads the row');
        self::assertSame('/invoices/recurring/'.$hosting->id(), $first->attr('href'), 'and links to the detail');
        self::assertStringContainsString('Domain', $crawler->filter('tbody tr')->eq(1)->filter('td')->eq(0)->text(), 'one without a name is named after its description');
        self::assertStringContainsString('Example Customer', $rows[0], 'the customer follows');
        self::assertStringContainsString('Hosting 12/2026', $rows[0], 'the description as the next invoice has it');
        self::assertStringContainsString('Monthly', $rows[0]);
        self::assertStringContainsString('2026-12-01', $rows[0]);
        self::assertStringContainsString('Monthly · no end', $rows[0]);
        self::assertStringContainsString('Send', $rows[0]);
        self::assertStringContainsString($this->nbsp('1,210.00 Kč'), $rows[0]);
        self::assertStringContainsString('Yearly', $rows[1]);
        self::assertStringContainsString('Every 12 months', $rows[2]);
        self::assertStringContainsString('Every 12 months · 3 more', $rows[2]);
        self::assertStringContainsString('Review', $rows[2]);
    }

    public function testTheTabsAreTheShippedViewsAndTheOldAddressesAlias(): void
    {
        // Saved views, D12: the tabs it had, with the same counts (D9), after All (D7).
        $this->signedIn();
        $this->template(description: 'Running');
        $paused = $this->template(description: 'Paused one');
        $ended = $this->template(description: 'Ended one');
        $failing = $this->template(description: 'Failing one');
        $this->scoped(function () use ($paused, $ended): void {
            self::getContainer()->get(RecurringLifecycle::class)->pause($paused->id());
            self::getContainer()->get(RecurringLifecycle::class)->end($ended->id());
        });
        $this->failed($failing, 'invoice.problem.customer_archived');

        $crawler = $this->client->request('GET', '/invoices/recurring');
        self::assertSame(['All 4', 'Active 2', 'Paused 1', 'Ended 1', 'Failing 1', 'Trash 0'], $this->tabs($crawler));
        self::assertSelectorCount(4, 'main tbody tr');
        self::assertSame('/invoices/recurring?view=paused', $crawler->filter('nav[aria-label="Recurring invoice lists"] a')->eq(2)->attr('href'));
        self::assertCount(1, $crawler->filter('[data-filter-sheet-form]'));

        foreach (['tab=paused' => 'Paused one', 'view=paused' => 'Paused one', 'tab=ended' => 'Ended one', 'view=failing' => 'Failing one'] as $address => $shown) {
            $crawler = $this->client->request('GET', '/invoices/recurring?'.$address);
            self::assertSame([$shown], array_map(static fn (string $row): string => str_contains($row, $shown) ? $shown : $row, $this->rowTexts($crawler)), $address);
        }
        $crawler = $this->client->request('GET', '/invoices/recurring?view=active');
        self::assertCount(2, $crawler->filter('tbody tr'), 'a failing template is active too, as before');
        // An unknown view is the whole list.
        $this->client->request('GET', '/invoices/recurring?view=friends');
        self::assertSelectorCount(4, 'main tbody tr');
    }

    public function testTheBuilderNarrowsTheViewAndItsChangesAreSavedAsANewView(): void
    {
        $this->signedIn();
        $this->template(description: 'Sent one');
        $this->template(description: 'Reviewed one', delivery: RecurringDelivery::Review);

        $this->client->request('GET', '/invoices/recurring?'.http_build_query(['view' => 'active', 'conditions' => '1', 'c' => [
            ['field' => 'state', 'op' => 'is', 'values' => ['active']],
            ['field' => 'delivery', 'op' => 'is', 'values' => ['review']],
        ]]));
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Reviewed one', $crawler->filter('tbody tr')->text());
        self::assertSame('Active, unsaved changes 2', $this->tabs($crawler)[1]);
        self::assertSame(['All 2', 'Active, unsaved changes 2', 'Paused 0', 'Ended 0', 'Failing 0', 'Trash 0'], $this->tabs($crawler), 'the tabs count their own views');
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'For review']));
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('tbody tr'));
        // Six tabs are taken, the trash the last of them, so the new view stays off them, as on the invoice list:
        // the Settings sheet lists it unchecked, and while it is open it shows as a tab.
        self::assertSame('For review', $this->tabs($crawler)[6] ?? null);
        self::assertCount(1, $crawler->filter('[data-settings-view-list] [data-settings-view][data-label="For review"] input[type="checkbox"]:not([checked])'));
        self::assertNotContains('For review', $this->tabs($this->client->request('GET', '/invoices/recurring')));
    }

    public function testTheBuilderFieldsNarrowTheList(): void
    {
        $this->signedIn();
        $this->template(description: 'Monthly one', name: 'Example monthly');
        $this->template(recurrence: $this->recurrence(first: '2027-03-01'), description: 'Later one', name: 'Example later');
        $failing = $this->template(description: 'Failing one', name: 'Broken');
        $this->failed($failing, 'invoice.problem.customer_archived');

        $shown = function (array $conditions): array {
            $this->client->request('GET', '/invoices/recurring?'.http_build_query(['conditions' => '1', 'c' => $conditions]));
            $crawler = $this->client->followRedirect();

            return $crawler->filter('tbody tr')->each(static fn (Crawler $row): string => trim($row->filter('td a')->first()->text()));
        };

        self::assertSame(['Example monthly', 'Example later'], $shown([['field' => 'failing', 'op' => 'is', 'value' => '0']]));
        self::assertSame(['Broken'], $shown([['field' => 'failing', 'op' => 'is', 'value' => '1']]));
        self::assertSame(['Example later'], $shown([['field' => 'next_occurrence', 'op' => 'after', 'from' => ['rule' => FilterRows::DAY, 'day' => '2027-01-01']]]));
        self::assertSame(['Example monthly', 'Example later'], $shown([['field' => 'name', 'op' => 'contains', 'value' => 'example']]));
        self::assertSame(['Broken'], $shown([['field' => 'description', 'op' => 'contains', 'value' => 'failing']]));
        self::assertSame(['Example monthly', 'Broken', 'Example later'], $shown([['field' => 'state', 'op' => 'is_not', 'values' => ['paused']]]));
    }

    public function testTheListSortsByName(): void
    {
        $this->signedIn();
        $this->template(description: 'B', name: 'Beta');
        $this->template(recurrence: $this->recurrence(first: '2027-03-01'), description: 'A', name: 'Alpha');

        $names = fn (string $query): array => $this->client->request('GET', '/invoices/recurring'.$query)->filter('tbody tr td:first-child a')->each(static fn (Crawler $name): string => trim($name->text()));

        self::assertSame(['Beta', 'Alpha'], $names(''), 'by the next issue');
        self::assertSame(['Alpha', 'Beta'], $names('?sort=name'));
        self::assertSame(['Beta', 'Alpha'], $names('?sort=-name'));
        self::assertSame(['Alpha', 'Beta'], $names('?sort=-next'));
        self::assertSame(['Beta', 'Alpha'], $names('?sort=amount'), 'an unknown sort is the list\'s own');
    }

    public function testTheColumnsSheetHidesAColumnAndTheFailureMovesUnderTheName(): void
    {
        $this->signedIn();
        $failing = $this->template(description: 'Failing one');
        $this->failed($failing, 'invoice.problem.customer_archived');

        $crawler = $this->client->request('GET', '/invoices/recurring?view=failing&cols=name,customer,amount');

        self::assertSame(['Template name', 'Customer', 'Amount'], $this->headers($crawler));
        self::assertCount(1, $crawler->filter('[data-column-picker-form]'));
        self::assertStringContainsString('Failed', $crawler->filter('tbody tr td')->eq(0)->text(), 'the failure stays in sight');
    }

    public function testTheRecordCounterCountsAViewAsTheListDoes(): void
    {
        $this->signedIn();
        $this->template(description: 'Running');
        $paused = $this->template(description: 'Paused one');
        $this->scoped(fn () => self::getContainer()->get(RecurringLifecycle::class)->pause($paused->id()));
        $counter = self::getContainer()->get(RecurringRecordCounter::class);

        self::assertSame(['invoicing.recurring'], $counter->lists());
        self::assertSame('invoices:read', $counter->scope());
        self::assertSame(2, $counter->count('invoicing.recurring', new ViewFilter()));
        self::assertSame(1, $counter->count('invoicing.recurring', ViewFilter::all(new Condition('state', 'is', 'paused'))));
        $this->expectException(\InvalidArgumentException::class);
        $counter->count('party.contacts', new ViewFilter());
    }

    public function testTheListTakesNoQueryPerRow(): void
    {
        $this->signedIn();
        $this->template();
        $one = $this->queriesOfAPage();
        $this->template(customer: $this->customerNamed('Example Second a.s.'));
        $this->template(customer: $this->customerNamed('Example Third a.s.'));

        self::assertSame($one, $this->queriesOfAPage());
        self::assertLessThanOrEqual(6, $one, 'count, page, customers, lines, firm settings and firm');
    }

    public function testFiltersByTabAndIgnoresAnOldFirmFilter(): void
    {
        $this->signedIn();
        $this->template(description: 'Main');
        $paused = $this->template(description: 'Paused one');
        $this->scoped(fn () => self::getContainer()->get(RecurringLifecycle::class)->pause($paused->id()));

        $crawler = $this->client->request('GET', '/invoices/recurring');
        self::assertSame(['All 2', 'Active 1', 'Paused 1', 'Ended 0', 'Failing 0', 'Trash 0'], $this->tabs($crawler));
        self::assertNotContains('Firm', $this->headers($crawler), 'one firm per instance (ADR 0039)');

        // An instance holds one firm (ADR 0039): no firm select, and an old ?firm= narrows nothing.
        $crawler = $this->client->request('GET', '/invoices/recurring?firm='.$this->firm->id());
        self::assertSame(['All 2', 'Active 1', 'Paused 1', 'Ended 0', 'Failing 0', 'Trash 0'], $this->tabs($crawler));
        self::assertCount(0, $crawler->filter('[data-filter-chip="firm"], select[name="firm"]'));

        $crawler = $this->client->request('GET', '/invoices/recurring?tab=paused');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Paused one', $crawler->filter('tbody tr')->text());
        self::assertSame(['Edit', 'Resume', 'End', 'Delete'], $this->menu($crawler->filter('tbody tr')));
    }

    public function testTheDetailShowsTheTemplateReadOnly(): void
    {
        $this->signedIn();
        $template = $this->template(description: 'Hosting {month/year}', name: 'Example hosting');

        $crawler = $this->client->request('GET', '/invoices/recurring/'.$template->id());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Example hosting');
        $text = $crawler->filter('[data-recurring-detail]')->text();
        self::assertStringContainsString('Example Customer', $text);
        self::assertStringContainsString('Monthly · no end', $text);
        self::assertStringContainsString('2026-12-01', $text, 'the next issue');
        self::assertStringContainsString('Hosting 12/2026', $text);
        self::assertStringContainsString($this->nbsp('1,210.00 Kč'), $crawler->filter('[data-recurring-totals]')->text(), 'the total the next invoice comes to');
        self::assertCount(1, $crawler->filter('[data-recurring-lines] tbody tr'));
        $edit = $crawler->filter('a[data-recurring-edit]');
        self::assertSame('/invoices/recurring/'.$template->id().'/edit', $edit->attr('href'));
        self::assertInstanceOf(\DOMAttr::class, $edit->getNode(0)?->attributes?->getNamedItem('data-in-place'), 'Edit loads the form in place');
        self::assertSame(['Issue now', 'Pause', 'End', 'Delete'], array_map(trim(...), $crawler->filter('main [data-more-actions-menu] [role="menuitem"]')->each(static fn (Crawler $item): string => $item->text())));
    }

    public function testThePausedDetailOffersResume(): void
    {
        $this->signedIn();
        $template = $this->template();
        $this->scoped(fn () => self::getContainer()->get(RecurringLifecycle::class)->pause($template->id()));

        $crawler = $this->client->request('GET', '/invoices/recurring/'.$template->id());

        self::assertSame(['Resume', 'End', 'Delete'], array_map(trim(...), $crawler->filter('main [data-more-actions-menu] [role="menuitem"]')->each(static fn (Crawler $item): string => $item->text())));
    }

    public function testTheDetailIsStaffOnly(): void
    {
        $this->signedIn(Role::Guest);
        $template = $this->template();

        $this->client->request('GET', '/invoices/recurring/'.$template->id());

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheFormInTheSheetCancelsToTheDetailAndSavesBackToIt(): void
    {
        $this->signedIn();
        $template = $this->template();

        $crawler = $this->client->request('GET', '/invoices/recurring/'.$template->id().'/edit', server: ['HTTP_X_FORESI_DIALOG' => 'sheet']);

        self::assertResponseIsSuccessful();
        $cancel = $crawler->filter('#recurring-cancel');
        self::assertSame('/invoices/recurring/'.$template->id(), $cancel->attr('href'));
    }

    public function testTheMenuOffersWhatTheStateAllows(): void
    {
        $this->signedIn();
        $this->template();

        $crawler = $this->client->request('GET', '/invoices/recurring');

        self::assertSame(['Edit', 'Issue now', 'Pause', 'End', 'Delete'], $this->menu($crawler->filter('tbody tr')));
    }

    public function testPauseStoresPaused(): void
    {
        $this->signedIn();
        $template = $this->template();

        $crawler = $this->client->request('GET', '/invoices/recurring/'.$template->id().'/pause', server: self::DIALOG);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2026-12-01', $crawler->filter('dialog')->text(), 'the dialog names the date it stops');
        $this->post($template, 'pause', ['return' => 'tab=active']);

        self::assertResponseIsSuccessful();
        self::assertSame(['location' => '/invoices/recurring?tab=active'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $row = $this->templateRow($template->id());
        self::assertSame('paused', $row['state']);
        self::assertNotNull($row['paused_at']);
        $crawler = $this->client->request('GET', '/invoices/recurring?tab=paused');
        self::assertStringContainsString('is paused', $this->flashes($crawler));
    }

    public function testResumeNamesSkippedDates(): void
    {
        $this->signedIn();
        $template = $this->template();
        $this->scoped(fn () => self::getContainer()->get(RecurringLifecycle::class)->pause($template->id()));
        Clock::set(new MockClock('2027-01-20 12:00:00', 'UTC'));

        $crawler = $this->client->request('GET', '/invoices/recurring/'.$template->id().'/resume', server: self::DIALOG);

        self::assertResponseIsSuccessful();
        $dialog = $crawler->filter('dialog')->text();
        self::assertStringContainsString('2026-12-01', $dialog);
        self::assertStringContainsString('2027-01-01', $dialog);
        self::assertStringContainsString('2027-02-01', $dialog, 'and the date it goes on with');
        self::assertSame('paused', $this->templateRow($template->id())['state'], 'the dialog changes nothing');

        $this->post($template, 'resume');

        $row = $this->templateRow($template->id());
        self::assertSame('active', $row['state']);
        self::assertSame('2027-02-01', $row['next_occurrence']);
        $crawler = $this->client->request('GET', '/invoices/recurring');
        $status = $this->flashes($crawler);
        self::assertStringContainsString('2027-02-01', $status);
        self::assertStringContainsString('2026-12-01, 2027-01-01', $status);
    }

    public function testResumingAfterTodaysRunIssuesADueTemplateAtOnce(): void
    {
        $this->signedIn();
        $template = $this->template();
        $this->scoped(fn () => self::getContainer()->get(RecurringLifecycle::class)->pause($template->id()));
        // Resumed on the day of an occurrence, after the day's run.
        Clock::set(new MockClock('2027-01-01 12:00:00', 'UTC'));
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO scheduled_job_run (id, job, last_period, finished_at) VALUES (?, ?, ?, ?)',
            ['019a0000-0000-7000-8000-0000000000ac', IssueRecurringInvoices::NAME, '2027-01-01', '2027-01-01 04:10:00'],
        );

        $this->post($template, 'resume');

        self::assertSame('2027-01-01', $this->templateRow($template->id())['next_occurrence']);
        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $sent = $queue->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(IssueRecurringTemplate::class, $message);
        self::assertSame($template->id(), $message->templateId);
    }

    public function testEndStoresEnded(): void
    {
        $this->signedIn();
        $template = $this->template();

        $this->post($template, 'end');

        $row = $this->templateRow($template->id());
        self::assertSame('ended', $row['state']);
        self::assertNull($row['next_occurrence']);
        $crawler = $this->client->request('GET', '/invoices/recurring?tab=ended');
        self::assertSame(['Edit', 'Delete'], $this->menu($crawler->filter('tbody tr')));
    }

    public function testDeleteMovesANeverIssuedTemplateToTheTrashAndRestoreBringsItBackPaused(): void
    {
        $this->signedIn();
        $template = $this->template(description: 'Hosting');
        $token = $this->token($template);
        $dialog = $this->client->request('GET', '/invoices/recurring/'.$template->id().'/delete', server: self::DIALOG)->text();
        self::assertStringContainsString('moves to the trash, where it can be restored for 30 days', $dialog);

        $this->client->request('POST', '/invoices/recurring/'.$template->id().'/delete', ['_token' => $token], server: self::DIALOG);

        self::assertSame(1, $this->rowsIn('recurring_invoice'), 'it stays, in the trash');
        $crawler = $this->client->request('GET', '/invoices/recurring');
        self::assertStringContainsString('is in the trash', $this->flashes($crawler));
        self::assertSame(['All 0', 'Active 0', 'Paused 0', 'Ended 0', 'Failing 0', 'Trash 1'], $this->tabs($crawler));
        self::assertSelectorCount(0, 'main tbody tr[data-row-id]');

        $crawler = $this->client->request('GET', '/invoices/recurring?view=trash');
        self::assertCount(1, $crawler->filter('tbody tr'));
        $headers = $crawler->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text()));
        self::assertContains('Deleted by', $headers);
        self::assertContains('Deleted', $headers);
        self::assertNotContains('Next issue', $headers);
        self::assertSame(['Restore'], $this->menu($crawler->filter('tbody tr')));

        // In the trash, an action is refused until it is restored.
        $this->client->request('GET', '/invoices/recurring/'.$template->id().'/resume');
        self::assertResponseRedirects('/invoices/recurring');
        $detail = $this->client->request('GET', '/invoices/recurring/'.$template->id());
        self::assertStringContainsString('in the trash and issues nothing', $detail->filter('main')->text());
        self::assertCount(1, $detail->filter('form[action="/invoices/recurring/'.$template->id().'/restore"]'));

        $this->client->request('POST', '/invoices/recurring/'.$template->id().'/restore', ['_token' => $token, 'return' => 'view=trash']);
        self::assertResponseRedirects('/invoices/recurring?view=trash');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The recurring invoice for Example Customer a.s. is restored. It stays paused', $this->flashes($crawler));
        self::assertEmpty($crawler->filter('tbody tr[data-row-id]'));
        self::assertStringContainsString('The trash is empty', $crawler->filter('main')->text());
        $row = $this->templateRow($template->id());
        self::assertNull($row['deleted_at']);
        self::assertSame('paused', $row['state']);
    }

    public function testDeleteIsRefusedAfterAnIssue(): void
    {
        $this->signedIn();
        $template = $this->template();
        $this->post($template, 'issue_now', ['occurrence' => '2026-12-01']);

        $crawler = $this->client->request('GET', '/invoices/recurring');
        self::assertNotContains('Delete', $this->menu($crawler->filter('tbody tr')));
        $this->post($template, 'delete');

        self::assertSame(1, $this->rowsIn('recurring_invoice'));
        $crawler = $this->client->request('GET', '/invoices/recurring');
        self::assertStringContainsString('can only be ended', $this->flashes($crawler));
    }

    public function testIssueNowStatesTheDatesAndIssuesOnceOnDoubleSubmit(): void
    {
        $this->signedIn();
        $template = $this->template(description: 'Hosting {month/year}');

        $crawler = $this->client->request('GET', '/invoices/recurring/'.$template->id().'/issue_now', server: self::DIALOG);
        self::assertResponseIsSuccessful();
        $dialog = $crawler->filter('dialog')->text();
        self::assertStringContainsString('2026-12-01', $dialog, 'the occurrence it issues');
        self::assertStringContainsString('2026-11-20', $dialog, 'issued today');
        self::assertStringContainsString('2026-12-04', $dialog, 'due in 14 days');
        self::assertStringContainsString('Hosting 12/2026', $dialog);

        $this->post($template, 'issue_now', ['occurrence' => '2026-12-01']);
        $this->post($template, 'issue_now', ['occurrence' => '2026-12-01']);

        $invoices = $this->scoped(fn (): array => $this->em()->getConnection()->fetchAllAssociative('SELECT description, recurring_occurrence, issue_date FROM invoice'));
        self::assertCount(1, $invoices);
        self::assertSame('Hosting 12/2026', $invoices[0]['description']);
        self::assertSame('2026-12-01', $invoices[0]['recurring_occurrence']);
        self::assertSame('2026-11-20', $invoices[0]['issue_date']);
        $row = $this->templateRow($template->id());
        self::assertEquals(1, $row['issued_count']);
        self::assertSame('2027-01-01', $row['next_occurrence']);
        $crawler = $this->client->request('GET', '/invoices/recurring');
        self::assertStringContainsString('already issued', $this->flashes($crawler));
    }

    public function testAFailingRowShowsTheReasonAndIssueNowClearsIt(): void
    {
        $this->signedIn();
        $template = $this->template(delivery: RecurringDelivery::Review);
        $this->failed($template, 'invoice.problem.customer_archived');

        $crawler = $this->client->request('GET', '/invoices/recurring?tab=failing');

        self::assertSame(['All 1', 'Active 1', 'Paused 0', 'Ended 0', 'Failing 1', 'Trash 0'], $this->tabs($crawler));
        $row = $crawler->filter('tbody tr');
        self::assertCount(1, $row);
        self::assertStringContainsString('Failed', $row->text());
        self::assertStringContainsString('This contact is archived.', $row->text());
        self::assertContains('Issue now', $this->menu($row));

        $this->post($template, 'issue_now', ['occurrence' => '2026-12-01']);

        self::assertNull($this->templateRow($template->id())['last_failure_code']);
        self::assertStringContainsString('waits under To send', $this->flashes($this->client->request('GET', '/invoices/recurring')));
    }

    public function testAFailureWithoutATextOfItsOwnSaysSoInGeneral(): void
    {
        $this->signedIn();
        $this->failed($this->template(), 'invoice.problem.something_new');

        $crawler = $this->client->request('GET', '/invoices/recurring?tab=failing');

        self::assertStringContainsString('The last run could not issue or send it.', $crawler->filter('tbody tr')->text());
    }

    public function testIssueNowSendsAndSaysWhenTheSendIsRefused(): void
    {
        $this->signedIn();
        $template = $this->template();

        $this->post($template, 'issue_now', ['occurrence' => '2026-12-01']);

        self::assertSame(1, $this->rowsIn('invoice'));
        self::assertSame(0, $this->rowsIn('invoice_send'), 'the customer has no address for invoices');
        self::assertStringContainsString('could not be sent: The customer has no e-mail address for invoices.', $this->flashes($this->client->request('GET', '/invoices/recurring')));
    }

    public function testToSendListsHeldInvoices(): void
    {
        $this->signedIn();
        $this->post($this->template(delivery: RecurringDelivery::Review), 'issue_now', ['occurrence' => '2026-12-01']);

        $crawler = $this->client->request('GET', '/invoices');
        $chip = $crawler->filter('[data-to-send] a');
        self::assertCount(1, $chip);
        self::assertSame('false', $chip->attr('aria-current'));
        self::assertStringContainsString('To send', $chip->text());
        self::assertStringContainsString('1', $chip->text());

        $crawler = $this->client->request('GET', '/invoices?toSend=1');
        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('[data-to-send] a')->attr('aria-current'));
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSame('/invoices', $crawler->filter('[data-to-send] a')->attr('href'), 'pressed again it shows all');
        self::assertCount(1, $crawler->filter('[data-filter-search] input[name="toSend"][value="1"]'), 'a search keeps the filter');
    }

    public function testTheToSendCountCountsTheWholeInstance(): void
    {
        $this->signedIn();
        $this->post($this->template(delivery: RecurringDelivery::Review), 'issue_now', ['occurrence' => '2026-12-01']);
        $this->post($this->template(delivery: RecurringDelivery::Review), 'issue_now', ['occurrence' => '2026-12-01']);
        $this->post($this->template(delivery: RecurringDelivery::Review), 'issue_now', ['occurrence' => '2026-12-01']);

        self::assertStringContainsString('3', $this->client->request('GET', '/invoices')->filter('[data-to-send] a')->text());
        $crawler = $this->client->request('GET', '/invoices?firm='.$this->firm->id().'&toSend=1');
        self::assertStringContainsString('3', $crawler->filter('[data-to-send] a')->text(), 'an old ?firm= narrows nothing');
        self::assertCount(3, $crawler->filter('tbody tr'), 'the count says what the list shows');
    }

    public function testIssueNowOnAPausedTemplateSaysItIsNotActive(): void
    {
        $this->signedIn();
        $template = $this->template();
        $token = $this->token($template);
        $this->scoped(fn () => self::getContainer()->get(RecurringLifecycle::class)->pause($template->id()));

        $this->client->request('POST', '/invoices/recurring/'.$template->id().'/issue_now', ['_token' => $token, 'occurrence' => '2026-12-01'], server: self::DIALOG);

        $flashes = $this->flashes($this->client->request('GET', '/invoices/recurring?tab=paused'));
        self::assertStringContainsString('The recurring invoice is not active, so nothing was issued.', $flashes);
        self::assertStringNotContainsString('already issued', $flashes);
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT id FROM invoice'), 'nothing was issued');
    }

    public function testAFailingRowKeepsItsDescriptionAndLinksWhereToFixIt(): void
    {
        $this->signedIn();
        $contact = $this->template(description: 'Hosting');
        $this->failed($contact, 'invoice.problem.customer_address_incomplete');
        $budget = $this->template(description: 'Support', delivery: RecurringDelivery::Review);
        $this->post($budget, 'issue_now', ['occurrence' => '2026-12-01']);
        $this->failed($budget, 'send.rate_limited');

        $crawler = $this->client->request('GET', '/invoices/recurring?tab=failing');

        $rows = $crawler->filter('tbody tr');
        self::assertCount(2, $rows);
        $first = $rows->eq(0);
        self::assertSame('Hosting', $first->filter('td')->eq(2)->filter('[title]')->attr('title'), 'the description stays');
        $alert = $first->filter('td')->eq(2)->filter('[data-row-alert]');
        self::assertStringContainsString('Failed: The customer needs a complete billing address.', $alert->text());
        self::assertSame('/contacts/'.$this->customer->id(), $alert->filter('a')->attr('href'), 'to the contact, where the address is fixed');
        self::assertCount(1, $first->filter('td')->eq(0)->filter('[data-row-alert-phone]'), 'a phone shows it as a line of its own');
        $second = $rows->eq(1);
        self::assertStringContainsString('Support', $second->text());
        self::assertSame('/invoices?toSend=1', $second->filter('[data-row-alert] a')->attr('href'), 'to the invoices waiting to be sent');
    }

    public function testAMissingMailServerLinksTheOwnerToTheMailSettings(): void
    {
        $this->signedIn();
        $template = $this->template(delivery: RecurringDelivery::Review);
        $this->post($template, 'issue_now', ['occurrence' => '2026-12-01']);
        $this->failed($template, 'send.no_server');

        $crawler = $this->client->request('GET', '/invoices/recurring?tab=failing');

        self::assertSame('/settings/firm/mail', $crawler->filter('tbody [data-row-alert] a')->attr('href'));
    }

    public function testEveryDialogWorksAsAPage(): void
    {
        $this->signedIn();
        $template = $this->template();
        $paused = $this->template();
        $this->scoped(fn () => self::getContainer()->get(RecurringLifecycle::class)->pause($paused->id()));

        foreach (self::ACTIONS as $action) {
            $id = 'resume' === $action ? $paused->id() : $template->id();
            $page = $this->client->request('GET', '/invoices/recurring/'.$id.'/'.$action);
            self::assertResponseIsSuccessful($action);
            self::assertCount(1, $page->filter('#sidebar'), $action.' is a page of its own');
            self::assertCount(1, $page->filter('main form#recurring-action'), $action);
            self::assertGreaterThan(0, $page->filter('main a[href="/invoices/recurring"]')->count(), $action.' cancels back to the list');

            $dialog = $this->client->request('GET', '/invoices/recurring/'.$id.'/'.$action, server: self::DIALOG);
            self::assertCount(0, $dialog->filter('#sidebar'), $action);
            self::assertCount(1, $dialog->filter('dialog form#recurring-action'), $action);
        }
    }

    public function testActionsNeedAToken(): void
    {
        $this->signedIn();
        $template = $this->template();

        $this->client->request('POST', '/invoices/recurring/'.$template->id().'/pause', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('active', $this->templateRow($template->id())['state']);
    }

    public function testStaffOnly(): void
    {
        $this->signedIn(Role::Guest);
        $template = $this->template();

        $this->client->request('GET', '/invoices/recurring');
        self::assertResponseStatusCodeSame(403);
        foreach (self::ACTIONS as $action) {
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, '/invoices/recurring/'.$template->id().'/'.$action);
                self::assertResponseStatusCodeSame(403, $method.' '.$action);
            }
        }
        self::assertCount(0, $this->client->request('GET', '/contacts')->filter('#sidebar a[href="/invoices/recurring"]'));
    }

    /**
     * @param array<string, string> $values
     */
    private function post(RecurringInvoice $template, string $action, array $values = []): void
    {
        $token = $this->token($template);
        $this->client->request('POST', '/invoices/recurring/'.$template->id().'/'.$action, ['_token' => $token] + $values, server: self::DIALOG);
    }

    private function token(RecurringInvoice $template): string
    {
        return $this->client->request('GET', '/invoices/recurring/'.$template->id().'/end')->filter('form#recurring-action input[name="_token"]')->attr('value') ?? '';
    }

    /**
     * The queries of what the list reads for a page, the names on its rows included.
     */
    private function queriesOfAPage(): int
    {
        $this->em()->clear();
        $before = $this->queryCount();
        $this->scoped(function (): void {
            $container = self::getContainer();
            $page = $container->get(RecurringInvoices::class)->page(new RecurringFilter(RecurringState::Active, false, 1));
            $lines = $container->get(RecurringOverview::class)->prepare($page->templates);
            $container->get(RecurringAmounts::class)->payable($page->templates, $lines, new \DateTimeImmutable('2026-11-20'));
            foreach ($page->templates as $template) {
                $template->customer()->displayName();
            }
        });

        return $this->queryCount() - $before;
    }

    private function failed(RecurringInvoice $template, string $code): void
    {
        $this->scoped(fn (): int|string => $this->em()->getConnection()->executeStatement("UPDATE recurring_invoice SET last_failure_code = :code, last_failure_at = '2026-11-20 04:00:00' WHERE id = :id", ['code' => $code, 'id' => $template->id()]));
    }

    private function rowsIn(string $table): int
    {
        $count = $this->scoped(fn (): mixed => $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table));
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function queryCount(): int
    {
        return \count(self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? []);
    }

    private function flashes(Crawler $crawler): string
    {
        return implode(' | ', $crawler->filter('main [role="status"], main [role="alert"]')->each(static fn (Crawler $flash): string => $flash->text()));
    }

    /**
     * @return list<string>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Recurring invoice lists"] a')->each(static fn (Crawler $tab): string => (string) preg_replace('/(\D)(\d)/', '$1 $2', $tab->text()));
    }

    /**
     * @return list<string>
     */
    private function headers(Crawler $crawler): array
    {
        return array_values(array_filter($crawler->filter('thead th')->each(static fn (Crawler $header): string => trim($header->text())), static fn (string $text): bool => '' !== $text && 'Actions' !== $text));
    }

    /**
     * @return list<string>
     */
    private function rowTexts(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr')->each(static fn (Crawler $row): string => $row->text());
    }

    /**
     * @return list<string>
     */
    private function menu(Crawler $row): array
    {
        return $row->filter('[role="menu"] [role="menuitem"]')->each(static fn (Crawler $item): string => $item->text());
    }

    /**
     * @return array<string, mixed>
     */
    private function templateRow(string $id): array
    {
        return $this->scoped(function () use ($id): array {
            $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM recurring_invoice WHERE id = :id', ['id' => $id]);
            self::assertIsArray($row);

            return $row;
        });
    }

    private function recurrence(string $first = '2026-12-01', RecurrenceUnit $unit = RecurrenceUnit::Month, int $every = 1, RecurrenceEnd $end = RecurrenceEnd::Never, ?int $endAfter = null): Recurrence
    {
        return new Recurrence(new \DateTimeImmutable($first), $unit, $every, false, $end, null, $endAfter);
    }

    private function template(?Recurrence $recurrence = null, string $description = 'Hosting', RecurringDelivery $delivery = RecurringDelivery::Send, ?Party $customer = null, bool $series = true, string $name = ''): RecurringInvoice
    {
        return $this->scoped(fn (): RecurringInvoice => self::getContainer()->get(SaveRecurringInvoice::class)->create($this->input(
            $series ? $this->series : null,
            $customer ?? $this->customer,
            $recurrence ?? $this->recurrence(),
            $description,
            $delivery,
            $name,
        )));
    }

    private function input(?Series $series, Party $customer, Recurrence $recurrence, string $description, RecurringDelivery $delivery, string $name = ''): RecurringInput
    {
        $line = new LineInput($description, Quantity::fromString('1'), 'ks', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

        return new RecurringInput($series?->id(), $customer->id(), PaymentMethod::BankTransfer, self::IBAN, '', '', $description, '', '', DocumentLanguage::Czech, [$line], $recurrence, TaxPointRule::IssueDate, 14, $delivery, $name);
    }

    private function signedIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em()->persist($this->firm);
        FirmAccounts::add($this->em(), self::IBAN);
        $this->series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->series->markDefault();
        $this->em()->persist($this->series);
        $this->em()->flush();
        $this->customer = $this->customerNamed('Example Customer a.s.');
        $this->browser->signIn();
    }

    private function customerNamed(string $name): Party
    {
        return $this->scoped(function () use ($name): Party {
            $customer = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
            $this->em()->persist($customer);
            $this->em()->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
            $this->em()->flush();

            return $customer;
        });
    }

    private function nbsp(string $text): string
    {
        return str_replace(' ', "\u{a0}", $text);
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

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
