<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\DocumentExports;
use Foresi\Invoicing\Application\EditInvoice;
use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\InvoiceRow;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\InvoiceSumLine;
use Foresi\Invoicing\Application\InvoiceSums;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Application\MarkDelivered;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Application\SendInvoice;
use Foresi\Invoicing\Application\SendMark;
use Foresi\Invoicing\Application\SendRequest;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Application\View\InvoiceLists;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\ExportState;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Domain\SendPurpose;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Invoicing\Infrastructure\Controller\InvoiceListController;
use Foresi\Party\Application\FirmBankAccounts;
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
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Domain\View\Visibility;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Support\Messenger\RunMessages;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InvoiceListControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private LegalEntity $firm;
    private Series $series;
    private Party $customer;
    private ?string $token = null;

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
        self::assertSame(['All 2', 'Paid 0', 'Unpaid 2', 'Overdue 1', 'To refund 0', 'Trash 1'], $this->tabs($crawler));
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
        self::assertSame(['All 1', 'Paid 0', 'Unpaid 1', 'Overdue 1', 'To refund 0', 'Trash 0'], $this->tabs($crawler));
        self::assertSame($second->id(), $crawler->filter('[data-filter-sheet-form] select[name="firm"] option[selected]')->attr('value'));
        // The tabs and the sort keep the firm.
        self::assertStringContainsString('firm='.$second->id(), (string) $crawler->filter('nav[aria-label="Invoice lists"] a')->eq(1)->attr('href'));
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
        // The empty state's reset, besides the filter bar's own ways back.
        self::assertCount(1, $crawler->filter('main a[data-filter-clear-all][href="/invoices"]'));
        self::assertSame(1, $crawler->filter('main a[href="/invoices"]')->count() - $crawler->filter('[data-filter-bar] a[href="/invoices"]')->count());
    }

    public function testTheTabsShowWhatASearchFinds(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01', description: 'Findable');
        $this->issue('2026-11-02', '2026-12-01', description: 'Other');

        $crawler = $this->client->request('GET', '/invoices', ['q' => 'findable']);

        self::assertSame(['All 1', 'Paid 0', 'Unpaid 1', 'Overdue 0', 'To refund 0', 'Trash 0'], $this->tabs($crawler));
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
        // Under the keys of the attributes, which the older ones name too.
        self::assertSame(['20260002', '20260003', '20260001'], $this->numbers($this->client->request('GET', '/invoices?sort=due_date')));
        self::assertSame(['20260001', '20260003', '20260002'], $this->numbers($this->client->request('GET', '/invoices?sort=-due_date')));
        self::assertSelectorExists('th[aria-sort="descending"] a[href="/invoices?sort=due_date"]');
    }

    public function testAnyAttributeCanBeAddedAsAColumnAndSortedBy(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-05', price: '100.00', variableSymbol: '111');
        $this->issue('2026-11-02', '2026-12-01', price: '300.00', variableSymbol: '222');

        $crawler = $this->client->request('GET', '/invoices?cols=number,tax_point_date,vat,variable_symbol&sort=-vat');

        self::assertSame(['Number', 'Tax point', 'VAT', 'Variable symbol'], array_values(array_diff($crawler->filter('main thead th')->each(static fn (Crawler $th): string => trim($th->text())), ['', 'Actions'])));
        self::assertSame(['20260002', '20260001'], $this->numbers($crawler));
        $cells = $crawler->filter('main tbody tr')->first()->filter('td')->each(static fn (Crawler $td): string => trim($td->text()));
        self::assertSame(['2026-11-02', "63.00\u{a0}Kč", '222'], \array_slice($cells, 1, 3));
        self::assertSelectorExists('th[aria-sort="descending"] a[href*="sort=vat"]');
        self::assertSame(['20260001', '20260002'], $this->numbers($this->client->request('GET', '/invoices?cols=number,vat&sort=vat')));
    }

    public function testAnOlderColumnKeyInTheAddressShowsItsColumnUnderItsKey(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-05');

        $crawler = $this->client->request('GET', '/invoices?cols=number,dueDate');

        self::assertSame(['Number', 'Due'], array_values(array_diff($crawler->filter('main thead th')->each(static fn (Crawler $th): string => trim($th->text())), ['', 'Actions'])));
        self::assertSelectorExists('th a[href="/invoices?sort=number&cols=number,due_date"]');
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
        self::assertCount(0, $crawler->filter('[data-filter-sheet-form] select[name="firm"]'));

        $this->issue('2026-11-02', '2026-12-01', firm: $second);

        $crawler = $this->client->request('GET', '/invoices');
        self::assertContains('Firm', $this->headers($crawler));
        self::assertStringContainsString('Example Second s.r.o.', $crawler->filter('tbody tr')->first()->text());
        self::assertCount(1, $crawler->filter('[data-filter-sheet-form] select[name="firm"]'));
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

    public function testTheDetailIsAPageAndARecordInTheSheet(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01');
        $href = (string) $this->client->request('GET', '/invoices')->filter('tbody tr a[data-row-link]')->first()->attr('href');

        $page = $this->client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('20260001', $page->filter('h1')->text());
        self::assertCount(1, $page->filter('[data-invoice-lines] tbody tr'));
        self::assertCount(1, $page->filter('[data-invoice-lines-stacked] li'), 'a phone stacks the same line');
        // Whom it bills and how much is due by when lead, the status next to the amount.
        self::assertSame('Example Customer a.s.', $page->filter('[data-invoice-summary] [data-invoice-customer]')->text());
        self::assertSame($this->nbsp('1,210.00 Kč'), $page->filter('[data-invoice-summary] [data-invoice-payable]')->text());
        self::assertCount(1, $page->filter('[data-invoice-summary] [data-invoice-status]'));
        self::assertStringContainsString('2026-12-01', $page->filter('[data-invoice-summary]')->text());
        // The dates and symbols as label and value.
        $labels = $page->filter('[data-invoice-details] dt')->each(static fn ($dt): string => trim($dt->text()));
        self::assertContains('Issue date', $labels);
        self::assertContains('Due date', $labels);
        self::assertSame($href.'/edit', $page->filter('a[data-invoice-edit]')->attr('href'));

        $sheet = $this->client->request('GET', $href, server: ['HTTP_X_FORESI_DIALOG' => 'sheet']);
        $record = $sheet->filter('dialog#sheet-page[data-overlay-record]');
        self::assertCount(1, $record);
        self::assertNull($record->attr('data-sheet-full'), 'the detail keeps the width the sheet remembers');
        self::assertNotNull($sheet->filter('a[data-invoice-edit]')->attr('data-in-place'));

        $form = $this->client->request('GET', $href.'/edit', server: ['HTTP_X_FORESI_DIALOG' => 'sheet']);
        self::assertNotNull($form->filter('dialog#sheet-page')->attr('data-sheet-full'), 'the form with lines fills the content panel');
        self::assertSame($href, $form->filter('#invoice-cancel')->attr('href'));
    }

    public function testARowShowsItsFacts(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices');

        $row = $crawler->filter('tbody tr')->first();
        self::assertSame('20260001', $row->filter('a[data-row-link]')->text());
        // The row opens the read-only detail in the sheet (docs/design/overlays.md).
        self::assertNotNull($row->filter('a[data-row-link]')->attr('data-sheet'));
        self::assertMatchesRegularExpression('~^/invoices/[0-9a-f-]{36}$~', (string) $row->filter('a[data-row-link]')->attr('href'));
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
        self::assertSame(['All 4', 'Paid 2', 'Unpaid 2', 'Overdue 0', 'To refund 1', 'Trash 0'], $this->tabs($crawler));
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
        $pay = $unpaidRow->filter('[role="menu"] a[role="menuitem"][href="/invoices/'.$unpaid->id().'/pay"]');
        self::assertSame('Pay', $pay->text());
        self::assertSame('/invoices/'.$unpaid->id().'/pay', $pay->attr('href'));
        self::assertNotNull($pay->attr('data-dialog'));
        self::assertSame('dialog', $pay->attr('aria-haspopup'));
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

    public function testOnlyStaffSeeInvoices(): void
    {
        $this->signedIn(Role::Guest);
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

        $edit = $row->filter('[role="menu"] a[role="menuitem"][href="/invoices/'.$invoice->id().'/edit"]');
        self::assertSame('Edit', $edit->text());
        self::assertSame('false', $edit->attr('data-turbo'));
        $pdf = $row->filter('[role="menu"] a[role="menuitem"][href="/invoices/'.$invoice->id().'/pdf"]');
        self::assertSame('PDF', $pdf->text());
        self::assertSame('false', $pdf->attr('data-turbo'));
        self::assertSame(['Edit', 'PDF', 'Pay', 'Send', 'Copy', 'Recurring invoice', 'Issue a corrective document', 'Mark as delivered', 'History', 'Delete'], $this->menu($row));
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

    public function testAGuestGetsNoPdf(): void
    {
        $this->signedIn(Role::Guest);
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
        self::assertSame('Delete invoice', trim($submit->text()));
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

        self::assertCount(1, $row->filter('[role="menu"] form[action="/invoices/'.$invoice->id().'/restore"]'));
        self::assertCount(0, $row->filter('[role="menu"] a[href$="/edit"]'));
        self::assertSame(['Restore', 'PDF', 'Copy', 'History'], $this->menu($row));
    }

    public function testDeliveredOffersNoDelete(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $this->delivered($invoice);

        $crawler = $this->client->request('GET', '/invoices');

        $row = $crawler->filter('tbody tr')->first();
        self::assertStringContainsString('Delivered', $row->text());
        self::assertSame(['Edit', 'PDF', 'Pay', 'Send', 'Copy', 'Recurring invoice', 'Issue a corrective document', 'History'], $this->menu($row));
        self::assertCount(1, $row->filter('[role="menu"] a[href$="/edit"]'));
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
        self::assertSelectorTextContains('main', 'Invoice 20260001 is restored.');
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
        self::assertStringNotContainsString('is restored', $crawler->filter('main')->text());
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
        // The tab of an old link opens its view, and the list names the view (saved views, D12).
        self::assertSame('view=overdue&q=2026', $return);

        $this->client->request('POST', '/invoices/'.$invoice->id().'/delivered', ['_token' => $this->token(), 'return' => $return]);

        self::assertResponseRedirects('/invoices?view=overdue&q=2026');
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

    public function testAnOldExportLinkBecomesAConditionOfTheWorkingFilter(): void
    {
        $this->signedIn();
        $none = $this->issue('2026-11-01', '2026-12-01');
        $exported = $this->issue('2026-11-02', '2026-12-01');
        $changed = $this->issue('2026-11-03', '2026-12-01');
        $this->exported($exported, $changed);
        $this->changedAfterExport($changed);

        $crawler = $this->client->request('GET', '/invoices?export=exported');

        self::assertSame([$exported->number()], $this->numbers($crawler));
        // The sheet holds only the conditions the person adds (spec D11): no fixed export select.
        self::assertCount(0, $crawler->filter('[data-filter-sheet-form] select[name="export"]'));
        self::assertSame(['not_exported', 'exported', 'changed'], $crawler->filter('[data-filter-rows] [data-filter-row="export"] input[type="checkbox"]')->each(static fn (Crawler $box): string => $box->attr('value') ?? ''));
        self::assertSame(['exported'], $crawler->filter('[data-filter-rows] [data-filter-row="export"] input[type="checkbox"][checked]')->each(static fn (Crawler $box): string => $box->attr('value') ?? ''));
        self::assertSame('Accountant export', $crawler->filter('[data-filter-add] [data-field="export"]')->text());
        // The list's links and its download carry the condition in f=, no longer the old parameter.
        $f = WorkingFilter::encode(ViewFilter::all(new Condition(InvoiceLists::EXPORT, 'is', ExportState::Exported->value)));
        self::assertSame($f, $crawler->filter('form[data-list-export] input[name="f"]')->attr('value'));
        self::assertCount(0, $crawler->filter('form[data-list-export] input[name="export"]'));
        self::assertSame([$exported->number()], $this->numbers($this->client->request('GET', '/invoices?f='.$f)));

        self::assertSame([$changed->number()], $this->numbers($this->client->request('GET', '/invoices?export=changed')));
        self::assertSame([$none->number()], $this->numbers($this->client->request('GET', '/invoices?export=not_exported')));
        self::assertSame([$changed->number(), $exported->number(), $none->number()], $this->numbers($this->client->request('GET', '/invoices?export=other')));
        $either = ViewFilter::all(new Condition(InvoiceLists::EXPORT, 'is_any_of', [ExportState::NotExported->value, ExportState::Changed->value]));
        self::assertSame([$changed->number(), $none->number()], array_map(static fn (InvoiceRow $row): string => $row->number, $this->rows(new InvoiceFilter(null, 'all', '', '-issueDate', 1, view: $either))));
        self::assertSame([], $this->rows(new InvoiceFilter(null, 'trash', '', '-issueDate', 1, view: ViewFilter::all(new Condition(InvoiceLists::TRASHED, 'is', true), new Condition(InvoiceLists::EXPORT, 'is', ExportState::Exported->value)))));
    }

    public function testAnExportFilterFindingNothingOffersToReset(): void
    {
        $this->signedIn();
        $this->issue('2026-11-01', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices?export=changed');

        self::assertSame([], $this->numbers($crawler));
        self::assertSelectorTextContains('main', 'No invoice matches');
        // The empty state's reset, besides the filter bar's own ways back.
        self::assertCount(1, $crawler->filter('main a[data-filter-clear-all][href="/invoices"]'));
        $reset = $crawler->filter('main a[href="/invoices"]')->reduce(static fn (Crawler $link): bool => 'Show all invoices' === trim($link->text()));
        self::assertCount(1, $reset);
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
        self::assertSame(['Edit', 'PDF', 'Pay', 'Send', 'Copy', 'Recurring invoice', 'Issue a corrective document', 'Mark as delivered', 'Clear export state', 'History', 'Delete'], $this->menu($row));
        $form = $row->filter('form[action="/invoices/'.$invoice->id().'/clear-export"]');
        self::assertCount(0, $form->filter('[data-turbo-confirm]'));
        $return = $form->filter('input[name="return"]')->attr('value');
        $f = WorkingFilter::encode(ViewFilter::all(new Condition(InvoiceLists::EXPORT, 'is', ExportState::Exported->value)));
        self::assertSame('f='.$f, $return);

        $this->client->request('POST', '/invoices/'.$invoice->id().'/clear-export', ['_token' => $this->token(), 'return' => $return]);
        self::assertResponseRedirects('/invoices?f='.$f);
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
    public function testTheMonthTakesATaxDocumentByItsTaxPointAndAnAdvanceByItsIssueDate(): void
    {
        $this->signedIn();
        $october = $this->issue('2026-11-02', '2026-12-01');
        $november = $this->issue('2026-11-03', '2026-12-01');
        $advance = $this->issue('2026-11-04', '2026-12-01');
        $earlyAdvance = $this->issue('2026-11-05', '2026-12-01');
        $sql = self::getContainer()->get(Connection::class);
        // Issued in November with an October tax point: the tax point decides.
        $sql->executeStatement("UPDATE invoice SET tax_point_date = '2026-10-31' WHERE id = ?", [$october->id()]);
        // An advance keeps no tax point; the one given here must not count.
        $sql->executeStatement("UPDATE invoice SET kind = 'advance', tax_point_date = '2026-10-31' WHERE id = ?", [$advance->id()]);
        $sql->executeStatement("UPDATE invoice SET kind = 'advance', tax_point_date = NULL, issue_date = '2026-10-01' WHERE id = ?", [$earlyAdvance->id()]);
        $this->em()->clear();

        $numbers = fn (InvoiceFilter $filter): array => array_map(static fn (InvoiceRow $row): string => $row->number, $this->rows($filter));
        self::assertSame([$october->number()], $numbers(new InvoiceFilter(null, 'all', '', '-issueDate', 1, month: '2026-10')));
        self::assertSame([$november->number()], $numbers(new InvoiceFilter(null, 'all', '', '-issueDate', 1, month: '2026-11')));
        self::assertSame([], $numbers(new InvoiceFilter(null, 'all', '', '-issueDate', 1, month: '2026-12')));
        self::assertSame([$november->number(), $october->number()], $numbers(new InvoiceFilter(null, 'all', '', '-issueDate', 1)));
        self::assertSame([$earlyAdvance->number()], $numbers(new InvoiceFilter(null, 'all', '', '-issueDate', 1, kind: DocumentKind::Advance, month: '2026-10')));
        self::assertSame([$advance->number()], $numbers(new InvoiceFilter(null, 'all', '', '-issueDate', 1, kind: DocumentKind::Advance, month: '2026-11')));
    }

    public function testOnlyAWholeMonthIsAMonth(): void
    {
        self::assertSame('2026-01', InvoiceFilter::monthOf('2026-01'));
        self::assertSame('2026-12', InvoiceFilter::monthOf('2026-12'));
        foreach (['', '2026-13', '2026-00', '2026-1', '0999-05', '2026-05-01', ' 2026-05', '2026-05 ', 'x2026-05'] as $value) {
            self::assertNull(InvoiceFilter::monthOf($value), $value);
        }
    }

    public function testTheSumsAddUpTheWholeListByRateInCrowns(): void
    {
        $this->signedIn();
        $this->issueLines('2026-11-02', [$this->line('1000.00'), $this->line('500.00', VatRateKind::Reduced), $this->line('200.00', null, TaxTreatment::Exempt)]);
        $euro = $this->issue('2026-11-03', '2026-12-01', price: '100.00');
        $trashed = $this->issue('2026-11-04', '2026-12-01', price: '300.00');
        $this->trashed($trashed, 'Wrong customer');
        // A document in euros counts at its own rate; 527.625 and 3 040.125 crowns round only in the sum.
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE invoice SET currency = 'EUR', exchange_rate = '25.125' WHERE id = ?", [$euro->id()]);

        $sums = $this->sumsOf(new InvoiceFilter(null, 'all', '', '-issueDate', 1));
        self::assertSame([2, 421250, 79763, 501013], [$sums->count, $sums->base->minor(), $sums->vat->minor(), $sums->total->minor()]);
        self::assertSame(Currency::CZK, $sums->total->currency());
        self::assertSame([
            ['standard', '21.00', 351250, 73763, 425013],
            ['standard', '12.00', 50000, 6000, 56000],
            ['exempt', null, 20000, 0, 20000],
        ], $this->sumLines($sums));

        $trash = $this->sumsOf(new InvoiceFilter(null, 'trash', '', '-issueDate', 1));
        self::assertSame([1, 30000, 6300, 36300], [$trash->count, $trash->base->minor(), $trash->vat->minor(), $trash->total->minor()]);
        self::assertSame([['standard', '21.00', 30000, 6300, 36300]], $this->sumLines($trash));

        // The filters of the page narrow the sums too, the page itself does not.
        self::assertSame(2, $this->sumsOf(new InvoiceFilter(null, 'all', 'Consulting', '-issueDate', 3, month: '2026-11', view: ViewFilter::all(new Condition(InvoiceLists::EXPORT, 'is', ExportState::NotExported->value))))->count);
        $none = $this->sumsOf(new InvoiceFilter(null, 'all', '', '-issueDate', 1, month: '2026-10'));
        self::assertSame([0, 0, 0, 0, []], [$none->count, $none->base->minor(), $none->vat->minor(), $none->total->minor(), $none->lines]);
    }

    public function testTheSumsOfCorrectionsKeepTheirSign(): void
    {
        $this->signedIn();
        $credit = $this->issue('2026-11-02', '2026-12-01');
        $debit = $this->issue('2026-11-03', '2026-12-01', price: '400.00');
        $sql = self::getContainer()->get(Connection::class);
        $sql->executeStatement("UPDATE invoice SET kind = 'correction_document' WHERE id IN (?, ?)", [$credit->id(), $debit->id()]);
        $sql->executeStatement('UPDATE invoice_line SET net_minor = -net_minor, vat_minor = -vat_minor, gross_minor = -gross_minor WHERE invoice_id = ?', [$credit->id()]);

        $sums = $this->sumsOf(new InvoiceFilter(null, 'all', '', '-issueDate', 1, kind: DocumentKind::CorrectionDocument));

        self::assertSame([2, -60000, -12600, -72600], [$sums->count, $sums->base->minor(), $sums->vat->minor(), $sums->total->minor()]);
        self::assertSame([['standard', '21.00', -60000, -12600, -72600]], $this->sumLines($sums));
    }

    public function testTheMonthNarrowsTheListAndEveryLinkKeepsIt(): void
    {
        $this->signedIn();
        $october = $this->issue('2026-10-15', '2026-11-15');
        $november = $this->issue('2026-11-02', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices?month=2026-10&sort=number');

        self::assertSame([$october->number()], $this->numbers($crawler));
        // The sort is a change to All nobody saved (spec D10); the month is not.
        self::assertSame(['All, unsaved changes 1', 'Paid 0', 'Unpaid 1', 'Overdue 1', 'To refund 0', 'Trash 0'], $this->tabs($crawler));
        self::assertSame('/invoices?view=unpaid&sort=number&month=2026-10', $crawler->filter('nav[aria-label="Invoice lists"] a')->eq(2)->attr('href'));
        // A list of the months with a document, not a native month field that overflows and Safari lacks.
        self::assertSame(['select', 'Month'], [$crawler->filter('#invoice-filters-month-field')->nodeName(), $crawler->filter('label[for="invoice-filters-month-field"]')->text()]);
        self::assertSame(['' => 'All months', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'this_year' => 'This year', '2026-11' => 'November 2026', '2026-10' => 'October 2026'], $this->monthOptions($crawler));
        self::assertSame('2026-10', $crawler->filter('#invoice-filters-month-field option[selected]')->attr('value'));
        self::assertStringContainsString('month=2026-10', $crawler->filter('thead a')->first()->attr('href') ?? '');
        $return = $crawler->filter('tr[data-id="'.$october->id().'"] form input[name="return"]')->first()->attr('value');
        self::assertSame('sort=number&month=2026-10', $return);
        self::assertSame(['app_invoices', ['month' => '2026-10']], InvoiceListController::routeOf(['month' => '2026-10', 'other' => 'x']));

        self::assertSame([$november->number()], $this->numbers($this->client->request('GET', '/invoices?month=2026-11')));
        self::assertSame([$november->number(), $october->number()], $this->numbers($this->client->request('GET', '/invoices?month=2026-13')));

        $crawler = $this->client->request('GET', '/invoices?month=2026-09');
        self::assertSelectorTextContains('main', 'No invoice matches');
        self::assertCount(0, $crawler->filter('[data-list-sums]'));
        // A month without a document stays chosen, so the filter it shows is the one applied.
        self::assertSame(['' => 'All months', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'this_year' => 'This year', '2026-11' => 'November 2026', '2026-10' => 'October 2026', '2026-09' => 'September 2026'], $this->monthOptions($crawler));
        self::assertSame('2026-09', $crawler->filter('#invoice-filters-month-field option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', '/invoices');
        self::assertSame('', $crawler->filter('#invoice-filters-month-field option[selected]')->attr('value'));
    }

    public function testLastMonthIsTheMonthBeforeTodayWhenTheListOpens(): void
    {
        // Saved views, D5: a relative month resolves when the query runs, in the instance's zone.
        $this->signedIn();
        $september = $this->issue('2026-09-30', '2026-10-15');
        $october = $this->issue('2026-10-01', '2026-11-15');
        $november = $this->issue('2026-11-02', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices?month=last_month');

        self::assertSame([$october->number()], $this->numbers($crawler));
        self::assertSame('last_month', $crawler->filter('#invoice-filters-month-field option[selected]')->attr('value'));
        self::assertSame(['All 1', 'Paid 0', 'Unpaid 1', 'Overdue 1', 'To refund 0', 'Trash 0'], $this->tabs($crawler));
        self::assertSame('/invoices?view=unpaid&month=last_month', $crawler->filter('nav[aria-label="Invoice lists"] a')->eq(2)->attr('href'));
        self::assertSame([$november->number()], $this->numbers($this->client->request('GET', '/invoices?month=this_month')));
        self::assertSame([$november->number(), $october->number()], $this->numbers($this->client->request('GET', '/invoices?month=this_quarter')));
        self::assertSame([$november->number(), $october->number(), $september->number()], $this->numbers($this->client->request('GET', '/invoices?month=this_year')));

        // The same address a month on lists the month that is now the last one.
        Clock::set(new MockClock('2026-12-02 12:00:00', 'UTC'));
        self::assertSame([$november->number()], $this->numbers($this->client->request('GET', '/invoices?month=last_month')));
    }

    public function testAViewIsNamedByItsSlugAndTheTabOfAnOldLinkOpensIt(): void
    {
        $this->signedIn();
        $this->issue('2026-11-02', '2026-11-10');

        $byView = $this->client->request('GET', '/invoices?view=overdue');
        $byTab = $this->client->request('GET', '/invoices?tab=overdue');

        self::assertSame($this->numbers($byView), $this->numbers($byTab));
        self::assertCount(1, $this->numbers($byView));
        self::assertSelectorExists('nav[aria-label="Invoice lists"] a[href="/invoices?view=overdue"][aria-current="page"]');
        // A view the list does not have shows the whole list.
        $this->client->request('GET', '/invoices?view=nothing');
        self::assertSelectorExists('nav[aria-label="Invoice lists"] a[href="/invoices"][aria-current="page"]');
    }

    public function testTheBuilderAppliesUnpaidAndDueBeforeTodayAsAWorkingFilter(): void
    {
        // Saved views, D11: the sheet's rows are a GET form; Apply puts the filter in f=.
        $this->signedIn();
        $late = $this->issue('2026-10-01', '2026-11-10');
        $notDue = $this->issue('2026-11-02', '2026-12-01');
        $paid = $this->issue('2026-10-02', '2026-11-05');
        $this->pay($paid, '1210.00');

        $this->client->request('GET', '/invoices?'.http_build_query(['q' => '', 'conditions' => '1', 'c' => [
            ['field' => 'payment', 'op' => 'is', 'values' => ['unpaid']],
            ['field' => 'due_date', 'op' => 'before', 'from' => ['rule' => 'today', 'day' => '', 'month' => '', 'n' => '']],
            // A row left without an operator, as the form without JavaScript sends it, is no condition.
            ['field' => 'amount', 'op' => '', 'from' => '5'],
        ]]));

        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame(['f'], array_keys($query));
        $crawler = $this->client->request('GET', $location);

        self::assertSame([$late->number()], $this->numbers($crawler));
        self::assertNotContains($notDue->number(), $this->numbers($crawler));
        self::assertSame(['Payment state: Unpaid', 'Due date: before today'], $crawler->filter('[data-filter-chips] [data-filter-condition] span')->each(static fn (Crawler $chip): string => $chip->text()));
        // The tabs keep their counts and leave the working filter behind.
        self::assertSame('/invoices?view=paid', $crawler->filter('nav[aria-label="Invoice lists"] a')->eq(1)->attr('href'));
        // Each chip's ✕ is the list without that condition.
        $without = $crawler->filter('[data-filter-chips] [data-filter-condition-remove]')->eq(1)->attr('href');
        self::assertCount(2, $this->numbers($this->client->request('GET', (string) $without)));
        // The rows come back in the sheet as they were set.
        self::assertSame('before', $crawler->filter('[data-filter-sheet-form] select[name="c[1][op]"] option[selected]')->attr('value'));
    }

    public function testUnpaidAndDueBeforeTodayIsSavedAsASharedViewOfItsOwn(): void
    {
        // Saved views, D10: a filter nobody saved puts a dot on the view's tab; Save as new view keeps it.
        $this->signedIn();
        $late = $this->issue('2026-10-01', '2026-11-10');
        $this->issue('2026-11-02', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices?f='.$this->unpaidAndDue());

        self::assertSame('All, unsaved changes 2', $this->tabs($crawler)[0]);
        // All is no one's to change: its changes become a new view.
        self::assertCount(0, $crawler->filter('[data-view-save]'));
        self::assertCount(1, $crawler->filter('[data-view-save-as-button]'));
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'Unpaid and due', 'visibility' => 'shared']));

        $view = $this->em()->getRepository(SavedView::class)->findOneBy(['name' => 'Unpaid and due']);
        self::assertInstanceOf(SavedView::class, $view);
        self::assertSame(Visibility::Shared, $view->visibility());
        self::assertFalse($view->showCount());
        $conditions = $view->filters()['conditions'];
        self::assertIsArray($conditions);
        self::assertSame(['payment', 'due_date'], array_column($conditions, 'field'));
        self::assertResponseRedirects('/invoices?view='.$view->id(), 303);
        $crawler = $this->client->followRedirect();
        self::assertSame([$late->number()], $this->numbers($crawler));
        // The list shows six tabs already (D7): the new view opens from More views.
        self::assertSame(['Unpaid and due'], $crawler->filter('[data-more-views] [aria-checked="true"]')->each(static fn (Crawler $item): string => $item->text()));
        self::assertCount(0, $crawler->filter('[data-tab-modified], [data-view-actions]'));
        self::assertContains('The new view is saved.', $this->statuses($crawler));
    }

    public function testResetKeepsOnlyTheSearch(): void
    {
        $this->signedIn();
        $this->issue('2026-10-01', '2026-11-10');

        $crawler = $this->client->request('GET', '/invoices?view=unpaid&q=Consulting&sort=number&f='.$this->unpaidAndDue());

        self::assertSame('/invoices?view=unpaid&q=Consulting&density=', $crawler->filter('[data-view-reset]')->attr('href'));
        $crawler = $this->client->request('GET', '/invoices?view=unpaid&q=Consulting&density=');
        self::assertCount(0, $crawler->filter('[data-tab-modified], [data-view-actions]'));
    }

    public function testAMemberForksADefaultViewAndAnOwnerSavesIntoIt(): void
    {
        // D6: a view a module ships is changed by owners only; anyone forks it with Save as new view.
        $this->signedIn(Role::Member);
        $this->issue('2026-10-01', '2026-11-10');

        $crawler = $this->client->request('GET', '/invoices?view=overdue&sort=number');

        self::assertCount(0, $crawler->filter('[data-view-save]'));
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'Overdue by number']));
        $fork = $this->em()->getRepository(SavedView::class)->findOneBy(['name' => 'Overdue by number']);
        self::assertInstanceOf(SavedView::class, $fork);
        self::assertSame(Visibility::Private, $fork->visibility());
        self::assertSame([['field' => 'number', 'dir' => 'asc']], $fork->sort());
        $crawler = $this->client->followRedirect();
        // A private view carries a lock (D7) and opens in its own order.
        self::assertCount(1, $crawler->filter('[data-more-views] [aria-checked="true"] svg[class*="size-3.5"]'));
        self::assertCount(0, $crawler->filter('[data-tab-modified]'));
        // Their own view is theirs to change.
        self::assertCount(1, $crawler->filter('[data-view-manage-row="'.$fork->id().'"] form[action="/views/edit"]'));
        $overdue = $this->em()->getRepository(SavedView::class)->findOneBy(['systemKey' => 'invoicing.invoices.overdue']);
        self::assertInstanceOf(SavedView::class, $overdue);
        self::assertSame([], $overdue->sort());
    }

    public function testAnOwnerSavesTheSortIntoADefaultView(): void
    {
        $this->signedIn();
        $this->issue('2026-10-01', '2026-11-10');

        $crawler = $this->client->request('GET', '/invoices?view=overdue&sort=number');
        $this->client->submit($crawler->filter('[data-view-save]')->form());

        self::assertResponseRedirects('/invoices?view=overdue', 303);
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('[data-tab-modified]'));
        // The view's own order is no change, and leaves the address clean.
        self::assertStringNotContainsString('sort=', (string) $crawler->filter('nav[aria-label="Invoice lists"] a[aria-current="page"]')->attr('href'));
        self::assertCount(1, $this->client->request('GET', '/invoices?view=overdue&sort=-issueDate')->filter('[data-tab-modified]'));
    }

    public function testManageViewsRenamesMovesAndDeletesAView(): void
    {
        $this->signedIn();
        $this->issue('2026-10-01', '2026-11-10');
        $crawler = $this->client->request('GET', '/invoices?f='.$this->unpaidAndDue());
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'Late']));
        $crawler = $this->client->followRedirect();
        $row = $crawler->filter('[data-view-manage-list] li')->reduce(static fn (Crawler $li): bool => 'Late' === $li->filter('input[name="name"]')->attr('value'));

        $this->client->submit($row->filter('form[action="/views/edit"]')->form(['name' => 'Late ones', 'placement' => 'overflow']));

        $crawler = $this->client->followRedirect();
        self::assertContains('The view is changed.', $this->statuses($crawler));
        self::assertNotContains('Late ones', $this->tabs($crawler));
        self::assertContains('Late ones', $crawler->filter('[data-more-views] [role="menu"] a')->each(static fn (Crawler $item): string => $item->text()));
        $row = $crawler->filter('[data-view-manage-list] li')->reduce(static fn (Crawler $li): bool => 'Late ones' === $li->filter('input[name="name"]')->attr('value'));
        $this->client->submit($row->filter('form[action="/views/delete"]')->form());
        self::assertNull($this->em()->getRepository(SavedView::class)->findOneBy(['name' => 'Late ones']));
        // A shipped view is renamed or moved, never deleted (D6).
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('[data-view-manage-row="overdue"] [data-view-delete]'));
        self::assertCount(1, $crawler->filter('[data-view-manage-row="overdue"] input[name="name"]'));
    }

    public function testAListShowsAtMostSixTabs(): void
    {
        // D7: All and five views; a view moved to a tab past them is refused.
        $this->signedIn();
        $this->issue('2026-10-01', '2026-11-10');
        $crawler = $this->client->request('GET', '/invoices?f='.$this->unpaidAndDue());
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'Late']));
        $crawler = $this->client->followRedirect();

        // All, Paid, Unpaid, Overdue, To refund and Trash are six: the new view waits in More views.
        self::assertCount(6, $this->tabs($crawler));
        $row = $crawler->filter('[data-view-manage-list] li')->reduce(static fn (Crawler $li): bool => 'Late' === $li->filter('input[name="name"]')->attr('value'));
        self::assertStringContainsString('The list already shows six tabs; move another view to More views first.', $row->text());
        $this->client->submit($row->filter('form[action="/views/edit"]')->form(['placement' => 'primary']));

        $crawler = $this->client->followRedirect();
        self::assertSame('The list already shows six tabs; move another view to More views first.', $crawler->filter('[role="alert"]')->text());
        // With the trash in More views there is room for it.
        $this->client->submit($crawler->filter('[data-view-manage-row="trash"] form[action="/views/edit"]')->form(['placement' => 'overflow']));
        $crawler = $this->client->followRedirect();
        $row = $crawler->filter('[data-view-manage-list] li')->reduce(static fn (Crawler $li): bool => 'Late' === $li->filter('input[name="name"]')->attr('value'));
        self::assertStringNotContainsString('six tabs', $row->text());
        $this->client->submit($row->filter('form[action="/views/edit"]')->form(['placement' => 'primary']));
        self::assertContains('Late', $this->tabs($this->client->followRedirect()));
    }

    public function testRestoreDefaultViewsBringsBackTheShippedOnes(): void
    {
        $this->signedIn();
        $this->issue('2026-10-01', '2026-11-10');
        $crawler = $this->client->request('GET', '/invoices');
        $row = $crawler->filter('[data-view-manage-row="overdue"]');
        $this->client->submit($row->filter('form[action="/views/edit"]')->form(['name' => 'Late', 'placement' => 'overflow']));
        $crawler = $this->client->followRedirect();
        self::assertContains('Late', $crawler->filter('[data-more-views] [role="menu"] a')->each(static fn (Crawler $item): string => $item->text()));

        $this->client->submit($crawler->filter('[data-view-restore]')->form());

        $crawler = $this->client->followRedirect();
        self::assertSame(['All 1', 'Paid 0', 'Unpaid 1', 'Overdue 1', 'To refund 0', 'Trash 0'], $this->tabs($crawler));
    }

    public function testAMemberMayNotRestoreTheDefaultViews(): void
    {
        $this->signedIn(Role::Member);
        $this->issue('2026-10-01', '2026-11-10');

        $crawler = $this->client->request('GET', '/invoices');

        self::assertCount(0, $crawler->filter('[data-view-restore]'));
        self::assertSame(['Paid', 'Unpaid', 'Overdue', 'To refund', 'Trash'], $crawler->filter('[data-view-manage-list] li p a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('/invoices?view=paid', $crawler->filter('[data-view-manage-list] li p a')->first()->attr('href'));
    }

    public function testTheDisplayAPersonLastUsedOnAViewComesBack(): void
    {
        // D-display-3: changed columns are kept for the person on the server, until Reset.
        $this->signedIn();
        $this->issue('2026-10-01', '2026-11-10');

        $this->client->request('GET', '/invoices?view=overdue&density=dense&cols=number,customer');
        $crawler = $this->client->request('GET', '/invoices?view=overdue');
        self::assertSame(['Number', 'Customer'], array_slice($this->headers($crawler), 0, 2));

        self::assertSame('Overdue, unsaved changes 1', $this->tabs($crawler)[3]);
        self::assertStringContainsString('"density":"dense"', (string) $crawler->filter('[data-view-save-as-form] input[name="display"]')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-view-save-as-form] input[name="displayChanged"]'));
        // Another view opens with its own display.
        self::assertCount(0, $this->client->request('GET', '/invoices?view=paid')->filter('[data-tab-modified]'));

        $this->client->request('GET', (string) $crawler->filter('[data-view-reset]')->attr('href'));
        self::assertCount(0, $this->client->request('GET', '/invoices?view=overdue')->filter('[data-tab-modified]'));
    }

    public function testTheSavedViewFormsRefuseWhatTheyMayNotDo(): void
    {
        $this->signedIn(Role::Member);
        $this->issue('2026-10-01', '2026-11-10');
        $crawler = $this->client->request('GET', '/invoices?view=overdue&sort=number');
        $form = $crawler->filter('[data-view-save-as-form]')->form();
        $token = (string) $crawler->filter('[data-view-save-as-form] input[name="_token"]')->attr('value');
        $filter = (string) $crawler->filter('[data-view-save-as-form] input[name="f"]')->attr('value');

        // A name of more than 60 characters, and a return to another site.
        $this->client->submit($form, ['name' => str_repeat('x', 61), 'return' => '//example.com/invoices']);
        self::assertResponseRedirects('/', 303);
        // A member changing a view a module ships.
        $this->client->request('POST', '/views/save', ['_token' => $token, 'ref' => 'invoicing.invoices.overdue', 'f' => $filter, 'sort' => '[]', 'return' => '/invoices?view=overdue']);
        $crawler = $this->client->followRedirect();
        self::assertSame(['A view\'s name has 1 to 60 characters.', 'Only its creator or an owner changes this view. Save your changes as a new view instead.'], $crawler->filter('[role="alert"]')->each(static fn (Crawler $alert): string => trim($alert->text())));
        // A filter the list does not accept.
        $this->client->request('POST', '/views/new', ['_token' => $token, 'list' => 'invoicing.invoices', 'name' => 'Broken', 'f' => 'not-a-filter', 'return' => '/invoices']);
        self::assertContains('The view could not be saved. Check its filters and try again.', $this->client->followRedirect()->filter('[role="alert"]')->each(static fn (Crawler $alert): string => trim($alert->text())));
        // Without the form's token.
        $this->client->request('POST', '/views/delete', ['_token' => 'wrong', 'ref' => 'invoicing.invoices.overdue', 'return' => '/invoices']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<string>
     */
    private function statuses(Crawler $crawler): array
    {
        return $crawler->filter('[role="status"]')->each(static fn (Crawler $status): string => trim($status->text()));
    }

    private function unpaidAndDue(): string
    {
        return WorkingFilter::encode(ViewFilter::parse(['conditions' => [
            ['field' => 'payment', 'op' => 'is', 'value' => 'unpaid'],
            ['field' => 'due_date', 'op' => 'before', 'value' => ['relative' => 'today']],
        ]], InvoiceLists::of(DocumentKind::Invoice)));
    }

    public function testABuilderFilterEqualToTheViewLeavesTheAddressClean(): void
    {
        $this->signedIn();
        $this->issue('2026-11-02', '2026-12-01');

        $this->client->request('GET', '/invoices?'.http_build_query(['view' => 'unpaid', 'conditions' => '1', 'c' => [['field' => 'payment', 'op' => 'is', 'values' => ['unpaid']]]]));

        self::assertResponseRedirects('/invoices?view=unpaid', 303);
        // Removing the view's own condition shows the whole list under that view.
        $crawler = $this->client->request('GET', '/invoices?view=unpaid');
        self::assertSame(['Payment state: Unpaid'], $crawler->filter('[data-filter-chips] [data-filter-condition] span')->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertStringContainsString('f=', (string) $crawler->filter('[data-filter-condition-remove]')->attr('href'));
    }

    public function testACustomerAndAnAmountFilterTheList(): void
    {
        $this->signedIn();
        $acme = $this->customerNamed('Acme Example');
        $small = $this->issue('2026-11-02', '2026-12-01', $acme, price: '100.00');
        $this->issue('2026-11-03', '2026-12-01', $acme, price: '5000.00');
        $this->issue('2026-11-04', '2026-12-01');

        $this->client->request('GET', '/invoices?'.http_build_query(['conditions' => '1', 'c' => [
            ['field' => 'customer', 'op' => 'is', 'value' => $acme->id(), 'text' => 'Acme'],
            ['field' => 'amount', 'op' => 'le', 'from' => '1 000,50'],
        ]]));
        $crawler = $this->client->followRedirect();

        self::assertSame([$small->number()], $this->numbers($crawler));
        self::assertSame(['Customer: Acme Example', 'Total: ≤ 1,000.5'], $crawler->filter('[data-filter-chips] [data-filter-condition] span')->each(static fn (Crawler $chip): string => $chip->text()));
    }

    public function testAnAddressWithABrokenFilterShowsTheView(): void
    {
        $this->signedIn();
        $this->issue('2026-11-02', '2026-12-01');

        $crawler = $this->client->request('GET', '/invoices?view=unpaid&f=not-a-filter');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->numbers($crawler));
    }

    public function testStoredDefaultViewsCountAsTheDeclaredOnes(): void
    {
        $this->signedIn();
        $this->issue('2026-10-15', '2026-11-15');
        $before = $this->tabs($this->client->request('GET', '/invoices'));

        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('A kernel runs.'))->find('app:views:sync'));
        self::assertSame(0, $tester->execute([]));

        self::assertSame($before, $this->tabs($this->client->request('GET', '/invoices')));
        self::assertSame(['All 1', 'Paid 0', 'Unpaid 1', 'Overdue 1', 'To refund 0', 'Trash 0'], $before);
    }

    public function testTheMonthsOfferedAreThoseOfTheListKindAndFirm(): void
    {
        $this->signedIn();
        // An invoice belongs to the month of its tax point, not of its issue.
        $invoice = $this->issue('2026-10-02', '2026-11-15');
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE invoice SET tax_point_date = '2026-09-30' WHERE id = ?", [$invoice->id()]);
        $this->issue('2026-11-02', '2026-12-01', firm: $this->secondFirm());
        // An advance belongs to the month of its issue, whatever its tax point.
        $advance = $this->issue('2026-08-20', '2026-09-01');
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE invoice SET kind = 'advance', tax_point_date = '2026-07-31' WHERE id = ?", [$advance->id()]);

        self::assertSame(['' => 'All months', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'this_year' => 'This year', '2026-11' => 'November 2026', '2026-09' => 'September 2026'], $this->monthOptions($this->client->request('GET', '/invoices')));
        self::assertSame(['' => 'All months', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'this_year' => 'This year', '2026-08' => 'August 2026'], $this->monthOptions($this->client->request('GET', '/advances')));
        self::assertSame(['' => 'All months', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'this_year' => 'This year', '2026-09' => 'September 2026'], $this->monthOptions($this->client->request('GET', '/invoices?firm='.$this->firm->id())));
        // An empty list that nothing narrows has no filter bar.
        self::assertSame([], $this->monthOptions($this->client->request('GET', '/corrections')));
    }

    /**
     * @return array<string, string>
     */
    private function monthOptions(Crawler $crawler): array
    {
        $options = [];
        foreach ($crawler->filter('#invoice-filters-month-field option') as $option) {
            self::assertInstanceOf(\DOMElement::class, $option);
            $options[$option->getAttribute('value')] = trim($option->textContent);
        }

        return $options;
    }

    public function testTheListShowsNoSumsUnderTheTable(): void
    {
        $this->signedIn();
        $this->issueLines('2026-11-02', [$this->line('1000.00'), $this->line('500.00', VatRateKind::Reduced)]);

        $crawler = $this->client->request('GET', '/invoices');

        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertCount(0, $crawler->filter('[data-list-sums]'));
    }

    /**
     * @return list<array{string, string|null, int, int, int}>
     */
    private function sumLines(InvoiceSums $sums): array
    {
        return array_map(static fn (InvoiceSumLine $line): array => [$line->treatment->value, $line->rate?->decimal(), $line->base->minor(), $line->vat->minor(), $line->total->minor()], $sums->lines);
    }

    private function sumsOf(InvoiceFilter $filter): InvoiceSums
    {
        return $this->scoped(static fn (): InvoiceSums => self::getContainer()->get(InvoiceStore::class)->sums($filter));
    }

    private function line(string $price, ?VatRateKind $kind = VatRateKind::Base, TaxTreatment $treatment = TaxTreatment::Standard): LineInput
    {
        return new LineInput('Work', Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, $treatment, $kind, null, Percent::fromString('0'));
    }

    /**
     * @param list<LineInput> $lines
     */
    private function issueLines(string $date, array $lines): Invoice
    {
        return $this->scoped(function () use ($date, $lines): Invoice {
            $input = $this->input($date, '2026-12-01');

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($input->firmId, $input->seriesId, null, $input->customerId, null, $input->issueDate, $input->taxPointDate, $input->dueDate, $input->paymentMethod, $input->bankAccount, '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, $lines, true));
        });
    }

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
     * What the menu of a row offers.
     *
     * @return list<string>
     */
    private function menu(Crawler $row): array
    {
        return $row->filter('[role="menu"] [role="menuitem"]')->each(static fn (Crawler $item): string => $item->text());
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
        $this->browser->seed(role: $role);
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
        return $work();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
