<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\DocumentExports;
use App\Invoicing\Application\EditInvoice;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MarkDelivered;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\SendInvoice;
use App\Invoicing\Application\SendMark;
use App\Invoicing\Application\SendRequest;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\ExportState;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\SendPurpose;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Application\FirmBankAccounts;
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
use App\Tests\Import\Support\RunMessages;
use App\Tests\Party\Support\FirmAccounts;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InvoiceListControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private TenantId $tenant;
    private LegalEntity $firm;
    private Series $series;
    private Party $customer;
    private ?string $token = null;

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

    public function testAnEmptyListOffersANewInvoice(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Invoices');
        self::assertCount(0, $crawler->filter('nav[aria-label="Invoice lists"]'));
        $new = $crawler->filter('main a[href="/invoices/new"]');
        self::assertGreaterThan(0, $new->count());
        self::assertSame('false', $new->first()->attr('data-turbo'));
    }

    public function testTheTabsCountWhatTheyHold(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-11-15');
        $this->issue('2026-11-02', '2026-12-15');
        $trashed = $this->issue('2026-11-03', '2026-11-04');
        $this->trashed($trashed, 'Wrong customer');

        $crawler = $this->client->request('GET', '/invoices');

        self::assertResponseIsSuccessful();
        self::assertSame(['All 2', 'Paid 0', 'Unpaid 2', 'Overdue 1', 'Trash 1'], $this->tabs($crawler));
        self::assertCount(2, $crawler->filter('tbody tr'));

        $crawler = $this->client->request('GET', '/invoices?tab=overdue');
        self::assertSame(['Overdue 1'], $crawler->filter('nav[aria-label="Invoice lists"] a[aria-current="page"]')->each(static fn (Crawler $tab): string => (string) preg_replace('/(\D)(\d)/', '$1 $2', $tab->text())));
        self::assertSame(['20260001'], $this->numbers($crawler));

        $crawler = $this->client->request('GET', '/invoices?tab=trash');
        self::assertSame(['20260003'], $this->numbers($crawler));
    }

    public function testAnUnknownTabShowsAll(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-15');

        $crawler = $this->client->request('GET', '/invoices?tab=settled');

        self::assertSame(['All 1'], $crawler->filter('nav[aria-label="Invoice lists"] a[aria-current="page"]')->each(static fn (Crawler $tab): string => (string) preg_replace('/(\D)(\d)/', '$1 $2', $tab->text())));
    }

    public function testTheFirmFilterNarrowsListAndCounts(): void
    {
        $this->signedIn();
        $second = $this->secondFirm();
        $this->issue('2026-11-01', '2026-11-05');
        $other = $this->issue('2026-11-02', '2026-11-05', firm: $second);

        $crawler = $this->client->request('GET', '/invoices', ['firm' => $second->id()]);

        self::assertSame([$other->number()], $this->numbers($crawler));
        self::assertSame(['All 1', 'Paid 0', 'Unpaid 1', 'Overdue 1', 'Trash 0'], $this->tabs($crawler));
        self::assertSame($second->id(), $crawler->filter('select[name="firm"] option[selected]')->attr('value'));
        // The tabs and the sort keep the firm.
        self::assertStringContainsString('firm='.$second->id(), (string) $crawler->filter('nav[aria-label="Invoice lists"] a')->eq(1)->attr('href'));
    }

    public function testAFirmOfAnotherTenantIsNoFilter(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-11-05');
        $foreign = $this->foreignFirm();

        $crawler = $this->client->request('GET', '/invoices', ['firm' => $foreign->id()]);

        self::assertResponseIsSuccessful();
        self::assertSame(['20260001'], $this->numbers($crawler));
    }

    public function testOverdueUsesTheCountryToday(): void
    {
        $this->signedIn();
        $this->issue('2026-10-01', '2026-10-31');
        // 23:30 UTC on 31 October is already 1 November in Prague.
        Clock::set(new MockClock('2026-10-31 23:30:00', 'UTC'));

        $crawler = $this->client->request('GET', '/invoices?tab=overdue');
        self::assertSame(['20260001'], $this->numbers($crawler));

        Clock::set(new MockClock('2026-10-31 22:30:00', 'UTC'));
        $crawler = $this->client->request('GET', '/invoices?tab=overdue');
        self::assertSame([], $this->numbers($crawler));
    }

    public function testADueDateOfTodayIsNotOverdueAndARowShowsWhatIs(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-11-20');
        $this->issue('2026-11-02', '2026-11-19');

        $store = $this->rows(new InvoiceFilter(null, 'all', '', '-issueDate', 1));

        self::assertSame([['20260002', true], ['20260001', false]], array_map(static fn (InvoiceRow $row): array => [$row->number, $row->overdue], $store));
        $crawler = $this->client->request('GET', '/invoices');
        self::assertStringContainsString('Overdue', $crawler->filter('tbody tr')->first()->text());
    }

    public function testATrashedInvoiceIsNotOverdue(): void
    {
        $this->signedIn();
        $trashed = $this->issue('2026-11-01', '2026-11-02');
        $this->trashed($trashed, 'Wrong customer');

        $rows = $this->rows(new InvoiceFilter(null, 'trash', '', '-issueDate', 1));

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->overdue);
        self::assertTrue($rows[0]->trashed);
    }

    public function testTextMatchesNumberCustomerSymbolAndDescription(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01', description: 'Quarterly audit');
        $named = $this->issue('2026-11-02', '2026-12-01', customer: $this->customerNamed('Žluťoučký kůň a.s.'));
        $this->issue('2026-11-03', '2026-12-01', description: 'Práce na Řádu');

        foreach (['quarterly' => ['20260001'], 'ŽLUŤOUČKÝ' => [$named->number()], 'žluť' => [$named->number()], 'ŘÁDU' => ['20260003'], '20260003' => ['20260003'], '2026-0003' => ['20260003'], 'nothing' => []] as $text => $expected) {
            $crawler = $this->client->request('GET', '/invoices', ['q' => $text]);
            self::assertSame($expected, $this->numbers($crawler), (string) $text);
        }
    }

    public function testTextMatchesTheVariableSymbol(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01', variableSymbol: '7777');
        $this->issue('2026-11-02', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices', ['q' => '7777']);

        self::assertSame(['20260001'], $this->numbers($crawler));
    }

    public function testASearchWildcardIsPlainText(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01', description: 'Half done');
        $this->issue('2026-11-02', '2026-12-01', description: '100% done');

        $crawler = $this->client->request('GET', '/invoices', ['q' => '%']);

        self::assertSame(['20260002'], $this->numbers($crawler));
    }

    public function testASearchFindingNothingOffersToReset(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices', ['q' => 'nothing']);

        self::assertSelectorTextContains('main', 'No invoice matches');
        self::assertCount(1, $crawler->filter('main a[href="/invoices"]'));
    }

    public function testTheTabsShowWhatASearchFinds(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01', description: 'Findable');
        $this->issue('2026-11-02', '2026-12-01', description: 'Other');

        $crawler = $this->client->request('GET', '/invoices', ['q' => 'findable']);

        self::assertSame(['All 1', 'Paid 0', 'Unpaid 1', 'Overdue 0', 'Trash 0'], $this->tabs($crawler));
    }

    public function testTheNewestDayComesFirstAndWithinItTheHighestNumber(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01');
        $this->issue('2026-11-02', '2026-12-01');
        $this->issue('2026-11-02', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices');

        self::assertSame(['20260003', '20260002', '20260001'], $this->numbers($crawler));
    }

    public function testASortableColumnSorts(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-05', price: '300.00');
        $this->issue('2026-11-02', '2026-12-01', price: '100.00');
        $this->issue('2026-11-03', '2026-12-03', price: '200.00');

        self::assertSame(['20260002', '20260003', '20260001'], $this->numbers($this->client->request('GET', '/invoices?sort=dueDate')));
        self::assertSame(['20260001', '20260003', '20260002'], $this->numbers($this->client->request('GET', '/invoices?sort=-dueDate')));
        self::assertSame(['20260002', '20260003', '20260001'], $this->numbers($this->client->request('GET', '/invoices?sort=payable')));
        self::assertSame(['20260001', '20260002', '20260003'], $this->numbers($this->client->request('GET', '/invoices?sort=number')));
        self::assertSame(['20260003', '20260002', '20260001'], $this->numbers($this->client->request('GET', '/invoices?sort=nonsense')));
    }

    public function testCustomerSortIgnoresCase(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01', customer: $this->customerNamed('beta s.r.o.'));
        $this->issue('2026-11-02', '2026-12-01', customer: $this->customerNamed('Alfa s.r.o.'));
        $this->issue('2026-11-03', '2026-12-01', customer: $this->customerNamed('Gama s.r.o.'));

        self::assertSame(['20260002', '20260001', '20260003'], $this->numbers($this->client->request('GET', '/invoices?sort=customer')));
    }

    public function testTheFirmColumnAppearsWithTwoFirms(): void
    {
        $this->signedIn();
        $second = $this->secondFirm();
        $this->issue('2026-11-01', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices');
        self::assertNotContains('Firm', $this->headers($crawler));
        self::assertCount(0, $crawler->filter('select[name="firm"]'));

        $this->issue('2026-11-02', '2026-12-01', firm: $second);

        $crawler = $this->client->request('GET', '/invoices');
        self::assertContains('Firm', $this->headers($crawler));
        self::assertStringContainsString('Example Second s.r.o.', $crawler->filter('tbody tr')->first()->text());
        self::assertCount(1, $crawler->filter('select[name="firm"]'));
    }

    public function testAFirmStaysInTheColumnOnceItsOnlyInvoiceIsInTheTrash(): void
    {
        $this->signedIn();
        $second = $this->secondFirm();
        $this->issue('2026-11-01', '2026-12-01');
        $this->trashed($this->issue('2026-11-02', '2026-12-01', firm: $second), 'Wrong firm');

        $crawler = $this->client->request('GET', '/invoices');

        self::assertContains('Firm', $this->headers($crawler));
    }

    public function testARowShowsItsFacts(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices');

        $row = $crawler->filter('tbody tr')->first();
        self::assertSame('20260001', $row->filter('a[data-row-link]')->text());
        self::assertSame('false', $row->filter('a[data-row-link]')->attr('data-turbo'));
        self::assertStringEndsWith('/edit', (string) $row->filter('a[data-row-link]')->attr('href'));
        self::assertStringContainsString('Example Customer a.s.', $row->text());
        self::assertStringContainsString('2026-11-01', $row->text());
        self::assertStringContainsString('2026-12-01', $row->text());
        self::assertStringContainsString($this->nbsp('1,210.00 Kč'), $row->text());
    }

    public function testEachStateShowsItsBadge(): void
    {
        $this->signedIn();
        $issued = $this->issue('2026-11-01', '2026-12-01');
        $overdue = $this->issue('2026-11-02', '2026-11-10');
        $partly = $this->issue('2026-11-03', '2026-12-01');
        $partlyOverdue = $this->issue('2026-11-04', '2026-11-10');
        $paid = $this->issue('2026-11-05', '2026-11-10');
        $overpaid = $this->issue('2026-11-06', '2026-12-01');
        $this->pay($partly, '210.00');
        $this->pay($partlyOverdue, '1000.00');
        $this->pay($paid, '1210.00');
        $this->pay($overpaid, '1300.00');

        $rows = $this->client->request('GET', '/invoices')->filter('tbody tr');

        $badges = [];
        foreach ($rows as $row) {
            $row = new Crawler($row, 'http://localhost/');
            $badges[$row->filter('a[data-row-link]')->text()] = $row->filter('td')->eq(5)->filter('span')->first()->text();
        }
        self::assertSame([
            $overpaid->number() => 'Overpaid',
            $paid->number() => 'Paid',
            $partlyOverdue->number() => 'Overdue · 210.00 Kč left to pay',
            $partly->number() => 'Partly paid · 1,000.00 Kč left to pay',
            $overdue->number() => 'Overdue',
            $issued->number() => 'Issued',
        ], array_map($this->nbspless(...), $badges));
        $partlyRow = $rows->reduce(static fn (Crawler $row): bool => str_contains($row->text(), $partly->number()));
        self::assertSame('1,000.00 Kč left to pay', $this->nbspless((string) $partlyRow->filter('td')->eq(5)->filter('span')->first()->attr('title')));
        self::assertStringContainsString('Partly paid · 1,000.00 Kč left to pay', $this->nbspless($partlyRow->filter('[data-row-secondary]')->text()));
    }

    public function testThePaidTabListsPaidAndOverpaidOnly(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01');
        $partly = $this->issue('2026-11-02', '2026-12-01');
        $paid = $this->issue('2026-11-03', '2026-11-10');
        $overpaid = $this->issue('2026-11-04', '2026-12-01');
        $this->pay($partly, '100.00');
        $this->pay($paid, '1210.00');
        $this->pay($overpaid, '1210.01');

        $crawler = $this->client->request('GET', '/invoices?tab=paid');

        self::assertSame([$overpaid->number(), $paid->number()], $this->numbers($crawler));
        self::assertSame(['All 4', 'Paid 2', 'Unpaid 2', 'Overdue 0', 'Trash 0'], $this->tabs($crawler));
        self::assertSame(['20260002', '20260001'], $this->numbers($this->client->request('GET', '/invoices?tab=unpaid')));
    }

    public function testPayIsOfferedUntilTheInvoiceIsPaid(): void
    {
        $this->signedIn();
        $unpaid = $this->issue('2026-11-01', '2026-12-01');
        $paid = $this->issue('2026-11-02', '2026-12-01');
        $this->pay($paid, '1210.00');

        $rows = $this->client->request('GET', '/invoices')->filter('tbody tr');

        $unpaidRow = $rows->reduce(static fn (Crawler $row): bool => str_contains($row->text(), $unpaid->number()));
        $pay = $unpaidRow->filter('a[aria-label="Pay"]');
        self::assertSame('/invoices/'.$unpaid->id().'/pay', $pay->attr('href'));
        self::assertNotNull($pay->attr('data-dialog'));
        self::assertSame('dialog', $pay->attr('aria-haspopup'));
        self::assertCount(1, $unpaidRow->filter('[role="menu"] a[data-phone-only][href="/invoices/'.$unpaid->id().'/pay"]'), 'on a phone it is in the menu');
        $paidRow = $rows->reduce(static fn (Crawler $row): bool => str_contains($row->text(), $paid->number()));
        self::assertCount(0, $paidRow->filter('a[href$="/pay"]'));
    }

    public function testThePaidAndUnpaidTabsSayWhyTheyAreEmpty(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        $this->client->request('GET', '/invoices?tab=paid');
        self::assertSelectorTextContains('main', 'Nothing is paid yet');
        self::assertSelectorTextContains('main', 'Paid invoices show here once you record a payment.');

        $this->pay($invoice, '1210.00');
        $this->client->request('GET', '/invoices?tab=unpaid');
        self::assertSelectorTextContains('main', 'Everything is paid');
        self::assertSelectorTextNotContains('main', 'No invoices here');
    }

    public function testAnotherTenantSeesNothing(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $other = $this->foreignInvoice();

        $crawler = $this->client->request('GET', '/invoices');

        self::assertSame([$invoice->number()], $this->numbers($crawler));
        self::assertSame(['All 1', 'Paid 0', 'Unpaid 1', 'Overdue 0', 'Trash 0'], $this->tabs($crawler));

        $this->client->request('GET', '/invoices/'.$other->id().'/history');
        self::assertResponseStatusCodeSame(404);
        foreach (['trash', 'restore', 'delivered', 'clear-export'] as $path) {
            $this->client->request('POST', '/invoices/'.$other->id().'/'.$path, ['_token' => $this->token(), 'reason' => 'Mine']);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testOnlyStaffSeeInvoices(): void
    {
        $this->signedIn(Role::Client);
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        foreach ([['GET', '/invoices'], ['GET', '/invoices/'.$invoice->id().'/history'], ['GET', '/invoices/'.$invoice->id().'/copy'], ['GET', '/invoices/'.$invoice->id().'/trash'], ['POST', '/invoices/'.$invoice->id().'/trash'], ['POST', '/invoices/'.$invoice->id().'/restore'], ['POST', '/invoices/'.$invoice->id().'/delivered'], ['POST', '/invoices/'.$invoice->id().'/clear-export']] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        self::assertCount(0, $this->client->request('GET', '/contacts')->filter('#sidebar a[href="/invoices"]'));
    }

    public function testActionsNeedAToken(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        foreach (['trash', 'restore', 'delivered', 'clear-export'] as $path) {
            $this->client->request('POST', '/invoices/'.$invoice->id().'/'.$path, ['_token' => 'wrong', 'reason' => 'Mine']);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testTheRowOffersItsActions(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        $row = $this->client->request('GET', '/invoices')->filter('tbody tr')->first();

        self::assertSame('false', $row->filter('a[aria-label="Edit"]')->attr('data-turbo'));
        self::assertSame('/invoices/'.$invoice->id().'/edit', $row->filter('a[aria-label="Edit"]')->attr('href'));
        self::assertSame('false', $row->filter('a[aria-label="PDF"]')->attr('data-turbo'));
        self::assertSame('/invoices/'.$invoice->id().'/pdf', $row->filter('a[aria-label="PDF"]')->attr('href'));
        self::assertSame(['Send', 'Copy', 'Recurring invoice', 'Issue a corrective document', 'Mark as delivered', 'History', 'Delete'], $this->menu($row));
        self::assertSame('false', $row->filter('a[href="/invoices/'.$invoice->id().'/copy"]')->attr('data-turbo'));
        self::assertCount(1, $row->filter('form[action="/invoices/'.$invoice->id().'/delivered"][data-turbo-confirm]'));
    }

    public function testThePdfOpensInTheBrowser(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        $this->client->request('GET', '/invoices/'.$invoice->id().'/pdf');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('inline; filename='.$invoice->number().'-isdoc.pdf', $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function testATrashedDocumentKeepsItsPdf(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $this->trashed($invoice, 'Wrong customer');

        $this->client->request('GET', '/invoices/'.$invoice->id().'/pdf');

        self::assertResponseIsSuccessful();
    }

    public function testAPdfOfAnUnsupportedCountrySaysSo(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $this->scoped(fn (): int => (int) $this->em()->getConnection()->executeStatement("UPDATE invoice SET issuer_address_country_code = 'XX' WHERE id = :id", ['id' => $invoice->id()]));

        $this->client->request('GET', '/invoices/'.$invoice->id().'/pdf');

        self::assertResponseRedirects('/invoices');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', $invoice->number());
    }

    public function testAnotherTenantsPdfIs404(): void
    {
        $this->signedIn();
        $other = $this->foreignInvoice();

        $this->client->request('GET', '/invoices/'.$other->id().'/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    public function testASubcontractorGetsNoPdf(): void
    {
        $this->signedIn(Role::Client);
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        $this->client->request('GET', '/invoices/'.$invoice->id().'/pdf');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTrashAsksForAReason(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/trash');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '20260001');
        self::assertCount(1, $crawler->filter('textarea[name="reason"]'));

        $this->client->request('POST', '/invoices/'.$invoice->id().'/trash', ['_token' => $this->token(), 'reason' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Say why');
        self::assertFalse($this->stored($invoice)->isTrashed());

        $this->client->request('POST', '/invoices/'.$invoice->id().'/trash', ['_token' => $this->token(), 'reason' => 'Wrong customer']);
        self::assertResponseRedirects('/invoices');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Invoice 20260001 is in the trash.');
        self::assertSame([], $this->numbers($crawler));
        $stored = $this->stored($invoice);
        self::assertTrue($stored->isTrashed());
        self::assertSame('Wrong customer', $stored->deletedReason());
    }

    public function testSendAndDeleteOpenInADialogAndMarkingDeliveredNamesItsOutcome(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        $row = $this->client->request('GET', '/invoices')->filter('tbody tr')->first();

        foreach (['send', 'trash'] as $action) {
            $item = $row->filter('a[role="menuitem"][href^="/invoices/'.$invoice->id().'/'.$action.'"]');
            self::assertNotNull($item->attr('data-dialog'), $action.' opens in place.');
            self::assertSame('dialog', $item->attr('aria-haspopup'));
        }
        self::assertNull($row->filter('a[role="menuitem"][href="/invoices/'.$invoice->id().'/history"]')->attr('data-dialog'));
        $delivered = $row->filter('form[action="/invoices/'.$invoice->id().'/delivered"]')->first();
        self::assertSame('Mark invoice 20260001 as delivered?', $delivered->attr('data-turbo-confirm'));
        self::assertSame('A delivered invoice can no longer be deleted.', $delivered->attr('data-confirm-text'));
        self::assertSame('Mark as delivered', $delivered->attr('data-confirm-accept'));
    }

    public function testTrashInADialogAsksForTheReasonAndMovesItToTheTrash(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $token = $this->token();
        $dialog = ['HTTP_X_FORESI_DIALOG' => '1'];

        $this->client->request('GET', '/invoices/'.$invoice->id().'/trash?return=tab%3Dunpaid', server: $dialog);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('<dialog', ltrim($html));
        self::assertStringNotContainsString('<html', $html);
        $crawler = new Crawler($html);
        self::assertSame('Delete invoice 20260001', $crawler->filter('dialog h2')->text());
        self::assertCount(1, $crawler->filter('dialog form#trash-form textarea[name="reason"][required]'));
        // A danger dialog: Cancel has the focus; the verb sits last and submits the form from the footer.
        $cancel = $crawler->filter('footer button[data-action="dialog#dismiss"]');
        self::assertNotNull($cancel->attr('autofocus'));
        $submit = $crawler->filter('footer button[type="submit"][form="trash-form"]');
        self::assertSame('Move to the trash', trim($submit->text()));
        self::assertStringContainsString('badge-red-text', (string) $submit->attr('class'));
        self::assertStringContainsString('max-w-[400px]', (string) $crawler->filter('dialog')->attr('class'));

        $this->client->request('POST', '/invoices/'.$invoice->id().'/trash', ['_token' => $token, 'reason' => ' ', 'return' => 'tab=unpaid'], server: $dialog);
        self::assertResponseStatusCodeSame(422);
        $refused = new Crawler((string) $this->client->getResponse()->getContent());
        self::assertSame('true', $refused->filter('textarea[name="reason"]')->attr('aria-invalid'));
        self::assertStringContainsString('Say why', $refused->filter('dialog')->text());
        self::assertFalse($this->stored($invoice)->isTrashed());

        $this->client->request('POST', '/invoices/'.$invoice->id().'/trash', ['_token' => $token, 'reason' => 'Wrong customer', 'return' => 'tab=unpaid'], server: $dialog);
        self::assertResponseIsSuccessful();
        self::assertSame(['location' => '/invoices?tab=unpaid'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $stored = $this->stored($invoice);
        self::assertTrue($stored->isTrashed());
        self::assertSame('Wrong customer', $stored->deletedReason());
        // The message waits for the page the dialog leads to.
        $this->client->request('GET', '/invoices?tab=unpaid');
        self::assertSelectorTextContains('main', 'Invoice 20260001 is in the trash.');
    }

    public function testAReasonTooLongIsRefused(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        $this->client->request('POST', '/invoices/'.$invoice->id().'/trash', ['_token' => $this->token(), 'reason' => str_repeat('x', 501)]);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->stored($invoice)->isTrashed());
    }

    public function testTheTrashShowsWhoWhenAndWhy(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $this->client->request('POST', '/invoices/'.$invoice->id().'/trash', ['_token' => $this->token(), 'reason' => 'Wrong customer']);

        $crawler = $this->client->request('GET', '/invoices?tab=trash');

        $row = $crawler->filter('tbody tr')->first();
        self::assertContains('Deleted by', $this->headers($crawler));
        self::assertStringContainsString('Example Company', $row->text());
        self::assertStringContainsString('2026-11-20 13:00', $row->text());
        self::assertStringContainsString('Wrong customer', $row->text());
    }

    public function testATrashedRowOffersRestoreAndNoEditOrDelete(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $this->trashed($invoice, 'Wrong customer');

        $row = $this->client->request('GET', '/invoices?tab=trash')->filter('tbody tr')->first();

        self::assertCount(2, $row->filter('form[action="/invoices/'.$invoice->id().'/restore"]'), 'In the row, and in the menu of a phone.');
        self::assertCount(0, $row->filter('a[aria-label="Edit"]'));
        self::assertSame(['Copy', 'History'], $this->menu($row));
    }

    public function testDeliveredOffersNoDelete(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $this->delivered($invoice);

        $crawler = $this->client->request('GET', '/invoices');

        $row = $crawler->filter('tbody tr')->first();
        self::assertStringContainsString('Delivered', $row->text());
        self::assertSame(['Send', 'Copy', 'Recurring invoice', 'Issue a corrective document', 'History'], $this->menu($row));
        self::assertCount(1, $row->filter('a[aria-label="Edit"]'));
    }

    public function testDeleteOnADeliveredDocumentIsRefusedClearly(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $token = $this->token();
        $this->delivered($invoice);

        $this->client->request('POST', '/invoices/'.$invoice->id().'/trash', ['_token' => $token, 'reason' => 'Mine']);
        self::assertResponseRedirects('/invoices');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'A delivered document cannot be deleted.');
        self::assertFalse($this->stored($invoice)->isTrashed());

        $this->client->request('GET', '/invoices/'.$invoice->id().'/trash');
        self::assertResponseRedirects('/invoices');
    }

    public function testDeleteTwiceSaysItIsThereAlready(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $this->trashed($invoice, 'Wrong customer');

        $this->client->request('POST', '/invoices/'.$invoice->id().'/trash', ['_token' => $this->token(), 'reason' => 'Again']);

        self::assertResponseRedirects('/invoices');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'This invoice is in the trash already.');
    }

    public function testRestoreBringsItBack(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $this->trashed($invoice, 'Wrong customer');

        $this->client->request('POST', '/invoices/'.$invoice->id().'/restore', ['_token' => $this->token()]);

        self::assertResponseRedirects('/invoices');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Invoice 20260001 is back in the list.');
        self::assertSame(['20260001'], $this->numbers($crawler));
        self::assertFalse($this->stored($invoice)->isTrashed());
    }

    public function testRestoringWhatIsNotInTheTrashClaimsNothing(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        $this->client->request('POST', '/invoices/'.$invoice->id().'/restore', ['_token' => $this->token()]);

        self::assertResponseRedirects('/invoices');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'This invoice is not in the trash.');
        self::assertStringNotContainsString('is back in the list', $crawler->filter('main')->text());
    }

    public function testMarkingDeliveredRemovesDeleteAndCannotHappenInTheTrash(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        $this->client->request('POST', '/invoices/'.$invoice->id().'/delivered', ['_token' => $this->token()]);
        self::assertResponseRedirects('/invoices');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Invoice 20260001 is marked as delivered.');
        self::assertTrue($this->stored($invoice)->isDelivered());

        $other = $this->issue('2026-11-02', '2026-12-01');
        $this->trashed($other, 'Wrong customer');
        $this->client->request('POST', '/invoices/'.$other->id().'/delivered', ['_token' => $this->token()]);
        self::assertResponseRedirects('/invoices');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'This invoice is in the trash. Restore it first.');
        self::assertFalse($this->stored($other)->isDelivered());
    }

    public function testActionsReturnToTheListTheyCameFrom(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-11-02');
        $crawler = $this->client->request('GET', '/invoices?tab=overdue&q=2026');

        $return = $crawler->filter('tbody tr form[action$="/delivered"] input[name="return"]')->attr('value');
        self::assertSame('tab=overdue&q=2026', $return);

        $this->client->request('POST', '/invoices/'.$invoice->id().'/delivered', ['_token' => $this->token(), 'return' => $return]);

        self::assertResponseRedirects('/invoices?tab=overdue&q=2026');
    }

    public function testAReturnAddressStaysOnTheList(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-11-02');

        $this->client->request('POST', '/invoices/'.$invoice->id().'/delivered', ['_token' => $this->token(), 'return' => '//example.com/x?tab=trash&evil=1']);

        self::assertResponseRedirects('/invoices');
    }

    public function testHistoryShowsWhoChangedWhatIncludingLines(): void
    {
        $this->signedIn();
        $this->saveThroughTheForm();
        $invoice = $this->onlyInvoice();
        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/edit');
        $form = $crawler->filter('#invoice-save')->form();
        $this->client->submit($form, ['description' => 'Changed', 'lines[0][price]' => '2000,00', '_action' => 'save']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/invoices/'.$invoice->id().'/trash', ['_token' => $this->token(), 'reason' => 'Wrong customer']);
        $this->client->request('POST', '/invoices/'.$invoice->id().'/restore', ['_token' => $this->token()]);

        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '20260001');
        $items = $crawler->filter('main [data-invoice-history] li')->each(static fn (Crawler $item): string => $item->text());
        self::assertCount(4, $items);
        self::assertStringContainsString('Example Company created the invoice', $items[0]);
        self::assertStringContainsString('Example Company changed the invoice', $items[1]);
        self::assertStringContainsString('Description', $items[1]);
        self::assertStringContainsString('Line 1 (Work): Price', $items[1]);
        self::assertStringContainsString('Example Company moved the invoice to the trash: Wrong customer', $items[2]);
        self::assertStringContainsString('Example Company restored the invoice', $items[3]);
    }

    public function testHistoryNamesLinesAddedAndRemoved(): void
    {
        $this->signedIn();
        $this->saveThroughTheForm();
        $invoice = $this->onlyInvoice();
        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/edit');
        $values = $this->formValues($crawler);
        $values['lines'][0]['text'] = 'Other';
        // The form row keeps its stored line; the second row is new.
        $values['lines'][1] = ['text' => 'Second'] + $values['lines'][0];
        $this->client->request('POST', '/invoices/'.$invoice->id().'/edit', $values);
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/edit');
        $values = $this->formValues($crawler);
        unset($values['lines'][1]);
        $this->client->request('POST', '/invoices/'.$invoice->id().'/edit', $values);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/history');

        $text = implode(' | ', $crawler->filter('main [data-invoice-history] li')->each(static fn (Crawler $item): string => $item->text()));
        self::assertStringContainsString('Line 1: Work → Other', $text);
        self::assertStringContainsString('Line 2 (Second) added', $text);
        self::assertStringContainsString('Line 2 (Second) removed', $text);
    }

    public function testHistorySaysWhenADocumentWasDelivered(): void
    {
        $this->signedIn();
        $this->saveThroughTheForm();
        $invoice = $this->onlyInvoice();
        $this->client->request('POST', '/invoices/'.$invoice->id().'/delivered', ['_token' => $this->token()]);

        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/history');

        self::assertStringContainsString('Example Company marked the invoice as delivered', $crawler->filter('main [data-invoice-history] li')->last()->text());
    }

    public function testTheExportColumnSaysWhatTheAccountantHas(): void
    {
        $this->signedIn();
        $none = $this->issue('2026-11-01', '2026-12-01');
        $exported = $this->issue('2026-11-02', '2026-12-01');
        $changed = $this->issue('2026-11-03', '2026-12-01');
        $this->exported($exported, $changed);
        Clock::set(new MockClock('2026-11-21 08:30:00', 'UTC'));
        $this->changedAfterExport($changed);

        $crawler = $this->client->request('GET', '/invoices');

        self::assertContains('Export', $this->headers($crawler));
        $cells = [];
        foreach ([$none, $exported, $changed] as $invoice) {
            $cell = $crawler->filter('tr[data-id="'.$invoice->id().'"] [data-icon-cell]');
            $cells[$invoice->number()] = 0 === $cell->count() ? null : [$cell->text(), $cell->attr('title'), $cell->attr('data-icon-cell')];
        }
        self::assertSame([
            $none->number() => null,
            $exported->number() => ['Exported', 'Exported for the accountant 2026-11-20 13:00', 'archive'],
            $changed->number() => ['Changed after export', 'Edited 2026-11-21 09:30, after the export for the accountant', 'file-pen-line'],
        ], $cells);
        self::assertStringContainsString('Changed after export', $crawler->filter('tr[data-id="'.$changed->id().'"] [data-row-secondary]')->text());
        self::assertStringNotContainsString('export', $crawler->filter('tr[data-id="'.$none->id().'"] [data-row-secondary]')->text());
    }

    public function testTheExportFilterNarrowsTheListAndTheTabsKeepIt(): void
    {
        $this->signedIn();
        $none = $this->issue('2026-11-01', '2026-12-01');
        $exported = $this->issue('2026-11-02', '2026-12-01');
        $changed = $this->issue('2026-11-03', '2026-12-01');
        $this->exported($exported, $changed);
        $this->changedAfterExport($changed);

        $crawler = $this->client->request('GET', '/invoices?export=exported');
        self::assertSame([$exported->number()], $this->numbers($crawler));
        self::assertSame(['All 1', 'Paid 0', 'Unpaid 1', 'Overdue 0', 'Trash 0'], $this->tabs($crawler));
        self::assertSame('/invoices?tab=unpaid&export=exported', $crawler->filter('nav[aria-label="Invoice lists"] a')->eq(2)->attr('href'));
        self::assertSame('exported', $crawler->filter('select[name="export"] option[selected]')->attr('value'));
        self::assertSame(['', 'not_exported', 'exported', 'changed'], $crawler->filter('select[name="export"] option')->each(static fn (Crawler $option): string => $option->attr('value') ?? ''));
        self::assertSame(['All', 'Not exported', 'Exported', 'Changed after export'], $crawler->filter('select[name="export"] option')->each(static fn (Crawler $option): string => $option->text()));

        self::assertSame([$changed->number()], $this->numbers($this->client->request('GET', '/invoices?export=changed')));
        self::assertSame([$none->number()], $this->numbers($this->client->request('GET', '/invoices?export=not_exported')));
        self::assertSame([$changed->number(), $exported->number(), $none->number()], $this->numbers($this->client->request('GET', '/invoices?export=other')));
        self::assertSame([], $this->rows(new InvoiceFilter(null, 'trash', '', '-issueDate', 1, export: ExportState::Exported)));
    }

    public function testAnExportFilterFindingNothingOffersToReset(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices?export=changed');

        self::assertSame([], $this->numbers($crawler));
        self::assertSelectorTextContains('main', 'No invoice matches');
        self::assertCount(1, $crawler->filter('main a[href="/invoices"]'));
    }

    public function testClearingTheExportStateIsOfferedOnceExportedAndTheNextExportTakesItAgain(): void
    {
        $this->signedIn();
        $none = $this->issue('2026-11-01', '2026-12-01');
        $invoice = $this->issue('2026-11-02', '2026-12-01');
        $this->exported($invoice);
        $crawler = $this->client->request('GET', '/invoices?export=exported');

        self::assertNotContains('Clear export state', $this->menu($this->client->request('GET', '/invoices')->filter('tr[data-id="'.$none->id().'"]')));
        $row = $crawler->filter('tr[data-id="'.$invoice->id().'"]');
        self::assertSame(['Send', 'Copy', 'Recurring invoice', 'Issue a corrective document', 'Mark as delivered', 'Clear export state', 'History', 'Delete'], $this->menu($row));
        $form = $row->filter('form[action="/invoices/'.$invoice->id().'/clear-export"]');
        self::assertCount(0, $form->filter('[data-turbo-confirm]'));
        $return = $form->filter('input[name="return"]')->attr('value');
        self::assertSame('export=exported', $return);

        $this->client->request('POST', '/invoices/'.$invoice->id().'/clear-export', ['_token' => $this->token(), 'return' => $return]);
        self::assertResponseRedirects('/invoices?export=exported');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Document 20260002 has no export state now; the next export takes it.');
        self::assertSame(ExportState::NotExported, $this->stored($invoice)->exportState());

        $this->client->request('POST', '/invoices/'.$invoice->id().'/clear-export', ['_token' => $this->token()]);
        self::assertResponseRedirects('/invoices');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'This document has no export state.');
    }

    public function testAChangedDocumentOffersToClearItsExportStateToo(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $this->exported($invoice);
        $this->changedAfterExport($invoice);

        self::assertContains('Clear export state', $this->menu($this->client->request('GET', '/invoices')->filter('tr[data-id="'.$invoice->id().'"]')));
    }

    public function testHistorySaysWhenTheDocumentWasExportedAndCleared(): void
    {
        $this->signedIn();
        $this->saveThroughTheForm();
        $invoice = $this->onlyInvoice();
        $this->exported($invoice);
        // A save that changes no value says only that it was saved: the export marks are no fields of the document.
        $this->changedAfterExport($invoice);
        $this->client->request('POST', '/invoices/'.$invoice->id().'/clear-export', ['_token' => $this->token()]);

        $items = $this->client->request('GET', '/invoices/'.$invoice->id().'/history')->filter('main [data-invoice-history] li');

        self::assertStringEndsWith('Example Company exported the invoice for the accountant', $items->eq($items->count() - 3)->text());
        self::assertStringEndsWith('Example Company changed the invoice', $items->eq($items->count() - 2)->text());
        self::assertStringEndsWith('Example Company cleared the export state', $items->last()->text());
    }

    public function testPagination(): void
    {
        $this->signedIn();
        for ($i = 0; $i < 26; ++$i) {
            $this->issue('2026-11-01', '2026-12-01');
        }

        $crawler = $this->client->request('GET', '/invoices');
        self::assertCount(25, $crawler->filter('tbody tr'));
        self::assertSame('20260026', $this->numbers($crawler)[0]);
        self::assertStringContainsString('26 invoices', $crawler->filter('[data-table-footer]')->text());

        $crawler = $this->client->request('GET', '/invoices?page=2&q=2026');
        self::assertSame(['20260001'], $this->numbers($crawler));

        // Past the end shows the last page.
        $crawler = $this->client->request('GET', '/invoices?page=9');
        self::assertSame(['20260001'], $this->numbers($crawler));
    }

    public function testTwentyFiveRowsLoadInAtMostFiveQueries(): void
    {
        $this->signedIn();
        for ($i = 0; $i < 25; ++$i) {
            $this->issue('2026-11-01', '2026-12-01');
        }
        $this->em()->clear();

        $before = $this->queryCount();
        $page = $this->scoped(fn () => self::getContainer()->get(InvoiceStore::class)->page(new InvoiceFilter(null, 'all', '', '-issueDate', 1)));
        $used = $this->queryCount() - $before;

        self::assertCount(25, $page->rows);
        // The firms, the counts, the rows, the send marks and the paid amounts.
        self::assertLessThanOrEqual(5, $used, 'Queries used: '.$used);
    }

    public function testMarksTakeOneQuery(): void
    {
        $this->signedIn(server: true);
        $first = $this->issue('2026-11-01', '2026-12-01');
        for ($i = 1; $i < 25; ++$i) {
            $this->issue('2026-11-01', '2026-12-01');
        }
        $this->sendOf($first);
        $this->em()->clear();

        $before = $this->queryCount();
        $page = $this->scoped(fn () => self::getContainer()->get(InvoiceStore::class)->page(new InvoiceFilter(null, 'all', '', '-issueDate', 1)));
        $queries = \array_slice(self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? [], $before);

        self::assertCount(25, $page->rows);
        $sendQueries = array_filter($queries, static fn (array $query): bool => \is_string($query['sql']) && str_contains($query['sql'], 'invoice_send'));
        self::assertCount(1, $sendQueries);
        $marks = array_count_values(array_map(static fn (InvoiceRow $row): string => $row->sendMark->value, $page->rows));
        ksort($marks);
        self::assertSame(['none' => 24, 'queued' => 1], $marks);
    }

    public function testListShowsTheReminderCountInOneQuery(): void
    {
        $this->signedIn(server: true);
        $twice = $this->issue('2026-11-01', '2026-11-10', description: 'Twice');
        $once = $this->issue('2026-11-02', '2026-11-10', description: 'Once');
        $never = $this->issue('2026-11-03', '2026-11-10', description: 'Never');
        $notDue = $this->issue('2026-11-04', '2026-12-01', description: 'Not due');
        Clock::set(new MockClock('2026-11-18 09:00:00', 'UTC'));
        $this->reminderOf($twice, 1);
        $this->reminderOf($once, 1);
        Clock::set(new MockClock('2026-11-19 09:00:00', 'UTC'));
        $this->reminderOf($twice, 2);
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $this->em()->clear();

        $crawler = $this->client->request('GET', '/invoices');
        // The holder starts afresh with each request, so it holds the queries of this one.
        $queries = self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? [];

        // In the e-mail column, in place of the mark: the newest e-mail was a reminder.
        $cell = static fn (Invoice $invoice): Crawler => $crawler->filter('tbody tr[data-id="'.$invoice->id().'"] [data-icon-cell="bell-ring"]');
        self::assertSame('2 reminders', trim($cell($twice)->text()));
        self::assertSame('Last reminder 2026-11-19 · Sending', $cell($twice)->attr('title'));
        self::assertSame('1 reminder', trim($cell($once)->text()));
        self::assertSame('Last reminder 2026-11-18 · Sending', $cell($once)->attr('title'));
        self::assertCount(0, $cell($never));
        self::assertCount(0, $cell($notDue));
        // On a phone the count goes with the row's second line.
        self::assertStringContainsString('2 reminders', $crawler->filter('tbody tr[data-id="'.$twice->id().'"] [data-row-secondary]')->text());
        self::assertGreaterThan(5, \count($queries), 'the request was recorded');
        // The tally of the page's rows: grouped by invoice, whatever the shell's notices count besides.
        $reminderQueries = array_filter($queries, static fn (array $query): bool => \is_string($query['sql']) && str_starts_with($query['sql'], 'SELECT') && str_contains($query['sql'], 'FROM invoice_send') && str_contains($query['sql'], 'purpose') && str_contains($query['sql'], 'GROUP BY'));
        self::assertCount(1, $reminderQueries);
        // The menu offers a reminder on the overdue rows only.
        self::assertCount(1, $crawler->filter('a[href^="/invoices/'.$never->id().'/remind"]'));
        self::assertCount(0, $crawler->filter('a[href^="/invoices/'.$notDue->id().'/remind"]'));
    }

    public function testEachMarkShowsItsLabel(): void
    {
        $this->signedIn(server: true);
        $none = $this->issue('2026-11-01', '2026-12-01', description: 'None');
        $queued = $this->issue('2026-11-02', '2026-12-01', description: 'Queued');
        $sent = $this->issue('2026-11-03', '2026-12-01', description: 'Sent');
        $read = $this->issue('2026-11-04', '2026-12-01', description: 'Read');
        $failed = $this->issue('2026-11-05', '2026-12-01', description: 'Failed');
        $this->sendOf($sent, deliver: true);
        $this->opened($this->sendOf($read, deliver: true));
        $this->sendOf($failed, ['refuse@example.com'], deliver: true);
        // Last, so no run of the queue delivers it.
        $this->sendOf($queued);

        $crawler = $this->client->request('GET', '/invoices');

        self::assertContains('E-mail', $this->headers($crawler));
        $marks = [];
        foreach ([$none, $queued, $sent, $read, $failed] as $invoice) {
            $cell = $crawler->filter('tr[data-id="'.$invoice->id().'"] [data-icon-cell]');
            $marks[$invoice->number()] = 0 === $cell->count() ? null : [$cell->text(), $cell->attr('title'), $cell->attr('data-icon-cell')];
        }
        self::assertSame([
            $none->number() => null,
            $queued->number() => ['Sending', 'Waiting in the queue to be sent', 'clock-3'],
            $sent->number() => ['Sent', 'Sent to the customer', 'send'],
            $read->number() => ['Read', 'The customer opened the document', 'circle-check'],
            $failed->number() => ['Failed', 'The e-mail could not be sent', 'triangle-alert'],
        ], $marks);
        // An icon, never colour alone, and on a phone the label in the line under the number.
        self::assertCount(1, $crawler->filter('tr[data-id="'.$read->id().'"] [data-icon-cell] svg'));
        self::assertStringContainsString('Read', $crawler->filter('tr[data-id="'.$read->id().'"] [data-row-secondary]')->text());
        // The status badge is separate and stays as it was.
        self::assertSame('Delivered', $crawler->filter('tr[data-id="'.$sent->id().'"] td .rounded-sm')->last()->text());
    }

    public function testReadSurvivesALaterFailedResend(): void
    {
        $this->signedIn(server: true);
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $this->opened($this->sendOf($invoice, deliver: true));
        Clock::set(new MockClock('2026-11-20 13:00:00', 'UTC'));
        $this->sendOf($invoice, ['refuse@example.com'], deliver: true);

        $rows = $this->rows(new InvoiceFilter(null, 'all', '', '-issueDate', 1));

        self::assertSame(SendMark::Read, $rows[0]->sendMark);
    }

    public function testATrashedInvoiceShowsNoMarkColumn(): void
    {
        $this->signedIn();
        $this->trashed($this->issue('2026-11-01', '2026-12-01'), 'Wrong customer');

        $crawler = $this->client->request('GET', '/invoices?tab=trash');

        self::assertNotContains('E-mail', $this->headers($crawler));
    }

    public function testTheSidebarLeadsToTheListAndThePaletteToANewInvoice(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices');

        self::assertCount(1, $crawler->filter('#sidebar a[href="/invoices"][aria-current="page"]'));
        $options = $crawler->filter('[data-controller="command-palette"] [role="option"]')->each(static fn (Crawler $option): string => $option->text());
        self::assertContains('Invoices', array_map(trim(...), $options));
        self::assertContains('New invoice', array_map(trim(...), $options));
    }

    public function testSavingLandsOnTheListAndCancelLeadsThere(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices/new');
        self::assertSame('/invoices', $crawler->filter('#invoice-cancel')->attr('href'));

        $this->saveThroughTheForm();
        self::assertResponseRedirects('/invoices');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Invoice 20260001 is saved.');
    }

    /**
     * Without a series the first save creates the default one, so this needs none stored.
     */
    private function saveThroughTheForm(): void
    {
        $crawler = $this->client->request('GET', '/invoices/new');
        $form = $crawler->filter('#invoice-save')->form();
        $this->client->submit($form, [
            'customerId' => $this->customer->id(),
            'customerQuery' => 'Example Customer a.s.',
            'description' => 'Consulting',
            'lines[0][text]' => 'Work',
            'lines[0][price]' => '1000,00',
            'lines[0][unit]' => 'h',
            '_action' => 'save',
        ]);
    }

    /**
     * @return array{lines: list<array<string, string>>, _action: string}&array<string, mixed>
     */
    private function formValues(Crawler $crawler): array
    {
        /** @var array{lines: list<array<string, string>>}&array<string, mixed> $values */
        $values = $crawler->filter('#invoice-save')->form()->getPhpValues();

        return ['_action' => 'save'] + $values;
    }

    private function onlyInvoice(): Invoice
    {
        return $this->scoped(function (): Invoice {
            $this->em()->clear();
            $invoices = $this->em()->createQuery('SELECT i FROM '.Invoice::class.' i')->getResult();
            self::assertCount(1, $invoices);

            return $invoices[0];
        });
    }

    /**
     * The token of the session, from a form of the list; it stays the same, so a test takes it before the rows run out.
     */
    private function token(): string
    {
        return $this->token ??= $this->client->request('GET', '/invoices?tab='.($this->scoped(fn (): int => (int) $this->em()->createQuery('SELECT COUNT(i.id) FROM '.Invoice::class.' i WHERE i.deletedAt IS NULL')->getSingleScalarResult()) > 0 ? 'all' : 'trash'))->filter('tbody input[name="_token"]')->first()->attr('value') ?? '';
    }

    private function queryCount(): int
    {
        return \count(self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? []);
    }

    /**
     * @return list<string>
     */
    private function numbers(Crawler $crawler): array
    {
        return $crawler->filter('tbody a[data-row-link]')->each(static fn (Crawler $link): string => $link->text());
    }

    /**
     * @return list<string>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Invoice lists"] a')->each(static fn (Crawler $tab): string => (string) preg_replace('/(\D)(\d)/', '$1 $2', $tab->text()));
    }

    /**
     * @return list<string>
     */
    private function headers(Crawler $crawler): array
    {
        return $crawler->filter('thead th')->each(static fn (Crawler $header): string => $header->text());
    }

    /**
     * What the menu of a row offers on the desktop.
     *
     * @return list<string>
     */
    private function menu(Crawler $row): array
    {
        return $row->filter('[role="menu"] [role="menuitem"]:not([data-phone-only])')->each(static fn (Crawler $item): string => $item->text());
    }

    /**
     * @return list<InvoiceRow>
     */
    private function rows(InvoiceFilter $filter): array
    {
        return $this->scoped(fn (): array => self::getContainer()->get(InvoiceStore::class)->page($filter)->rows);
    }

    private function issue(string $date, string $due, ?Party $customer = null, string $description = 'Consulting', string $price = '1000.00', string $variableSymbol = '', ?LegalEntity $firm = null): Invoice
    {
        return $this->scoped(fn (): Invoice => self::getContainer()->get(IssueInvoice::class)->issue($this->input($date, $due, $customer, $description, $price, $variableSymbol, $firm)));
    }

    private function input(string $date, string $due, ?Party $customer = null, string $description = 'Consulting', string $price = '1000.00', string $variableSymbol = '', ?LegalEntity $firm = null): InvoiceInput
    {
        $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

        return new InvoiceInput(
            ($firm ?? $this->firm)->id(),
            $firm instanceof LegalEntity ? null : $this->series->id(),
            null,
            ($customer ?? $this->customer)->id(),
            null,
            new \DateTimeImmutable($date),
            new \DateTimeImmutable($date),
            new \DateTimeImmutable($due),
            PaymentMethod::BankTransfer,
            self::getContainer()->get(FirmBankAccounts::class)->primaryOf($firm ?? $this->firm)?->iban(),
            $variableSymbol,
            '',
            '',
            $description,
            '',
            '',
            DocumentLanguage::Czech,
            [$line],
            '' === $variableSymbol,
        );
    }

    private function pay(Invoice $invoice, string $amount): void
    {
        $this->scoped(function () use ($invoice, $amount): void {
            $money = Money::of($amount, Currency::CZK);
            self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-20'), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $money)]));
        });
    }

    private function nbspless(string $text): string
    {
        return str_replace("\u{a0}", ' ', $text);
    }

    private function trashed(Invoice $invoice, string $reason): void
    {
        $this->scoped(fn () => self::getContainer()->get(TrashInvoice::class)->trash($invoice->id(), $reason));
    }

    private function delivered(Invoice $invoice): void
    {
        $this->scoped(fn () => self::getContainer()->get(MarkDelivered::class)->mark($invoice->id()));
    }

    private function exported(Invoice ...$invoices): void
    {
        $this->scoped(fn () => self::getContainer()->get(DocumentExports::class)->markExported(array_values(array_map(static fn (Invoice $invoice): string => $invoice->id(), $invoices))));
    }

    /**
     * Saves the document again as it was issued by issue(): a save of the content.
     */
    private function changedAfterExport(Invoice $invoice): void
    {
        $this->scoped(function () use ($invoice): void {
            $this->em()->clear();
            $stored = self::getContainer()->get(InvoiceStore::class)->get($invoice->id());
            self::getContainer()->get(EditInvoice::class)->edit($invoice->id(), $this->input($stored->issueDate()->format('Y-m-d'), $stored->dueDate()->format('Y-m-d')), $stored->version());
        });
    }

    private function stored(Invoice $invoice): Invoice
    {
        return $this->scoped(function () use ($invoice): Invoice {
            $this->em()->clear();

            return self::getContainer()->get(InvoiceStore::class)->get($invoice->id());
        });
    }

    private function customerNamed(string $name): Party
    {
        return $this->scoped(function () use ($name): Party {
            $party = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '87654320'), null, '');
            $this->em()->persist($party);
            $this->em()->persist(new PartyAddress($party, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
            $this->em()->flush();

            return $party;
        });
    }

    private function secondFirm(): LegalEntity
    {
        return $this->scoped(function (): LegalEntity {
            $firm = new LegalEntity('Example Second s.r.o.', new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), new Address('Example Street 2', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 2');
            $this->em()->persist($firm);
            FirmAccounts::add($this->em(), $firm, 'CZ5508000000001234567899');
            $this->em()->flush();

            return $firm;
        });
    }

    private function foreignFirm(): LegalEntity
    {
        $tenant = $this->browser->fixtures->tenant('Foreign Tenant '.bin2hex(random_bytes(2)));
        $this->context->switchTo($tenant);
        try {
            $firm = new LegalEntity('Example Foreign s.r.o.', new RegistrationNumber('CZ', '12345679'), null, new Address('Example Street 3', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, 'Register');
            $this->em()->persist($firm);
            $this->em()->flush();

            return $firm;
        } finally {
            $this->context->reset();
        }
    }

    private function foreignInvoice(): Invoice
    {
        $tenant = $this->browser->fixtures->tenant('Foreign Tenant '.bin2hex(random_bytes(2)));
        $this->context->switchTo($tenant);
        try {
            $firm = new LegalEntity('Example Foreign s.r.o.', new RegistrationNumber('CZ', '12345679'), VatId::fromString('CZ12345679'), new Address('Example Street 3', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Register');
            $this->em()->persist($firm);
            FirmAccounts::add($this->em(), $firm, self::IBAN);
            $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
            $series->markDefault();
            $this->em()->persist($series);
            $customer = new Party(PartyKind::Company, 'Foreign Customer a.s.', new RegistrationNumber('CZ', '87654320'), null, '');
            $this->em()->persist($customer);
            $this->em()->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
            $this->em()->flush();
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($firm->id(), $series->id(), null, $customer->id(), null, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Foreign', '', '', DocumentLanguage::Czech, [$line], true));
        } finally {
            $this->context->reset();
        }
    }

    /**
     * Sends the invoice from the dialog's service; delivered at once when asked.
     *
     * @param list<string> $to
     */
    private function sendOf(Invoice $invoice, array $to = ['billing@example.com'], bool $deliver = false): string
    {
        return $this->scoped(static function () use ($invoice, $to, $deliver): string {
            $send = self::getContainer()->get(SendInvoice::class)->send(new SendRequest($invoice->id(), $to, [], false, 'Invoice', '{link}'));
            if ($deliver) {
                $mail = self::getContainer()->get('messenger.transport.mail');
                self::assertInstanceOf(InMemoryTransport::class, $mail);
                try {
                    new RunMessages($mail, self::getContainer()->get(MessageBusInterface::class))->run();
                } catch (HandlerFailedException) {
                    // A refused message fails for good, and the worker reports it: the send is stored as failed.
                }
            }

            return $send->id();
        });
    }

    private function reminderOf(Invoice $invoice, int $step): void
    {
        $this->scoped(static fn () => self::getContainer()->get(SendInvoice::class)->send(new SendRequest($invoice->id(), ['billing@example.com'], [], false, 'Reminder', '{link}', SendPurpose::Reminder, $step)));
    }

    private function opened(string $sendId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE invoice_send SET first_read_at = '2026-11-20 12:30:00', read_count = 1 WHERE id = ?", [$sendId]);
    }

    private function signedIn(Role $role = Role::Owner, bool $server = false): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], role: $role);
        $this->context->switchTo($this->tenant);
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em()->persist($this->firm);
        FirmAccounts::add($this->em(), $this->firm, self::IBAN);
        $this->series = new Series($this->firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->series->markDefault();
        $this->em()->persist($this->series);
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em()->persist($this->customer);
        $this->em()->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        if ($server) {
            $this->em()->persist(FirmMailServer::define($this->firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', self::getContainer()->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', 'Example Firm'));
        }
        $this->em()->flush();
        $this->context->reset();
        $this->browser->signIn();
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
