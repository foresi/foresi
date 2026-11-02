<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\IssueRecurringInvoices;
use App\Invoicing\Application\IssueRecurringTemplate;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\RecurringAmounts;
use App\Invoicing\Application\RecurringFilter;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\RecurringLifecycle;
use App\Invoicing\Application\RecurringOverview;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringState;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use Doctrine\ORM\EntityManagerInterface;
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
    private TenantContext $context;
    private TenantId $tenant;
    private LegalEntity $firm;
    private Series $series;
    private Party $customer;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAnEmptyListOffersANewRecurringInvoice(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices/recurring');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Recurring invoices');
        self::assertCount(0, $crawler->filter('nav[aria-label="Recurring invoice lists"]'));
        self::assertGreaterThan(0, $crawler->filter('main a[href="/invoices/recurring/new"]')->count());
        self::assertStringContainsString('Recurring invoice', $crawler->filter('main')->text(), 'the hint names the menu item on an invoice');
    }

    public function testTheSidebarAndThePaletteOfferRecurringInvoices(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices');

        $link = $crawler->filter('#sidebar a[href="/invoices/recurring"]');
        self::assertCount(1, $link);
        self::assertStringContainsString('Recurring invoices', $link->text());
        self::assertStringContainsString('/invoices/recurring/new', (string) $this->client->getResponse()->getContent(), 'the palette offers a new one');
    }

    public function testTheListShowsNextIssueAndInterval(): void
    {
        $this->signedIn();
        $this->template(description: 'Hosting {month/year}');
        $this->template(recurrence: $this->recurrence(unit: RecurrenceUnit::Year), description: 'Domain');
        $this->template(recurrence: $this->recurrence(every: 12, end: RecurrenceEnd::AfterCount, endAfter: 3), description: 'Licence', delivery: RecurringDelivery::Review);

        $crawler = $this->client->request('GET', '/invoices/recurring');

        self::assertResponseIsSuccessful();
        self::assertSame(['Active 3', 'Paused 0', 'Ended 0', 'Failing 0'], $this->tabs($crawler));
        self::assertSame(['Customer', 'Description', 'Repeats', 'Next issue', 'After issue', 'Amount'], $this->headers($crawler));
        $rows = $this->rowTexts($crawler);
        self::assertCount(3, $rows);
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

    public function testFiltersByFirmAndTab(): void
    {
        $this->signedIn();
        $this->template(description: 'Main');
        $paused = $this->template(description: 'Paused one');
        $this->scoped(fn () => self::getContainer()->get(RecurringLifecycle::class)->pause($paused->id()));
        $second = $this->secondFirm();
        $this->template(description: 'Other firm', firm: $second, series: false);

        $crawler = $this->client->request('GET', '/invoices/recurring');
        self::assertSame(['Active 2', 'Paused 1', 'Ended 0', 'Failing 0'], $this->tabs($crawler));
        self::assertContains('Firm', $this->headers($crawler), 'several firms name theirs');

        $crawler = $this->client->request('GET', '/invoices/recurring?firm='.$second->id());
        self::assertSame(['Active 1', 'Paused 0', 'Ended 0', 'Failing 0'], $this->tabs($crawler));
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Other firm', $crawler->filter('tbody tr')->text());

        $crawler = $this->client->request('GET', '/invoices/recurring?tab=paused');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Paused one', $crawler->filter('tbody tr')->text());
        self::assertSame(['Resume', 'End', 'Delete'], $this->menu($crawler->filter('tbody tr')));
    }

    public function testTheMenuOffersWhatTheStateAllows(): void
    {
        $this->signedIn();
        $this->template();

        $crawler = $this->client->request('GET', '/invoices/recurring');

        self::assertSame(['Issue now', 'Pause', 'End', 'Delete'], $this->menu($crawler->filter('tbody tr')));
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
            'INSERT INTO scheduled_job_run (id, tenant_id, job, last_period, finished_at) VALUES (?, ?, ?, ?, ?)',
            ['019a0000-0000-7000-8000-0000000000ac', $this->tenant->toString(), IssueRecurringInvoices::NAME, '2027-01-01', '2027-01-01 04:10:00'],
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
        self::assertSame(['Delete'], $this->menu($crawler->filter('tbody tr')));
    }

    public function testDeleteRemovesANeverIssuedTemplate(): void
    {
        $this->signedIn();
        $template = $this->template();

        $this->post($template, 'delete');

        self::assertSame(0, $this->rowsIn('recurring_invoice'));
        self::assertSame(0, $this->rowsIn('recurring_invoice_line'));
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

        self::assertSame(['Active 1', 'Paused 0', 'Ended 0', 'Failing 1'], $this->tabs($crawler));
        $row = $crawler->filter('tbody tr');
        self::assertCount(1, $row);
        self::assertStringContainsString('Failed', $row->text());
        self::assertStringContainsString('This contact is archived for the firm.', $row->text());
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
        self::assertCount(1, $crawler->filter('form[role="search"] input[name="toSend"][value="1"]'), 'a search keeps the filter');
    }

    public function testTheToSendCountFollowsTheFirmFilter(): void
    {
        $this->signedIn();
        $this->post($this->template(delivery: RecurringDelivery::Review), 'issue_now', ['occurrence' => '2026-12-01']);
        $this->post($this->template(delivery: RecurringDelivery::Review), 'issue_now', ['occurrence' => '2026-12-01']);
        $second = $this->secondFirm();
        $this->post($this->template(delivery: RecurringDelivery::Review, firm: $second, series: false), 'issue_now', ['occurrence' => '2026-12-01']);

        self::assertStringContainsString('3', $this->client->request('GET', '/invoices')->filter('[data-to-send] a')->text());
        self::assertStringContainsString('2', $this->client->request('GET', '/invoices?firm='.$this->firm->id())->filter('[data-to-send] a')->text());
        $crawler = $this->client->request('GET', '/invoices?firm='.$second->id().'&toSend=1');
        self::assertStringContainsString('1', $crawler->filter('[data-to-send] a')->text(), 'the count says what the list shows');
        self::assertCount(1, $crawler->filter('tbody tr'));
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
        self::assertSame('Hosting', $first->filter('td')->eq(1)->filter('[title]')->attr('title'), 'the description stays');
        $alert = $first->filter('td')->eq(1)->filter('[data-row-alert]');
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

        self::assertSame('/settings/firms/'.$this->firm->id().'/mail', $crawler->filter('tbody [data-row-alert] a')->attr('href'));
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
        $this->signedIn(Role::Client);
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

    public function testAnotherTenantsTemplateIs404(): void
    {
        $this->signedIn();
        $foreign = $this->foreignTemplate();
        $token = $this->token($this->template());

        foreach (self::ACTIONS as $action) {
            $this->client->request('GET', '/invoices/recurring/'.$foreign.'/'.$action);
            self::assertResponseStatusCodeSame(404, $action);
            $this->client->request('POST', '/invoices/recurring/'.$foreign.'/'.$action, ['_token' => $token, 'occurrence' => '2026-12-01']);
            self::assertResponseStatusCodeSame(404, $action);
        }
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
            $page = $container->get(RecurringInvoices::class)->page(new RecurringFilter(null, RecurringState::Active, false, 1));
            $lines = $container->get(RecurringOverview::class)->prepare($page->templates);
            $container->get(RecurringAmounts::class)->payable($page->templates, $lines, new \DateTimeImmutable('2026-11-20'));
            foreach ($page->templates as $template) {
                $template->customer()->displayName();
                $template->firm()->name();
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
        return $row->filter('[role="menu"] [role="menuitem"]:not([data-phone-only])')->each(static fn (Crawler $item): string => $item->text());
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

    private function template(?Recurrence $recurrence = null, string $description = 'Hosting', RecurringDelivery $delivery = RecurringDelivery::Send, ?Party $customer = null, ?LegalEntity $firm = null, bool $series = true): RecurringInvoice
    {
        return $this->scoped(fn (): RecurringInvoice => self::getContainer()->get(SaveRecurringInvoice::class)->create($this->input(
            $firm ?? $this->firm,
            $series ? $this->series : null,
            $customer ?? $this->customer,
            $recurrence ?? $this->recurrence(),
            $description,
            $delivery,
        )));
    }

    private function input(LegalEntity $firm, ?Series $series, Party $customer, Recurrence $recurrence, string $description, RecurringDelivery $delivery): RecurringInput
    {
        $line = new LineInput($description, Quantity::fromString('1'), 'ks', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

        return new RecurringInput($firm->id(), $series?->id(), $customer->id(), PaymentMethod::BankTransfer, self::IBAN, '', '', $description, '', '', DocumentLanguage::Czech, [$line], $recurrence, TaxPointRule::IssueDate, 14, $delivery);
    }

    private function signedIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], role: $role);
        $this->context->switchTo($this->tenant);
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1', [self::IBAN]);
        $this->em()->persist($this->firm);
        $this->series = new Series($this->firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->series->markDefault();
        $this->em()->persist($this->series);
        $this->em()->flush();
        $this->context->reset();
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

    private function secondFirm(): LegalEntity
    {
        return $this->scoped(function (): LegalEntity {
            $firm = new LegalEntity('Example Second s.r.o.', new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), new Address('Example Street 2', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 2', [self::IBAN]);
            $this->em()->persist($firm);
            $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString('S{YYYY}{NNNN}'), DocumentKind::Invoice);
            $series->markDefault();
            $this->em()->persist($series);
            $this->em()->flush();

            return $firm;
        });
    }

    private function foreignTemplate(): string
    {
        $tenant = $this->browser->fixtures->tenant('Foreign Tenant '.bin2hex(random_bytes(2)));
        $this->context->switchTo($tenant);
        try {
            $firm = new LegalEntity('Example Foreign s.r.o.', new RegistrationNumber('CZ', '12345679'), VatId::fromString('CZ12345679'), new Address('Example Street 3', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Register', [self::IBAN]);
            $this->em()->persist($firm);
            $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
            $series->markDefault();
            $this->em()->persist($series);
            $customer = new Party(PartyKind::Company, 'Foreign Customer a.s.', new RegistrationNumber('CZ', '87654320'), null, '');
            $this->em()->persist($customer);
            $this->em()->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
            $this->em()->flush();

            return self::getContainer()->get(SaveRecurringInvoice::class)->create($this->input($firm, $series, $customer, $this->recurrence(), 'Foreign', RecurringDelivery::Send))->id();
        } finally {
            $this->context->reset();
        }
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
        $this->context->switchTo($this->tenant);
        try {
            return $work();
        } finally {
            $this->context->reset();
        }
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
