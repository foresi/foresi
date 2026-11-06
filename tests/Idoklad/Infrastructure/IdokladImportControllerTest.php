<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Infrastructure;

use App\Identity\Domain\Role;
use App\Idoklad\Application\ConnectIdoklad;
use App\Idoklad\Application\IdokladJobRunner;
use App\Idoklad\Application\Message\ProcessIdokladJob;
use App\Idoklad\Domain\IdokladConnection;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladJob;
use App\Idoklad\Domain\IdokladJobKind;
use App\Idoklad\Domain\IdokladJobState;
use App\Idoklad\Domain\IdokladLink;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportMode;
use App\Idoklad\Domain\ImportOutcome;
use App\Idoklad\Domain\ImportReport;
use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringContent;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\TaxPointRule;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Import\Support\RunMessages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class IdokladImportControllerTest extends WebTestCase
{
    private const string SECRET = 'example-client-secret-0123456789';
    private const int CZECHIA = 2;
    private const int CZK = 1;
    private const int CASH = 3;
    private const int CUSTOMER = 501;

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private TenantId $tenant;
    private LegalEntity $firm;

    /** The status iDoklad answers its API requests with. */
    private int $apiStatus = 200;
    private int $tokenStatus = 200;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em(), $this->context);
        Clock::set(new MockClock('2026-11-06 12:00:00'));
        self::getContainer()->set('http_client', new MockHttpClient(fn (string $method, string $url): ResponseInterface => $this->answer($method, $url)));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testConnectingSavesTheKeysAndTriesThem(): void
    {
        $this->signedInFirm();

        $this->client->request('GET', $this->url('/connect'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Settings → Applications → API');
        self::assertSelectorTextContains('main', 'Foresi only reads from iDoklad');

        $this->submitKeys('example-client', self::SECRET);

        self::assertResponseRedirects($this->url('/pull'));
        self::assertContains('GET https://api.idoklad.cz/v3/Contacts?page=1&pagesize=1', $this->sent);
        $connection = $this->connection();
        self::assertInstanceOf(IdokladConnection::class, $connection);
        self::assertSame('example-client', $connection->clientId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'iDoklad is connected.');
        self::assertSelectorTextContains('[data-quota]', '0 of 7,500 requests used');
    }

    public function testEmptyKeysAreRefusedAtTheirFields(): void
    {
        $this->signedInFirm();

        $this->submitKeys('', ' ');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#client_id-error');
        self::assertSelectorExists('#client_secret-error');
        self::assertNull($this->connection());
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'wrong keys' => [401, 200, 'iDoklad refused the client ID or the client secret.'];
        yield 'a tariff without the API' => [200, 402, 'The tariff of this iDoklad account has no API access'];
        yield 'an answer it cannot give' => [200, 500, 'iDoklad answered with status 500.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testARefusalOfIDokladIsShownClearly(int $tokenStatus, int $apiStatus, string $message): void
    {
        $this->signedInFirm();
        $this->tokenStatus = $tokenStatus;
        $this->apiStatus = $apiStatus;

        $this->submitKeys('example-client', self::SECRET);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-connect-error]', $message);
        self::assertInstanceOf(IdokladConnection::class, $this->connection(), 'The keys stay, to try again once the tariff allows it.');
    }

    public function testDisconnectingDeletesTheKeys(): void
    {
        $this->connectedFirm();

        $this->client->request('GET', $this->url('/pull'));
        self::assertSelectorExists('form[action$="/disconnect"][data-turbo-confirm][data-confirm-tone="danger"]');
        $this->client->request('POST', $this->url('/disconnect'), ['_token' => $this->token()]);

        self::assertResponseRedirects($this->url('/connect'));
        self::assertNull($this->connection());
    }

    public function testAPullIsABackgroundJobWhosePageShowsItsState(): void
    {
        $this->connectedFirm();

        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token()]);

        self::assertResponseRedirects($this->url());
        $waiting = [...$this->transport()->get()];
        self::assertCount(1, $waiting);
        self::assertInstanceOf(ProcessIdokladJob::class, $waiting[0]->getMessage());
        $this->client->request('GET', $this->url());
        self::assertSelectorTextContains('[data-job-state]', 'Waiting');
        self::assertSelectorExists('turbo-frame#idoklad-progress[data-controller="import-progress"]');
        $this->client->request('GET', $this->url('/progress'));
        self::assertSelectorExists('[data-progress-active="true"][data-progress-state="pending"]');
        $this->client->request('GET', $this->url('/pull'));
        self::assertResponseRedirects($this->url());

        // Asking again while it waits gives the same job.
        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token()]);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM idoklad_job'));

        // A request resets the in-memory queue, so the worker gets the message as it was sent.
        $this->messages()->redeliver($waiting[0]);

        $job = $this->latestJob();
        self::assertSame(IdokladJobState::Done, $job->state());
        self::assertSame(IdokladJobKind::Pull, $job->kind());
        self::assertNotNull($job->resultId());
        $this->client->request('GET', $this->url());
        self::assertResponseRedirects($this->url('/pull'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-pull-state]', 'Done');
        self::assertSelectorTextContains('main', 'Contacts');
        self::assertSelectorTextContains('[data-quota]', 'of 7,500 requests used');
        self::assertSelectorTextContains('main', 'The limit depends on the tariff');
        self::assertSelectorTextContains('main', 'Download changes');

        // A second delivery of the message finds the job ended and does nothing.
        $this->sent = [];
        $this->messages()->redeliver($waiting[0]);
        self::assertSame([], $this->sent());
    }

    public function testASecondPullAsksOnlyForChanges(): void
    {
        $this->connectedFirm();
        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token()]);
        $this->messages()->run();
        $this->sent = [];

        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token(), 'changes' => '1']);
        $this->messages()->run();

        self::assertSame(IdokladJobKind::Changes, $this->latestJob()->kind());
        self::assertNotEmpty(array_filter($this->sent(), static fn (string $request): bool => str_contains($request, 'DateLastChange')));
    }

    public function testAFailedPullSaysWhy(): void
    {
        $this->connectedFirm();
        $this->apiStatus = 402;

        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token()]);
        $this->messages()->run();

        $job = $this->latestJob();
        self::assertSame(IdokladJobState::Failed, $job->state());
        self::assertSame(IdokladJobRunner::TARIFF, $job->error());
        $this->client->request('GET', $this->url('/pull'));
        self::assertSelectorTextContains('[data-job-error]', 'has no API access');
    }

    public function testTheCheckReportShowsRefusalsMismatchesAndYears(): void
    {
        $this->connectedFirm();
        $pull = $this->pull();
        $this->record(IdokladResource::IssuedInvoices, 101, ['DocumentNumber' => '20260001', 'DateOfIssue' => '2026-10-01', 'DateOfMaturity' => '2026-10-15', 'PartnerAddress' => ['NickName' => 'Example Trading s.r.o.'], 'Prices' => ['TotalWithVat' => 2420.0, 'TotalPaid' => 0.0]]);
        $this->record(IdokladResource::IssuedInvoices, 102, ['DocumentNumber' => '20260002', 'DateOfIssue' => '2026-10-03', 'PartnerAddress' => ['NickName' => 'Example Buyer a.s.'], 'Prices' => ['TotalWithVat' => 560.0]]);
        $this->record(IdokladResource::IssuedInvoices, 103, ['DocumentNumber' => '20250009', 'DateOfIssue' => '2025-12-03', 'Prices' => ['TotalWithVat' => 100.0]]);
        $report = new ImportReport();
        $report->add('documents', 101, ImportOutcome::Created, values: ['resource' => 'IssuedInvoices', 'document_number' => '20260001']);
        $report->add('documents', 102, ImportOutcome::Mismatch, values: ['resource' => 'IssuedInvoices', 'document_number' => '20260002', 'expected_gross' => 56000, 'computed_gross' => 55900, 'expected_net' => 50000, 'computed_net' => 50000]);
        $report->add('documents', 103, ImportOutcome::Refused, reasons: ['idoklad.import.documents.currency_not_home'], values: ['resource' => 'IssuedInvoices', 'document_number' => '20250009', 'currency' => 'EUR']);
        $report->add('recurring', 7, ImportOutcome::Created, values: ['next_issues' => '2026-12-01,2027-01-01']);
        $this->storeRun(ImportMode::Check, $report, $pull);

        $this->client->request('GET', $this->url('/check'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-report-refused]', '20250009');
        self::assertSelectorTextContains('[data-report-refused]', 'The document is not in the home currency');
        self::assertSelectorTextContains('[data-report-refused]', 'Currency: EUR');
        self::assertSelectorTextContains('[data-report-mismatch]', '20260002');
        self::assertSelectorTextContains('[data-report-mismatch]', 'Example Buyer a.s.');
        self::assertSelectorTextContains('[data-report-mismatch]', "With VAT: iDoklad 560.00\u{a0}CZK, Foresi 559.00\u{a0}CZK");
        self::assertSelectorTextNotContains('[data-report-mismatch]', 'Without VAT');
        self::assertSelectorTextContains('[data-report-templates]', '1 Dec 2026');
        $years = $this->client->getCrawler()->filter('tr[data-id="y2026"] td')->each(static fn ($cell): string => trim($cell->text()));
        self::assertSame(['2026', '2', "2,980.00\u{a0}CZK", '1', "2,420.00\u{a0}CZK"], $years);
        self::assertSelectorTextContains('[data-overdue]', '1 imported invoice is overdue and unpaid.');
        self::assertSame('2026-11-06', $this->client->getCrawler()->filter('#remind_from')->attr('value'));
        self::assertSelectorExists('form#idoklad-run[data-turbo-confirm]');
        self::assertStringContainsString('2 records are created, 1 refused, 1 differ', (string) $this->client->getCrawler()->filter('form#idoklad-run')->attr('data-confirm-text'));
    }

    public function testACheckIsABackgroundJobOfThePulledSnapshot(): void
    {
        $this->connectedFirm();
        $this->pull();

        $this->client->request('POST', $this->url('/check'), ['_token' => $this->token()]);
        $this->messages()->run();

        self::assertSame(IdokladJobKind::Check, $this->latestJob()->kind());
        self::assertSame(IdokladJobState::Done, $this->latestJob()->state());
        $this->client->request('GET', $this->url());
        self::assertResponseRedirects($this->url('/check'));
    }

    public function testARunSetsTheDayRemindersStartFrom(): void
    {
        $this->connectedFirm();
        $this->storeRun(ImportMode::Check, new ImportReport(), $this->pull());

        $this->client->request('POST', $this->url('/run'), ['_token' => $this->token(), 'remind_from' => '2026-11-02']);
        self::assertResponseRedirects($this->url());
        $this->messages()->run();

        $job = $this->latestJob();
        self::assertSame(IdokladJobState::Done, $job->state(), (string) $job->error());
        self::assertSame('2026-11-02', $this->reminders()?->enabledOn()?->format('Y-m-d'));
        $this->client->request('GET', $this->url());
        self::assertResponseRedirects($this->url('/runs/'.$job->resultId()));
    }

    public function testARunNeedsADayForReminders(): void
    {
        $this->connectedFirm();
        $this->storeRun(ImportMode::Check, new ImportReport(), $this->pull());

        $this->client->request('POST', $this->url('/run'), ['_token' => $this->token(), 'remind_from' => '2026-13-40']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#remind_from-error');
        self::assertSame([], $this->messages()->pending());
    }

    public function testAnUndoRefusedNamesItsBlockers(): void
    {
        $run = $this->importedRun();
        $this->em()->getConnection()->executeStatement('UPDATE invoice SET version = 2');

        $this->client->request('GET', $this->url('/runs/'.$run));
        self::assertSelectorExists('form[action$="/undo"][data-turbo-confirm]');
        $this->client->request('POST', $this->url('/runs/'.$run.'/undo'), ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('[data-undo-blockers]', '20260008');
        self::assertSelectorTextContains('[data-undo-blockers]', 'changed since the import');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM invoice'));
    }

    public function testAnUndoTakesBackTheRunAndItsLinks(): void
    {
        $run = $this->importedRun();
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM idoklad_link WHERE resource = 'IssuedInvoices'"));
        $untouched = $this->template($run, 7, false);
        $resumed = $this->template($run, 8, true);
        $this->link($run, 9, '0190f5c4-6b1e-7c3a-9f7e-1a2b3c4d5e6f');

        $this->client->request('POST', $this->url('/runs/'.$run.'/undo'), ['_token' => $this->token()]);

        self::assertResponseRedirects($this->url('/runs/'.$run));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM invoice'));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM idoklad_link WHERE resource = 'IssuedInvoices'"));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM idoklad_link WHERE resource = 'Contacts'"), 'Contacts stay.');
        self::assertSame([$resumed], $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM recurring_invoice'), 'A template touched since stays.');
        self::assertEquals([8], $this->em()->getConnection()->fetchFirstColumn("SELECT external_id FROM idoklad_link WHERE resource = 'RecurringInvoices'"));
        self::assertNotContains($untouched, $this->em()->getConnection()->fetchFirstColumn('SELECT target_id FROM idoklad_link'));
        $this->client->followRedirect();
        self::assertSelectorExists('[data-run-undone]');
        self::assertSelectorNotExists('form[action$="/undo"]');

        $this->client->request('POST', $this->url('/runs/'.$run.'/undo'), ['_token' => $this->token()]);
        self::assertResponseRedirects($this->url('/runs/'.$run));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'This import can no longer be undone.');
    }

    public function testTheHistoryListsPullsAndRuns(): void
    {
        $this->connectedFirm();
        $this->storeRun(ImportMode::Check, new ImportReport(), $this->pull());

        $this->client->request('GET', $this->url('/history'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main table', 'Whole account');
        self::assertSelectorTextContains('main table', 'Check');
    }

    public function testTheImportHistoryLeadsToTheMoveAndOneFirmOpensAtOnce(): void
    {
        $this->signedInFirm();

        $this->client->request('GET', '/imports');
        self::assertSelectorExists('a[href="/import/idoklad"]');
        $this->client->request('GET', '/import/idoklad');

        self::assertResponseRedirects($this->url());
        $this->client->followRedirect();
        self::assertResponseRedirects($this->url('/connect'));
    }

    public function testOnlyTheOwnerMovesFromIDoklad(): void
    {
        $tenant = $this->browser->seed(['Tenant A'], role: Role::Member)[0];
        $firm = $this->firm($tenant);
        $this->browser->signIn();

        $this->client->request('GET', '/imports');
        self::assertSelectorNotExists('a[href="/import/idoklad"]');
        foreach (['', '/connect', '/pull', '/check', '/history'] as $path) {
            $this->client->request('GET', '/import/idoklad/'.$firm->id().$path);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('GET', '/import/idoklad');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAFirmOrRunOfAnotherTenantIsNotFound(): void
    {
        $this->signedInFirm();
        $foreign = $this->firm($this->browser->fixtures->tenant('Foreign Tenant'), '12345687');

        $this->client->request('GET', '/import/idoklad/'.$foreign->id().'/pull');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->url('/runs/0190f5c4-6b1e-7c3a-9f7e-1a2b3c4d5e6f'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAWrongTokenIsRefused(): void
    {
        $this->connectedFirm();

        $this->client->request('POST', $this->url('/pull'), ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->messages()->pending());
    }

    /**
     * A run that brought one invoice and its customer, through the real import.
     */
    private function importedRun(): string
    {
        $this->connectedFirm();
        $pull = $this->pull();
        $this->record(IdokladResource::Countries, self::CZECHIA, ['Id' => self::CZECHIA, 'Code' => 'CZE', 'Name' => 'Česká republika', 'NameEnglish' => 'Czech Republic', 'CurrencyId' => self::CZK]);
        $this->record(IdokladResource::Currencies, self::CZK, ['Id' => self::CZK, 'Code' => 'CZK', 'Symbol' => 'Kč']);
        $this->record(IdokladResource::PaymentOptions, self::CASH, ['Id' => self::CASH, 'Code' => 'H', 'Name' => 'Hotově', 'IsDefault' => true]);
        $this->record(IdokladResource::Contacts, self::CUSTOMER, ['Id' => self::CUSTOMER, 'CompanyName' => 'Example Trading s.r.o.', 'IdentificationNumber' => '23456787', 'Email' => 'billing@example.com', 'Street' => 'Example Street 9', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => self::CZECHIA]);
        $this->record(IdokladResource::NumericSequences, 1, ['Id' => 1, 'DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 9, 'IsDefault' => true]);
        $address = ['NickName' => 'Example Trading s.r.o.', 'IdentificationNumber' => '23456787', 'Email' => 'billing@example.com', 'Street' => 'Example Street 9', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => self::CZECHIA];
        $mine = ['NickName' => 'Example Firm s.r.o.', 'IdentificationNumber' => '12345695', 'Street' => 'Example Street 1', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => self::CZECHIA];
        $this->record(IdokladResource::IssuedInvoices, 101, [
            'Id' => 101, 'DocumentNumber' => '20260008', 'DocumentSerialNumber' => 8, 'VariableSymbol' => '20260008', 'CurrencyId' => self::CZK, 'ExchangeRate' => 1.0, 'ExchangeRateAmount' => 1.0,
            'DateOfIssue' => '2026-10-01', 'DateOfTaxing' => '2026-10-01', 'DateOfMaturity' => '2026-10-15', 'Description' => 'Consulting', 'PartnerId' => self::CUSTOMER, 'PartnerAddress' => $address, 'MyAddress' => $mine,
            'PaymentOptionId' => self::CASH, 'ReportLanguage' => 1, 'VatRegime' => 0, 'IsSentToPurchaser' => 0, 'Exported' => 0, 'ProformaInvoices' => [],
            'Items' => [['Id' => 1, 'Name' => 'Consulting', 'Amount' => 2.0, 'Unit' => 'h', 'ItemType' => 0, 'PriceType' => 1, 'VatRateType' => 3, 'VatRate' => 0.0, 'DiscountPercentage' => 0.0, 'Prices' => ['UnitPrice' => 1000.0, 'TotalWithoutVat' => 2000.0, 'TotalVat' => 0.0, 'TotalWithVat' => 2000.0]]],
            'Prices' => ['TotalWithoutVat' => 2000.0, 'TotalVat' => 0.0, 'TotalWithVat' => 2000.0, 'TotalPaid' => 0.0],
            'Metadata' => ['DateCreated' => '2026-10-01T09:00:00.123', 'DateLastChange' => '2026-10-02T10:39:22.873'],
        ]);
        $this->storeRun(ImportMode::Check, new ImportReport(), $pull);
        $this->client->request('POST', $this->url('/run'), ['_token' => $this->token(), 'remind_from' => '2026-11-06']);
        $this->messages()->run();
        $job = $this->latestJob();
        self::assertSame(IdokladJobState::Done, $job->state(), (string) $job->error());
        $runId = (string) $job->resultId();
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM invoice'), json_encode($this->em()->getConnection()->fetchOne('SELECT report FROM idoklad_import_run WHERE id = ?', [$runId]), \JSON_THROW_ON_ERROR));

        return $runId;
    }

    /**
     * A recurring template the run brought, paused as imported, and touched since when it says so.
     */
    private function template(string $runId, int $externalId, bool $touched): string
    {
        $id = $this->inTenant(function () use ($touched, $externalId): string {
            $customer = new Party(PartyKind::Person, 'Example Customer', null, null, '');
            $this->em()->persist($customer);
            $content = new RecurringContent(null, $customer, PaymentMethod::Cash, null, '', '', 'Hosting', '', '', DocumentLanguage::English, new Recurrence(new \DateTimeImmutable('2026-12-01'), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null), TaxPointRule::IssueDate, 14, RecurringDelivery::Review, 'Hosting '.$externalId);
            $template = new RecurringInvoice($this->storedFirm(), Currency::CZK, $content, null, new \DateTimeImmutable('2026-11-06 10:00:00'), true, 'idoklad:'.$externalId);
            if ($touched) {
                $template->resume(new \DateTimeImmutable('2026-11-06'));
            }
            $this->em()->persist($template);
            $this->em()->flush();

            return $template->id();
        });
        $this->link($runId, $externalId, $id);

        return $id;
    }

    private function link(string $runId, int $externalId, string $targetId): void
    {
        $this->inTenant(function () use ($runId, $externalId, $targetId): void {
            $this->em()->persist(new IdokladLink($this->storedFirm(), IdokladResource::RecurringInvoices, $externalId, $targetId, $runId, new \DateTimeImmutable('2026-11-06 10:00:00')));
            $this->em()->flush();
        });
    }

    /**
     * @return list<string> the requests sent to iDoklad, as method and URL
     */
    private function sent(): array
    {
        return $this->sent;
    }

    private function submitKeys(string $clientId, string $secret): void
    {
        $this->client->request('POST', $this->url('/connect'), ['_token' => $this->token(), 'client_id' => $clientId, 'client_secret' => $secret]);
    }

    private function answer(string $method, string $url): ResponseInterface
    {
        $this->sent[] = $method.' '.urldecode($url);
        if (str_starts_with($url, 'https://identity.idoklad.cz/')) {
            return 200 === $this->tokenStatus ? new JsonMockResponse(['access_token' => 'example-token', 'expires_in' => 3600]) : new MockResponse('', ['http_code' => $this->tokenStatus]);
        }
        if (200 !== $this->apiStatus) {
            return new MockResponse('', ['http_code' => $this->apiStatus]);
        }
        $items = str_contains($url, '/v3/Contacts?') ? [['Id' => 501, 'CompanyName' => 'Example Trading s.r.o.', 'Metadata' => ['DateLastChange' => '2026-11-01T10:00:00.000']]] : [];

        return new JsonMockResponse(['Data' => ['Items' => $items, 'TotalItems' => \count($items), 'TotalPages' => 1], 'IsSuccess' => true, 'StatusCode' => 200]);
    }

    private function signedInFirm(): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A']);
        $this->browser->signIn();
        $this->firm = $this->firm($this->tenant);
    }

    private function connectedFirm(): void
    {
        $this->signedInFirm();
        $this->inTenant(fn () => self::getContainer()->get(ConnectIdoklad::class)->connect($this->storedFirm(), 'example-client', self::SECRET));
    }

    private function firm(TenantId $tenant, string $number = '12345695'): LegalEntity
    {
        $this->context->switchTo($tenant);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em()->persist($firm);
        $this->em()->flush();
        $this->context->reset();

        return $firm;
    }

    private function pull(): IdokladPull
    {
        return $this->inTenant(function (): IdokladPull {
            $pull = new IdokladPull($this->storedFirm(), new \DateTimeImmutable('2026-11-06 09:00:00'));
            $pull->counted(IdokladResource::Contacts, 1);
            $pull->finish(new \DateTimeImmutable('2026-11-06 09:05:00'), 3);
            $this->em()->persist($pull);
            $this->em()->flush();

            return $pull;
        });
    }

    /**
     * @param array<mixed> $payload
     */
    private function record(IdokladResource $resource, int $id, array $payload): void
    {
        $this->inTenant(function () use ($resource, $id, $payload): void {
            $this->em()->persist(new IdokladRecord($this->storedFirm(), $resource, $id, $payload, new \DateTimeImmutable('2026-11-06 09:01:00')));
            $this->em()->flush();
        });
    }

    private function storeRun(ImportMode $mode, ImportReport $report, IdokladPull $pull): void
    {
        $this->inTenant(function () use ($mode, $report, $pull): void {
            $run = new IdokladImportRun($this->storedFirm(), $mode, new \DateTimeImmutable('2026-11-06 10:00:00'));
            $run->readFrom($pull);
            $run->finish(new \DateTimeImmutable('2026-11-06 10:01:00'), $report);
            $this->em()->persist($run);
            $this->em()->flush();
        });
    }

    private function connection(): ?IdokladConnection
    {
        return $this->inTenant(fn (): ?IdokladConnection => $this->em()->getRepository(IdokladConnection::class)->findOneBy(['firm' => $this->firm->id()]));
    }

    private function latestJob(): IdokladJob
    {
        return $this->inTenant(fn (): IdokladJob => $this->em()->getRepository(IdokladJob::class)->findOneBy([], ['requestedAt' => 'DESC', 'id' => 'DESC']) ?? self::fail('No job was asked for.'));
    }

    private function reminders(): ?FirmReminders
    {
        return $this->inTenant(fn (): ?FirmReminders => $this->em()->getRepository(FirmReminders::class)->findOneBy(['firm' => $this->firm->id()]));
    }

    private function storedFirm(): LegalEntity
    {
        return $this->em()->find(LegalEntity::class, $this->firm->id()) ?? self::fail('The firm is stored.');
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function inTenant(callable $work): mixed
    {
        $this->em()->clear();
        $had = $this->context->has();
        $this->context->switchTo($this->tenant);
        try {
            return $work();
        } finally {
            if (!$had) {
                $this->context->reset();
            }
            $this->em()->clear();
        }
    }

    private function messages(): RunMessages
    {
        return new RunMessages($this->transport(), self::getContainer()->get('messenger.default_bus'));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function url(string $path = ''): string
    {
        return '/import/idoklad/'.$this->firm->id().$path;
    }

    private function token(): string
    {
        // As a browser has it: from a form of the move.
        return (string) $this->client->request('GET', $this->url('/connect'))->filter('form[action$="/connect"] input[name="_token"]')->attr('value');
    }

    private function rows(string $sql): int
    {
        $count = $this->em()->getConnection()->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
