<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Idoklad\Integration\Country;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Idoklad\Application\ConnectIdoklad;
use Foresi\Idoklad\Application\Import\IdokladImportRuns;
use Foresi\Idoklad\Application\Import\IdokladLinks;
use Foresi\Idoklad\Application\Import\UndoIdokladImport;
use Foresi\Idoklad\Domain\IdokladImportRun;
use Foresi\Idoklad\Domain\IdokladJob;
use Foresi\Idoklad\Domain\IdokladJobKind;
use Foresi\Idoklad\Domain\IdokladJobState;
use Foresi\Idoklad\Domain\IdokladLink;
use Foresi\Idoklad\Domain\IdokladPull;
use Foresi\Idoklad\Domain\IdokladRecord;
use Foresi\Idoklad\Domain\IdokladResource;
use Foresi\Idoklad\Domain\ImportMode;
use Foresi\Idoklad\Domain\ImportOptions;
use Foresi\Idoklad\Domain\ImportOutcome;
use Foresi\Idoklad\Domain\ImportReport;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringPage;
use Foresi\Invoicing\Application\UndoImportedDocuments;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\FirmReminders;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\RecurringContent;
use Foresi\Invoicing\Domain\RecurringDelivery;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringInvoiceLine;
use Foresi\Invoicing\Domain\TaxPointRule;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Support\Messenger\RunMessages;
use Psr\Clock\ClockInterface;
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
    private const int EUR = 2;
    private const int CASH = 3;
    private const int CUSTOMER = 501;

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private LegalEntity $firm;

    /** The status iDoklad answers its API requests with. */
    private int $apiStatus = 200;
    private int $tokenStatus = 200;

    /** Whether iDoklad's API fails as nothing expects. */
    private bool $unexpected = false;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, $this->em());
        Clock::set(new MockClock('2026-11-06 12:00:00'));
        self::getContainer()->set('http_client', new MockHttpClient(fn (string $method, string $url): ResponseInterface => $this->answer($url)));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
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

    public function testTheCheckReportShowsRefusalsMismatchesAndYears(): void
    {
        $this->connectedFirm();
        $pull = $this->pull();
        $this->record(IdokladResource::IssuedInvoices, 101, ['DocumentNumber' => '20260001', 'DateOfIssue' => '2026-10-01', 'DateOfMaturity' => '2026-10-15', 'PartnerAddress' => ['NickName' => 'Example Trading s.r.o.'], 'Prices' => ['TotalWithVat' => 2420.0, 'TotalPaid' => 0.0]]);
        $this->record(IdokladResource::IssuedInvoices, 102, ['DocumentNumber' => '20260002', 'DateOfIssue' => '2026-10-03', 'PartnerAddress' => ['NickName' => 'Example Buyer a.s.'], 'Prices' => ['TotalWithVat' => 560.0]]);
        $this->record(IdokladResource::IssuedInvoices, 103, ['DocumentNumber' => '20250009', 'DateOfIssue' => '2025-12-03', 'Prices' => ['TotalWithVat' => 100.0]]);
        $report = new ImportReport();
        $report->add('documents', 101, ImportOutcome::Created, values: ['resource' => 'IssuedInvoices', 'document_number' => '20260001', 'computed_payable' => 242000]);
        $report->add('documents', 102, ImportOutcome::Mismatch, values: ['resource' => 'IssuedInvoices', 'document_number' => '20260002', 'expected_gross' => 56000, 'computed_gross' => 55900, 'expected_net' => 50000, 'computed_net' => 50000]);
        $report->add('documents', 103, ImportOutcome::Refused, reasons: ['idoklad.import.documents.currency_not_home'], values: ['resource' => 'IssuedInvoices', 'document_number' => '20250009', 'currency' => 'EUR']);
        $this->record(IdokladResource::RecurringInvoices, 7, ['RecurringSetting' => ['TemplateName' => 'Monthly hosting']]);
        $report->add('recurring', 7, ImportOutcome::Created, values: ['next_issues' => '2026-12-01,2027-01-01']);
        $report->add('series', 4, ImportOutcome::Skipped, reasons: ['idoklad.import.series.type_not_used'], values: ['document_type' => 5]);
        $report->add('series', 5, ImportOutcome::Skipped, reasons: ['idoklad.import.series.type_not_used'], values: ['document_type' => 2]);
        $this->storeRun(ImportMode::Check, $report, $pull);

        $this->client->request('GET', $this->url('/check'));

        self::assertResponseIsSuccessful();
        self::assertSame(['Created', 'Matched', 'Refused', 'Differs'], $this->client->getCrawler()->filter('[data-kpi] span.truncate')->each(static fn ($kpi): string => trim($kpi->text())), 'What was skipped is counted in the table only.');
        $series = $this->client->getCrawler()->filter('tr[data-id="series"] td')->each(static fn ($cell): string => trim($cell->text()));
        self::assertSame(['Number series', '0', '0', '0', '0', '0', '2'], $series, 'Skipped is a column of its own.');
        self::assertSelectorTextNotContains('[data-report-refused]', 'Foresi does not issue documents');
        self::assertSelectorTextContains('[data-report-refused]', '20250009');
        self::assertSelectorTextContains('[data-report-refused]', 'The document is not in the home currency');
        self::assertSelectorTextContains('[data-report-refused]', 'Currency: EUR');
        self::assertSelectorTextContains('[data-report-mismatch]', '20260002');
        self::assertSelectorTextContains('[data-report-mismatch]', 'Example Buyer a.s.');
        self::assertSelectorTextContains('[data-report-mismatch]', "With VAT: iDoklad 560.00\u{a0}CZK, Foresi 559.00\u{a0}CZK");
        self::assertSelectorTextNotContains('[data-report-mismatch]', 'Without VAT');
        self::assertSelectorTextContains('[data-report-templates]', '1 Dec 2026');
        self::assertSelectorTextContains('[data-report-templates]', 'Monthly hosting');
        $years = $this->client->getCrawler()->filter('tr[data-id="y2026"] td')->each(static fn ($cell): string => trim($cell->text()));
        self::assertSame(['2026', '2', "2,980.00\u{a0}CZK", '1', "2,420.00\u{a0}CZK", "560.00\u{a0}CZK", '1', '0'], $years, 'The document that differs explains the difference.');
        self::assertStringContainsString('red', (string) $this->client->getCrawler()->filter('tr[data-id="y2026"] td')->eq(5)->filter('span')->attr('class'));
        self::assertSelectorTextContains('[data-overdue]', '1 imported invoice is overdue and unpaid.');
        self::assertSame('2026-11-06', $this->client->getCrawler()->filter('#remind_from')->attr('value'));
        self::assertSame('', $this->client->getCrawler()->filter('#vat_payer_from')->attr('value'), 'A check older than its options names no day.');
        self::assertSelectorExists('form#idoklad-run[data-turbo-confirm]');
        self::assertStringContainsString('2 records are created, 1 refused, 1 differ', (string) $this->client->getCrawler()->filter('form#idoklad-run')->attr('data-confirm-text'));
    }

    public function testACheckSumsByYearWhatForesiComputedAndLeavesOtherCurrenciesOut(): void
    {
        $this->connectedFirm();
        $pull = $this->pull();
        $this->record(IdokladResource::Currencies, self::CZK, ['Id' => self::CZK, 'Code' => 'CZK']);
        $this->record(IdokladResource::Currencies, self::EUR, ['Id' => self::EUR, 'Code' => 'EUR']);
        $this->record(IdokladResource::IssuedInvoices, 101, ['DocumentNumber' => '20260001', 'DateOfIssue' => '2026-10-01', 'CurrencyId' => self::CZK, 'Prices' => ['TotalWithVat' => 2420.0]]);
        $this->record(IdokladResource::IssuedInvoices, 104, ['DocumentNumber' => '20260004', 'DateOfIssue' => '2026-10-04', 'CurrencyId' => self::EUR, 'Prices' => ['TotalWithVat' => 100.0]]);
        $report = new ImportReport();
        $report->add('documents', 101, ImportOutcome::Created, values: ['resource' => 'IssuedInvoices', 'document_number' => '20260001', 'computed_payable' => 241900]);
        $report->add('documents', 104, ImportOutcome::Refused, reasons: ['idoklad.import.documents.currency_not_home'], values: ['resource' => 'IssuedInvoices', 'document_number' => '20260004', 'currency' => 'EUR']);
        $this->storeRun(ImportMode::Check, $report, $pull);

        $this->client->request('GET', $this->url('/check'));

        $years = $this->client->getCrawler()->filter('tr[data-id="y2026"] td')->each(static fn ($cell): string => trim($cell->text()));
        self::assertSame(['2026', '1', "2,420.00\u{a0}CZK", '1', "2,419.00\u{a0}CZK", "1.00\u{a0}CZK", '0', '1'], $years, 'Foresi\'s own total; the euro invoice is only counted.');
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

    public function testTheCheckOffersItsVatPayerDayAndTheRunTakesTheChosenOne(): void
    {
        $this->connectedFirm();
        $pull = $this->pull();
        $this->fresh(function () use ($pull): void {
            $run = new IdokladImportRun(ImportMode::Check, new \DateTimeImmutable('2026-11-06 10:00:00'));
            $run->readFrom($pull);
            $run->ranWith(new ImportOptions(new \DateTimeImmutable('2021-04-01')));
            $run->finish(new \DateTimeImmutable('2026-11-06 10:01:00'), new ImportReport());
            $this->em()->persist($run);
            $this->em()->flush();
        });

        $this->client->request('GET', $this->url('/check'));
        self::assertSame('2021-04-01', $this->client->getCrawler()->filter('#vat_payer_from')->attr('value'));
        self::assertNull($this->client->getCrawler()->filter('#vat_payer_from')->attr('required'));
        self::assertSelectorTextContains('[data-vat-payer]', 'Leave empty if the firm was never a VAT payer');

        $this->client->request('POST', $this->url('/run'), ['_token' => $this->token(), 'remind_from' => '2026-11-02', 'vat_payer_from' => '2022-01-01']);
        self::assertResponseRedirects($this->url());
        self::assertEquals(new ImportOptions(new \DateTimeImmutable('2022-01-01')), $this->latestJob()->options());
        $this->messages()->run();
        $job = $this->latestJob();
        self::assertSame(IdokladJobState::Done, $job->state(), (string) $job->error());
        $stored = $this->fresh(fn (): ?IdokladImportRun => $this->em()->find(IdokladImportRun::class, (string) $job->resultId()));
        self::assertEquals(new ImportOptions(new \DateTimeImmutable('2022-01-01')), $stored?->options(), 'The run keeps the day it ran with.');
    }

    public function testAnEmptyVatPayerDayIsNeverAPayerAndAWrongOneIsRefused(): void
    {
        $this->connectedFirm();
        $this->storeRun(ImportMode::Check, new ImportReport(), $this->pull());

        $this->client->request('POST', $this->url('/run'), ['_token' => $this->token(), 'remind_from' => '2026-11-02', 'vat_payer_from' => '2026-02-30']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#vat_payer_from-error');
        self::assertSelectorNotExists('#remind_from-error');
        self::assertSame('2026-02-30', $this->client->getCrawler()->filter('#vat_payer_from')->attr('value'));
        self::assertSame([], $this->messages()->pending());

        $this->client->request('POST', $this->url('/run'), ['_token' => $this->token(), 'remind_from' => '2026-11-02', 'vat_payer_from' => '']);
        self::assertResponseRedirects($this->url());
        self::assertEquals(new ImportOptions(null), $this->latestJob()->options());
    }

    public function testARunNeedsADayForReminders(): void
    {
        $this->connectedFirm();
        $this->storeRun(ImportMode::Check, new ImportReport(), $this->pull());

        $this->client->request('POST', $this->url('/run'), ['_token' => $this->token(), 'remind_from' => '2026-13-40']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#remind_from-error');
        self::assertSelectorNotExists('#vat_payer_from-error');
        self::assertSame('', $this->client->getCrawler()->filter('#vat_payer_from')->attr('value'), 'Left out of the form, the day of the check, which named none.');
        self::assertSame([], $this->messages()->pending());
    }

    public function testAnUndoRefusedNamesItsBlockers(): void
    {
        $run = $this->importedRun();
        $this->em()->getConnection()->executeStatement('UPDATE invoice SET version = 2');

        $this->client->request('GET', $this->url('/runs/'.$run));
        self::assertSelectorExists('form[action$="/undo"][data-controller="confirm-submit"][data-confirm-title]');
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

    public function testARunSumsByYearTheInvoicesStoredInForesi(): void
    {
        $run = $this->importedRun();
        $this->client->request('GET', $this->url('/runs/'.$run));
        $years = $this->client->getCrawler()->filter('tr[data-id="y2026"] td')->each(static fn ($cell): string => trim($cell->text()));
        self::assertSame(['2026', '1', "2,000.00\u{a0}CZK", '1', "2,000.00\u{a0}CZK", "0.00\u{a0}CZK", '0', '0'], $years);

        $this->em()->getConnection()->executeStatement('UPDATE invoice SET payable_minor = 199900');
        $this->client->request('GET', $this->url('/runs/'.$run));

        $years = $this->client->getCrawler()->filter('tr[data-id="y2026"] td')->each(static fn ($cell): string => trim($cell->text()));
        self::assertSame(['2026', '1', "2,000.00\u{a0}CZK", '1', "1,999.00\u{a0}CZK", "1.00\u{a0}CZK", '0', '0'], $years, 'What is stored, not what the report says.');
    }

    public function testAnUndoTakesNothingBackWhenItsTemplatesFail(): void
    {
        $run = $this->importedRun();
        $template = $this->template($run, 7, false);
        $container = self::getContainer();
        $real = $container->get(RecurringInvoices::class);
        $failing = new readonly class($real) implements RecurringInvoices {
            public function __construct(private RecurringInvoices $real)
            {
            }

            public function get(string $id): RecurringInvoice
            {
                return $this->real->get($id);
            }

            public function lock(string $id): RecurringInvoice
            {
                return $this->real->lock($id);
            }

            public function current(string $id): RecurringInvoice
            {
                return $this->real->current($id);
            }

            public function lockDuringWrite(string $id): RecurringInvoice
            {
                return $this->real->lockDuringWrite($id);
            }

            public function occurrenceIssued(string $id, \DateTimeImmutable $occurrence): bool
            {
                return $this->real->occurrenceIssued($id, $occurrence);
            }

            public function add(RecurringInvoice $template): void
            {
                $this->real->add($template);
            }

            public function remove(RecurringInvoice $template): void
            {
                throw new \RuntimeException('Example failure of a template.');
            }

            public function trashedBefore(\DateTimeImmutable $moment): array
            {
                return $this->real->trashedBefore($moment);
            }

            public function lines(RecurringInvoice $template): array
            {
                return $this->real->lines($template);
            }

            public function addLine(RecurringInvoiceLine $line): void
            {
                $this->real->addLine($line);
            }

            public function removeLine(RecurringInvoiceLine $line): void
            {
                $this->real->removeLine($line);
            }

            public function due(\DateTimeImmutable $today): array
            {
                return $this->real->due($today);
            }

            public function page(RecurringFilter $filter): RecurringPage
            {
                return $this->real->page($filter);
            }

            public function failing(): int
            {
                return $this->real->failing();
            }

            public function inReview(): int
            {
                return $this->real->inReview();
            }
        };
        $undo = new UndoIdokladImport($container->get(UndoImportedDocuments::class), $container->get(IdokladLinks::class), $container->get(IdokladImportRuns::class), $failing, $container->get(ClockInterface::class));

        try {
            $this->fresh(fn (): array => $undo->undo($this->em()->find(IdokladImportRun::class, $run) ?? self::fail('The run is stored.')));
            self::fail('The failure of a template travels on.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Example failure of a template.', $failure->getMessage());
        }

        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM invoice'), 'The documents come back with the template.');
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM idoklad_link WHERE resource = 'IssuedInvoices'"));
        self::assertSame([$template], $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM recurring_invoice'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM idoklad_import_run WHERE undone_at IS NOT NULL'));
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
        $id = $this->fresh(function () use ($touched, $externalId): string {
            $customer = new Party(PartyKind::Person, 'Example Customer', null, null, '');
            $this->em()->persist($customer);
            $content = new RecurringContent(null, $customer, PaymentMethod::Cash, null, '', '', 'Hosting', '', '', DocumentLanguage::English, new Recurrence(new \DateTimeImmutable('2026-12-01'), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null), TaxPointRule::IssueDate, 14, RecurringDelivery::Review, 'Hosting '.$externalId);
            $template = new RecurringInvoice(Currency::of('CZK'), $content, null, new \DateTimeImmutable('2026-11-06 10:00:00'), true, 'idoklad:'.$externalId);
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
        $this->fresh(function () use ($runId, $externalId, $targetId): void {
            $this->em()->persist(new IdokladLink(IdokladResource::RecurringInvoices, $externalId, $targetId, $runId, new \DateTimeImmutable('2026-11-06 10:00:00')));
            $this->em()->flush();
        });
    }

    private function answer(string $url): ResponseInterface
    {
        if (str_starts_with($url, 'https://identity.idoklad.cz/')) {
            return 200 === $this->tokenStatus ? new JsonMockResponse(['access_token' => 'example-token', 'expires_in' => 3600]) : new MockResponse('', ['http_code' => $this->tokenStatus]);
        }
        if ($this->unexpected) {
            throw new \LogicException('Example unexpected failure.');
        }
        if (200 !== $this->apiStatus) {
            return new MockResponse('', ['http_code' => $this->apiStatus]);
        }
        $items = str_contains($url, '/v3/Contacts?') ? [['Id' => 501, 'CompanyName' => 'Example Trading s.r.o.', 'Metadata' => ['DateLastChange' => '2026-11-01T10:00:00.000']]] : [];

        return new JsonMockResponse(['Data' => ['Items' => $items, 'TotalItems' => \count($items), 'TotalPages' => 1], 'IsSuccess' => true, 'StatusCode' => 200]);
    }

    private function signedInFirm(): void
    {
        $this->browser->seed();
        $this->browser->signIn();
        $this->firm = $this->firm();
    }

    private function connectedFirm(): void
    {
        $this->signedInFirm();
        $this->fresh(fn () => self::getContainer()->get(ConnectIdoklad::class)->connect('example-client', self::SECRET));
    }

    private function firm(): LegalEntity
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em()->persist($firm);
        $this->em()->flush();

        return $firm;
    }

    private function pull(): IdokladPull
    {
        return $this->fresh(function (): IdokladPull {
            $pull = new IdokladPull(new \DateTimeImmutable('2026-11-06 09:00:00'));
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
        $this->fresh(function () use ($resource, $id, $payload): void {
            $this->em()->persist(new IdokladRecord($resource, $id, $payload, new \DateTimeImmutable('2026-11-06 09:01:00')));
            $this->em()->flush();
        });
    }

    private function storeRun(ImportMode $mode, ImportReport $report, IdokladPull $pull): void
    {
        $this->fresh(function () use ($mode, $report, $pull): void {
            $run = new IdokladImportRun($mode, new \DateTimeImmutable('2026-11-06 10:00:00'));
            $run->readFrom($pull);
            $run->finish(new \DateTimeImmutable('2026-11-06 10:01:00'), $report);
            $this->em()->persist($run);
            $this->em()->flush();
        });
    }

    private function latestJob(): IdokladJob
    {
        return $this->fresh(fn (): IdokladJob => $this->em()->getRepository(IdokladJob::class)->findOneBy([], ['requestedAt' => 'DESC', 'id' => 'DESC']) ?? self::fail('No job was asked for.'));
    }

    private function reminders(): ?FirmReminders
    {
        return $this->fresh(fn (): ?FirmReminders => $this->em()->getRepository(FirmReminders::class)->findOneBy([]));
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function fresh(callable $work): mixed
    {
        $this->em()->clear();
        try {
            return $work();
        } finally {
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
