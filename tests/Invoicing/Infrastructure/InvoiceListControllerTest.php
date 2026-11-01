<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\MarkDelivered;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
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
        self::assertSame(['All 2', 'Overdue 1', 'Trash 1'], $this->tabs($crawler));
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

        $crawler = $this->client->request('GET', '/invoices?tab=paid');

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
        self::assertSame(['All 1', 'Overdue 1', 'Trash 0'], $this->tabs($crawler));
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

        self::assertSame(['All 1', 'Overdue 0', 'Trash 0'], $this->tabs($crawler));
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
        self::assertStringContainsString($this->nbsp('1,210.00 CZK'), $row->text());
    }

    public function testAnotherTenantSeesNothing(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');
        $other = $this->foreignInvoice();

        $crawler = $this->client->request('GET', '/invoices');

        self::assertSame([$invoice->number()], $this->numbers($crawler));
        self::assertSame(['All 1', 'Overdue 0', 'Trash 0'], $this->tabs($crawler));

        $this->client->request('GET', '/invoices/'.$other->id().'/history');
        self::assertResponseStatusCodeSame(404);
        foreach (['trash', 'restore', 'delivered'] as $path) {
            $this->client->request('POST', '/invoices/'.$other->id().'/'.$path, ['_token' => $this->token(), 'reason' => 'Mine']);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testOnlyStaffSeeInvoices(): void
    {
        $this->signedIn(Role::Client);
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        foreach ([['GET', '/invoices'], ['GET', '/invoices/'.$invoice->id().'/history'], ['GET', '/invoices/'.$invoice->id().'/copy'], ['GET', '/invoices/'.$invoice->id().'/trash'], ['POST', '/invoices/'.$invoice->id().'/trash'], ['POST', '/invoices/'.$invoice->id().'/restore'], ['POST', '/invoices/'.$invoice->id().'/delivered']] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        self::assertCount(0, $this->client->request('GET', '/contacts')->filter('#sidebar a[href="/invoices"]'));
    }

    public function testActionsNeedAToken(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-11-01', '2026-12-01');

        foreach (['trash', 'restore', 'delivered'] as $path) {
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
        self::assertSame(['Copy', 'Mark as delivered', 'History', 'Delete'], $this->menu($row));
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
        self::assertSame(['Copy', 'History'], $this->menu($row));
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

    public function testTwentyFiveRowsLoadInAtMostThreeQueries(): void
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
        self::assertLessThanOrEqual(3, $used, 'Queries used: '.$used);
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
        return $this->scoped(function () use ($date, $due, $customer, $description, $price, $variableSymbol, $firm): Invoice {
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput(
                ($firm ?? $this->firm)->id(),
                $firm instanceof LegalEntity ? null : $this->series->id(),
                null,
                ($customer ?? $this->customer)->id(),
                null,
                new \DateTimeImmutable($date),
                new \DateTimeImmutable($date),
                new \DateTimeImmutable($due),
                PaymentMethod::BankTransfer,
                ($firm ?? $this->firm)->bankAccounts()[0],
                $variableSymbol,
                '',
                '',
                $description,
                '',
                '',
                DocumentLanguage::Czech,
                [$line],
                '' === $variableSymbol,
            ));
        });
    }

    private function trashed(Invoice $invoice, string $reason): void
    {
        $this->scoped(fn () => self::getContainer()->get(TrashInvoice::class)->trash($invoice->id(), $reason));
    }

    private function delivered(Invoice $invoice): void
    {
        $this->scoped(fn () => self::getContainer()->get(MarkDelivered::class)->mark($invoice->id()));
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
            $firm = new LegalEntity('Example Second s.r.o.', new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), new Address('Example Street 2', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 2', ['CZ5508000000001234567899']);
            $this->em()->persist($firm);
            $this->em()->flush();

            return $firm;
        });
    }

    private function foreignFirm(): LegalEntity
    {
        $tenant = $this->browser->fixtures->tenant('Foreign Tenant '.bin2hex(random_bytes(2)));
        $this->context->switchTo($tenant);
        try {
            $firm = new LegalEntity('Example Foreign s.r.o.', new RegistrationNumber('CZ', '12345679'), null, new Address('Example Street 3', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, 'Register', []);
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
            $firm = new LegalEntity('Example Foreign s.r.o.', new RegistrationNumber('CZ', '12345679'), VatId::fromString('CZ12345679'), new Address('Example Street 3', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Register', [self::IBAN]);
            $this->em()->persist($firm);
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

    private function signedIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], role: $role);
        $this->context->switchTo($this->tenant);
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1', [self::IBAN]);
        $this->em()->persist($this->firm);
        $this->series = new Series($this->firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->series->markDefault();
        $this->em()->persist($this->series);
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em()->persist($this->customer);
        $this->em()->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
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
