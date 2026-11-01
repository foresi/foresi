<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Audit\Domain\AuditEntry;
use App\Identity\Domain\Role;
use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\MarkDelivered;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmInvoicing;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesCounter;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Infrastructure\Form\InvoiceFormReader;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @phpstan-type Form array{lines: list<array<string, string>>, customer: array<string, string>, firmId: string, seriesId: string, customerId: string, customerQuery: string, issueDate: string, taxPointDate: string, dueDate: string, paymentMethod: string, bankAccount: string, variableSymbol: string, language: string, description: string, orderNumber: string, dueDateSet: string, ...}
 * @phpstan-type Figures array{lines: list<array{total: string}>, summaries: list<array{label: string, base: string, vat: string}>, net: string, vat: ?string, rounding: ?string, payable: string}
 */
final class InvoiceControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const string OTHER_IBAN = 'CZ5508000000001234567899';
    private const array CUSTOMER = ['name' => 'Example Customer a.s.', 'registrationNumber' => '87654320', 'vatId' => 'CZ87654320', 'street' => 'Customer Street 2', 'city' => 'Customer City', 'postalCode' => '200 00', 'countryCode' => 'CZ', 'email' => '', 'phone' => '', 'web' => '', 'registerEntry' => ''];

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
        Clock::set(new MockClock('2026-11-01 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheNewFormOpensWithTheDefaults(): void
    {
        $this->signedIn();

        $values = $this->openNew();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '20260001');
        self::assertSelectorExists('main [data-action-bar]');
        self::assertSame('2026-11-01', $values['issueDate']);
        self::assertSame('2026-11-01', $values['taxPointDate']);
        self::assertSame('2026-11-15', $values['dueDate']);
        self::assertSame('20260001', $values['variableSymbol']);
        self::assertSame($this->series->id(), $values['seriesId']);
        self::assertCount(1, $values['lines']);
        self::assertSame('1', $values['lines'][0]['quantity']);
    }

    public function testACopyOpensAnUnsavedFormOfANewDocument(): void
    {
        $this->signedIn();
        $source = $this->issued();
        Clock::set(new MockClock('2027-02-10 12:00:00', 'UTC'));

        $values = $this->openCopy($source);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '20270001');
        self::assertSelectorExists('form#invoice-form[action="/invoices/new"][data-turbo="false"]');
        self::assertSame('', $values['invoiceId'] ?? null);
        self::assertSame($this->customer->id(), $values['customerId']);
        self::assertSame('Example Customer a.s.', $values['customerQuery']);
        self::assertSame('Example Customer a.s.', $values['customer']['name']);
        self::assertSame('2027-02-10', $values['issueDate']);
        self::assertSame('2027-02-10', $values['taxPointDate']);
        self::assertSame('2027-02-24', $values['dueDate']);
        self::assertSame('20270001', $values['variableSymbol']);
        self::assertSame('Consulting', $values['description']);
        self::assertSame('', $values['orderNumber']);
        self::assertSame('Work', $values['lines'][0]['text']);
        self::assertSelectorNotExists('[data-copy-notice]');
        self::assertSelectorNotExists('[data-line-dates]');
    }

    public function testACopyReadsTheContactAsItIsNow(): void
    {
        $this->signedIn();
        $source = $this->issued();
        $this->scoped(function (): void {
            $party = $this->em()->getRepository(Party::class)->find($this->customer->id()) ?? throw new \LogicException('Customer.');
            $party->rename('Renamed Customer a.s.');
            $this->em()->flush();
        });

        $values = $this->openCopy($source);

        self::assertSame('Renamed Customer a.s.', $values['customer']['name']);
        self::assertSame('Example Customer a.s.', $this->stored($source)->customer()->name);
    }

    public function testCopyWritesNothing(): void
    {
        $this->signedIn();
        $source = $this->issued();
        $before = $this->rowCounts();

        $this->openCopy($source);

        self::assertSame($before, $this->rowCounts());
    }

    public function testASavedCopyIsANewDocumentAndTheSourceStaysAsItWas(): void
    {
        $this->signedIn();
        $source = $this->issued();
        $version = $this->stored($source)->version();

        $values = $this->openCopy($source);
        $this->save($values);

        self::assertResponseRedirects('/invoices');
        $invoices = $this->invoices();
        self::assertCount(2, $invoices);
        self::assertSame('20260002', $invoices[1]->number());
        self::assertNotSame($source->id(), $invoices[1]->id());
        self::assertSame('20260001', $this->stored($source)->number());
        self::assertSame($version, $this->stored($source)->version());
        self::assertSame(121000, $invoices[1]->payable()->minor());
    }

    public function testATrashedInvoiceCanBeCopied(): void
    {
        $this->signedIn();
        $source = $this->issued();
        $this->scoped(fn () => self::getContainer()->get(TrashInvoice::class)->trash($source->id(), 'Wrong customer'));

        $values = $this->openCopy($source);

        self::assertResponseIsSuccessful();
        self::assertSame('Consulting', $values['description']);
        self::assertSelectorTextNotContains('main', 'in the trash');
    }

    #[DataProvider('datesInLines')]
    public function testADateInLineTextShowsANotice(string $text, bool $notice): void
    {
        $this->signedIn();
        $this->save($this->filled(['lines' => [$this->lineValues(['text' => $text, 'price' => '100,00'])]]));
        $source = $this->onlyInvoice();

        $values = $this->openCopy($source);

        self::assertCount($notice ? 1 : 0, $this->client->getCrawler()->filter('[data-line-dates]'));
        self::assertSame($text, $values['lines'][0]['text']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function datesInLines(): iterable
    {
        yield 'a month by name' => ['Pronájem říjen 2026', true];
        yield 'a month and a year' => ['Hosting 10/2026', true];
        yield 'a plain number' => ['2026 ks', false];
    }

    public function testTheLineNoticeStaysThroughARefusedSaveOfACopy(): void
    {
        $this->signedIn();
        $this->save($this->filled(['lines' => [$this->lineValues(['text' => 'Hosting 10/2026', 'price' => '100,00'])]]));
        $values = $this->openCopy($this->onlyInvoice());

        $this->save(array_merge($values, ['customerId' => '', 'customerQuery' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-line-dates]');
    }

    public function testAnOrdinaryFormHasNoLineNotice(): void
    {
        $this->signedIn();
        $values = $this->filled(['customerId' => '', 'customerQuery' => '', 'lines' => [$this->lineValues(['text' => 'Hosting 10/2026'])]]);

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('[data-line-dates]');
    }

    public function testACopyOfAnArchivedFirmAsksForAnotherFirm(): void
    {
        $this->signedIn();
        $source = $this->issued();
        $second = $this->secondFirm(VatStatus::Registered);
        $this->archiveFirm();

        $crawler = $this->client->request('GET', '/invoices/'.$source->id().'/copy');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-copy-notice]', 'This firm is archived');
        self::assertCount(1, $crawler->filter('select[name="firmId"] option[value="'.$second->id().'"]'));
    }

    public function testANewIssueForAnArchivedFirmIsRefused(): void
    {
        $this->signedIn();
        $source = $this->issued();
        $values = $this->openCopy($source);
        $this->archiveFirm();

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-error-summary]', 'This firm is archived');
        self::assertCount(1, $this->invoices());
    }

    public function testAnInvoiceOfAnArchivedFirmStaysEditable(): void
    {
        $this->signedIn();
        $source = $this->issued();
        $this->archiveFirm();
        $values = $this->openEdit($source);
        $values['description'] = 'Still editable';

        $this->saveEdit($source, $values);

        self::assertResponseRedirects('/invoices');
        self::assertSame('Still editable', $this->stored($source)->description());
    }

    public function testACopyForACustomerArchivedForTheFirmWarnsAndNeedsAnotherCustomer(): void
    {
        $this->signedIn();
        $source = $this->issued();
        $this->scoped(function (): void {
            $party = $this->em()->getRepository(Party::class)->find($this->customer->id()) ?? throw new \LogicException('Customer.');
            $firm = $this->em()->getRepository(LegalEntity::class)->find($this->firm->id()) ?? throw new \LogicException('Firm.');
            self::getContainer()->get(ContactSettingsForFirm::class)->changeArchived($party, $firm, true);
            $this->em()->flush();
        });

        $values = $this->openCopy($source);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-copy-notice]', 'Choose another customer');
        self::assertSame('', $values['customerId']);
        self::assertSame('', $values['customer']['name']);

        $this->save($values);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->invoices());
    }

    public function testAnArchivedSeriesCopyUsesTheDefaultAndSaysSo(): void
    {
        $this->signedIn();
        $second = $this->secondSeries();
        $this->save($this->filled(['seriesId' => $second->id(), 'numberPeek' => 'S2026001', 'number' => 'S2026001', 'variableSymbol' => '']));
        $source = $this->onlyInvoice();
        $this->scoped(function () use ($second): void {
            $series = $this->em()->getRepository(Series::class)->find($second->id()) ?? throw new \LogicException('Series.');
            $series->archive();
            $this->em()->flush();
        });

        $values = $this->openCopy($source);

        self::assertSelectorTextContains('[data-copy-notice]', 'default series');
        self::assertSame($this->series->id(), $values['seriesId']);
    }

    public function testACopyOfAReverseChargeInvoiceKeepsTreatmentAndCode(): void
    {
        $this->signedIn();
        $this->save($this->filled(['lines' => [$this->lineValues(['vat' => 'reverse_charge', 'supplyCode' => '4'])]]));
        $source = $this->onlyInvoice();

        $values = $this->openCopy($source);

        self::assertSame('reverse_charge', $values['lines'][0]['vat']);
        self::assertSame('4', $values['lines'][0]['supplyCode']);
    }

    public function testANonPayerCopyStaysWithoutVat(): void
    {
        $this->signedIn(status: VatStatus::NotRegistered);
        $this->save($this->filled());
        $source = $this->onlyInvoice();

        $values = $this->openCopy($source);

        self::assertSame(InvoiceFormReader::VAT_NONE, $values['lines'][0]['vat']);
        self::assertSelectorNotExists('[data-cell="vat"]');
    }

    public function testACopyOfAnotherTenantsInvoiceIs404(): void
    {
        $this->signedIn();
        $foreign = $this->foreignInvoice();

        $this->client->request('GET', '/invoices/'.$foreign->id().'/copy');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNoFirmSaysWhatToDo(): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $this->client->request('GET', '/invoices/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Add a firm');
        self::assertSelectorNotExists('form#invoice-form');
    }

    public function testAFirmOfAnUnsupportedCountrySaysSo(): void
    {
        $this->signedIn(address: new Address('Example Street 1', 'Example City', '100 00', 'SK'));

        $this->client->request('GET', '/invoices/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', $this->text('invoice.problem.firm_country_unsupported'));
        self::assertSelectorNotExists('form#invoice-form');
    }

    public function testSaveIssuesAndNamesTheRealNumber(): void
    {
        $this->signedIn();

        $this->save($this->filled());

        $invoice = $this->onlyInvoice();
        self::assertResponseRedirects('/invoices');
        self::assertSame('20260001', $invoice->number());
        self::assertSame('20260001', $invoice->variableSymbol());
        self::assertSame(121000, $invoice->payable()->minor());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Invoice 20260001 is saved.');
    }

    public function testTheNumberOnTheFormIsOnlyAPreview(): void
    {
        $this->signedIn();
        $slower = $this->filled();
        $this->save($this->filled());

        $this->save($slower);

        $late = $this->invoices()[1];
        self::assertSame('20260002', $late->number());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Invoice saved as 20260002: 20260001 was taken meanwhile.');
    }

    public function testAVariableSymbolFollowsTheRealNumber(): void
    {
        $this->signedIn();
        $slower = $this->filled();
        $this->save($this->filled());

        $this->save($slower);

        self::assertSame('20260002', $this->invoices()[1]->variableSymbol());
    }

    public function testAVariableSymbolTypedByHandStays(): void
    {
        $this->signedIn();
        $slower = $this->filled(['variableSymbol' => '777']);
        $this->save($this->filled());

        $this->save($slower);

        self::assertSame('777', $this->invoices()[1]->variableSymbol());
    }

    public function testATypedNumberIsUsedAndTheSymbolFollowsItWithoutJavaScript(): void
    {
        $this->signedIn();

        $this->save($this->filled(['number' => 'X2026-5']));

        $invoice = $this->onlyInvoice();
        self::assertSame('X2026-5', $invoice->number());
        self::assertSame('20265', $invoice->variableSymbol());
    }

    public function testADoubleSubmitMakesOneInvoice(): void
    {
        $this->signedIn();
        $values = $this->filled();

        $this->save($values);
        $first = (string) $this->client->getResponse()->headers->get('Location');
        $this->save($values);

        self::assertResponseRedirects($first);
        self::assertCount(1, $this->invoices());
        self::assertSame('20260002', $this->openNew()['variableSymbol']);
    }

    public function testBackAndSaveAgainMakesNoSecondInvoice(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $this->save($values);
        $this->client->followRedirect();

        $this->save(array_merge($values, ['description' => 'Changed after Back']));

        self::assertCount(1, $this->invoices());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'This form was saved already.');
    }

    public function testADoubleSubmitOfAnEditLandsOnTheSavedInvoice(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->openEdit($invoice);
        $values['description'] = 'Changed';

        $this->saveEdit($invoice, $values);
        $first = (string) $this->client->getResponse()->headers->get('Location');
        $this->saveEdit($invoice, $values);

        self::assertResponseRedirects($first);
        self::assertSame(2, $this->stored($invoice)->version());
    }

    public function testAnExpiredFormKeepsWhatWasTyped(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $values['_submission'] = 'unknown';

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->invoices());
        self::assertSame('Consulting', $this->currentValues()['description']);
    }

    public function testARefusedSaveConsumesNoNumber(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $refused = $values;
        $refused['lines'][0]['text'] = '';
        $refused['lines'][0]['price'] = '100';

        $this->save($refused);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#lines-0-text[aria-invalid="true"]');
        self::assertSame([], $this->invoices());

        $this->save($values);
        self::assertSame('20260001', $this->onlyInvoice()->number());
    }

    public function testAllErrorsAreListedOnSubmit(): void
    {
        $this->signedIn();
        $values = $this->filled(['customerId' => '', 'customerQuery' => '', 'dueDate' => '']);
        $values['lines'][0]['price'] = 'abc';
        $values['lines'][0]['text'] = '';

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        $list = $this->client->getCrawler()->filter('[data-error-summary]');
        self::assertCount(1, $list);
        self::assertStringContainsString($this->text('invoice.problem.customer_unknown'), $list->text());
        self::assertStringContainsString($this->text('invoice.problem.due_date_missing'), $list->text());
        self::assertStringContainsString($this->text('invoice.problem.price_invalid'), $list->text());
        self::assertStringContainsString($this->text('invoice.problem.line_text_missing'), $list->text());
        self::assertStringContainsString('Line 1', $list->text());
        self::assertSelectorExists('#customer[aria-invalid="true"]');
        self::assertSelectorExists('#dueDate[aria-invalid="true"]');
        self::assertSelectorExists('#lines-0-price[aria-invalid="true"]');
        self::assertSelectorExists('#lines-0-text[aria-invalid="true"]');
        self::assertSelectorExists('[data-error-summary] a[href="#lines-0-price"]');
        self::assertStringStartsWith('Error:', $this->client->getCrawler()->filter('title')->text());
    }

    public function testRecountUsesTheServerCalculator(): void
    {
        $this->signedIn();
        $values = $this->openNew();
        $values['lines'] = [
            $this->lineValues(['price' => '333,33', 'quantity' => '3', 'vat' => 'base']),
            $this->lineValues(['price' => '100,00', 'priceType' => 'with_vat', 'vat' => 'reduced']),
        ];

        $this->client->request('POST', '/invoices/recount', $values);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertStringStartsWith('{"lines":', (string) $this->client->getResponse()->getContent());
        self::assertSame(['1,209.99 CZK', '100.00 CZK'], array_map($this->nbsp(...), array_column($data['lines'], 'total')));
        self::assertSame('1,089.28 CZK', $this->nbsp($data['net']));
        self::assertSame('220.71 CZK', $this->nbsp((string) $data['vat']));
        self::assertSame('0.01 CZK', $this->nbsp((string) $data['rounding']));
        self::assertSame('1,310.00 CZK', $this->nbsp($data['payable']));
        self::assertCount(2, $data['summaries']);
        self::assertSame('21%', $data['summaries'][0]['label']);
        self::assertSame('999.99 CZK', $this->nbsp($data['summaries'][0]['base']));
    }

    public function testRecountAndTheSavedDocumentNeverDiverge(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $values['lines'] = [
            $this->lineValues(['price' => '333,33', 'quantity' => '3', 'discount' => '7,5', 'vat' => 'base']),
            $this->lineValues(['price' => '19,99', 'quantity' => '1,25', 'priceType' => 'with_vat', 'vat' => 'reduced']),
            $this->lineValues(['price' => '10,10', 'priceType' => 'base_only', 'vat' => 'base']),
        ];

        $this->client->request('POST', '/invoices/recount', $values);
        $recount = $this->json();
        $this->save($values);

        $invoice = $this->onlyInvoice();
        $this->openEdit($invoice);
        self::assertResponseIsSuccessful();
        self::assertSame($this->nbsp($recount['payable']), $this->nbsp($this->client->getCrawler()->filter('[data-total="payable"]')->text()));
        self::assertSame($this->nbsp((string) $recount['rounding']), $this->nbsp($this->client->getCrawler()->filter('[data-total="rounding"]')->text()));
    }

    public function testRecountHidesARoundingOfZero(): void
    {
        $this->signedIn();
        $values = $this->openNew();
        $values['lines'] = [$this->lineValues(['price' => '100,00', 'vat' => 'base'])];

        $this->client->request('POST', '/invoices/recount', $values);

        self::assertNull($this->json()['rounding']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function nonsense(): iterable
    {
        yield 'a word for a price' => [['price' => 'abc']];
        yield 'a word for a quantity' => [['quantity' => 'many']];
        yield 'too big to count' => [['price' => '9999999999999', 'quantity' => '999999999']];
    }

    /**
     * @param array<string, string> $line
     */
    #[DataProvider('nonsense')]
    public function testRecountOfNonsenseAnswers422(array $line): void
    {
        $this->signedIn();
        $values = $this->openNew();
        $values['lines'] = [$this->lineValues($line)];

        $this->client->request('POST', '/invoices/recount', $values);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRecountOfABadDateAnswers422(): void
    {
        $this->signedIn();
        $values = $this->openNew();
        $values['taxPointDate'] = 'x';

        $this->client->request('POST', '/invoices/recount', $values);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRecountWithoutATokenIsRefused(): void
    {
        $this->signedIn();
        $values = $this->openNew();
        $values['_token'] = 'wrong';

        $this->client->request('POST', '/invoices/recount', $values);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRecountOfAForeignFirmIs404(): void
    {
        $this->signedIn();
        $foreign = $this->foreignFirm();
        $values = $this->openNew();
        $values['firmId'] = $foreign->id();

        $this->client->request('POST', '/invoices/recount', $values);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRecountOfAnEditUsesTheStoredRegime(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->openEdit($invoice);
        $this->scoped(function (): void {
            $firm = $this->em()->getRepository(LegalEntity::class)->find($this->firm->id()) ?? throw new \LogicException('Firm.');
            $firm->update($firm->name(), $firm->registrationNumber(), null, $firm->address(), VatStatus::NotRegistered, $firm->registerEntry(), $firm->bankAccounts());
            $this->em()->flush();
        });

        $this->client->request('POST', '/invoices/recount', $values);

        self::assertSame('1,210.00 CZK', $this->nbsp($this->json()['payable']));
    }

    public function testNextNumberIsAPreviewOfTheSeriesOnThatDate(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/invoices/next-number', ['series' => $this->series->id(), 'date' => '2027-03-01']);

        self::assertResponseIsSuccessful();
        self::assertSame('{"number":"20270001","variableSymbol":"20270001"}', $this->client->getResponse()->getContent());
    }

    public function testNextNumberOfAFirmWithoutASeriesUsesTheDefault(): void
    {
        $this->signedIn(series: false);

        $this->client->request('GET', '/invoices/next-number', ['firm' => $this->firm->id(), 'date' => '2026-11-01']);

        self::assertStringContainsString('"number":"20260001"', (string) $this->client->getResponse()->getContent());
    }

    public function testNextNumberOfAForeignSeriesIs404AndABadDate422(): void
    {
        $this->signedIn();
        $foreign = $this->foreignFirm();

        $this->client->request('GET', '/invoices/next-number', ['firm' => $foreign->id(), 'date' => '2026-11-01']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/invoices/next-number', ['series' => '00000000-0000-7000-8000-000000000000', 'date' => '2026-11-01']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/invoices/next-number', ['series' => $this->series->id(), 'date' => 'x']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testForgedFirmSeriesOrCustomerIs404OrAFormError(): void
    {
        $this->signedIn();
        $foreign = $this->foreignFirm();
        $values = $this->filled();

        $this->save(array_merge($values, ['firmId' => $foreign->id()]));
        self::assertResponseStatusCodeSame(404);

        $this->save(array_merge($this->filled(), ['seriesId' => '00000000-0000-7000-8000-000000000000']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-error-summary]', $this->text('invoice.problem.series_unknown'));

        $this->save(array_merge($this->filled(), ['customerId' => '00000000-0000-7000-8000-000000000000']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-error-summary]', $this->text('invoice.problem.customer_unknown'));

        self::assertSame([], $this->invoices());
    }

    public function testASupplierCannotBeForgedIn(): void
    {
        $this->signedIn();
        $this->scoped(function (): void {
            $this->em()->getRepository(Party::class)->find($this->customer->id())?->assignRoles([PartyRole::Supplier]);
            $this->em()->flush();
        });

        $this->save($this->filled());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-error-summary]', $this->text('invoice.problem.customer_role'));
    }

    public function testAForeignInvoiceIs404(): void
    {
        $this->signedIn();
        $this->client->request('GET', '/invoices/00000000-0000-7000-8000-000000000000/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAWrongCsrfTokenIsRefused(): void
    {
        $this->signedIn();

        $this->save(array_merge($this->filled(), ['_token' => 'wrong']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testASubcontractorGets403(): void
    {
        $this->signedIn(Role::Subcontractor);
        $id = '019a0000-0000-7000-8000-000000000001';

        foreach ([['GET', '/invoices/new'], ['POST', '/invoices/new'], ['GET', '/invoices/'.$id.'/edit'], ['POST', '/invoices/'.$id.'/edit'], ['GET', '/invoices/'.$id.'/copy'], ['POST', '/invoices/recount'], ['GET', '/invoices/next-number?date=2026-11-01'], ['GET', '/invoices/customers?firm='.$this->firm->id()]] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $method.' '.$url);
        }
    }

    public function testAMemberWorksWithInvoices(): void
    {
        $this->signedIn(Role::Member);

        $this->client->request('GET', '/invoices/new');

        self::assertResponseIsSuccessful();
    }

    public function testEditShowsSnapshotAndNotCurrentContact(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->scoped(function (): void {
            $party = $this->em()->getRepository(Party::class)->find($this->customer->id());
            $party?->rename('Renamed Later a.s.');
            $this->em()->flush();
        });

        $values = $this->openEdit($invoice);

        self::assertResponseIsSuccessful();
        self::assertSame('Example Customer a.s.', $values['customerQuery']);
        self::assertSelectorTextContains('[data-customer-summary]', 'Customer Street 2');
        self::assertStringNotContainsString('Renamed Later', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('h1', '20260001');
    }

    public function testEditKeepsTheFirmAsTextAndSavesAChange(): void
    {
        $this->signedIn();
        $invoice = $this->issued();

        $values = $this->openEdit($invoice);
        self::assertSelectorNotExists('select[name="firmId"]');
        self::assertSelectorTextContains('[data-firm-text]', 'Example Firm s.r.o.');

        $values['description'] = 'Changed';
        $values['lines'][0]['price'] = '2000,00';
        $this->saveEdit($invoice, $values);

        self::assertResponseRedirects('/invoices');
        $stored = $this->stored($invoice);
        self::assertSame('Changed', $stored->description());
        self::assertSame(242000, $stored->payable()->minor());
        self::assertSame('20260001', $stored->number());
    }

    public function testAStaleSaveAsksToReload(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $stale = $this->openEdit($invoice);
        $newer = $this->openEdit($invoice);
        $newer['description'] = 'First save';
        $this->saveEdit($invoice, $newer);

        $stale['description'] = 'Second save';
        $this->saveEdit($invoice, $stale);

        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('[data-reload-prompt]', $this->text('invoice.problem.changed_meanwhile'));
        self::assertSelectorExists('[data-reload-prompt] a[href="/invoices/'.$invoice->id().'/edit"]');
        self::assertSame('First save', $this->stored($invoice)->description());
    }

    public function testChangingTheSeriesOnAnEditRenumbers(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $other = $this->scoped(function (): Series {
            $firm = $this->em()->getRepository(LegalEntity::class)->find($this->firm->id()) ?? throw new \LogicException('Firm.');
            $series = new Series($firm, 'Second', SeriesFormat::fromString('S{YYYY}{NNN}'), DocumentKind::Invoice);
            $this->em()->persist($series);
            $this->em()->flush();

            return $series;
        });
        $values = $this->openEdit($invoice);
        $values['seriesId'] = $other->id();

        $this->saveEdit($invoice, $values);

        $stored = $this->stored($invoice);
        self::assertSame('S2026001', $stored->number());
        self::assertSame('2026001', $stored->variableSymbol());
    }

    public function testSeriesAndNumberAreReadOnlyOnceDelivered(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->scoped(fn () => self::getContainer()->get(MarkDelivered::class)->mark($invoice->id()));

        $values = $this->openEdit($invoice);

        self::assertSelectorNotExists('[name="number"]:not([type="hidden"])');
        self::assertSelectorNotExists('select[name="seriesId"]');
        $values['description'] = 'Still editable';
        $this->saveEdit($invoice, $values);

        self::assertResponseRedirects();
        self::assertSame('Still editable', $this->stored($invoice)->description());
        self::assertSame('20260001', $this->stored($invoice)->number());
    }

    public function testATrashedInvoiceSaysWhyItCannotBeSaved(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->scoped(fn () => self::getContainer()->get(TrashInvoice::class)->trash($invoice->id(), 'Mistake'));

        $values = $this->openEdit($invoice);
        self::assertSelectorTextContains('main', $this->text('invoice.problem.trashed'));

        $this->saveEdit($invoice, $values);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEditingTheCustomerOnTheDocumentLeavesTheContact(): void
    {
        $this->signedIn();
        $values = $this->filled(['customerEdit' => '1', 'customer' => self::CUSTOMER]);
        $values['customer']['name'] = 'Only On This Document a.s.';

        $this->save($values);

        self::assertSame('Only On This Document a.s.', $this->onlyInvoice()->customer()->name);
        $this->scoped(fn () => self::assertSame('Example Customer a.s.', $this->em()->getRepository(Party::class)->find($this->customer->id())?->displayName()));
    }

    public function testABadVatIdOnTheDocumentIsTranslated(): void
    {
        $this->signedIn();
        $values = $this->filled(['customerEdit' => '1', 'customer' => self::CUSTOMER]);
        $values['customer']['vatId'] = '12';

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-error-summary]', $this->text('invoice.problem.customer_vat_id_invalid'));
    }

    public function testReverseChargeOfADomesticCustomerAsksForTheCode(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $values['lines'][0]['vat'] = 'reverse_charge';

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#lines-0-supplyCode[aria-invalid="true"]');

        $values = $this->currentValues();
        $values['lines'][0]['supplyCode'] = '4';
        $this->save($values);

        self::assertSame('4', $this->linesOf($this->onlyInvoice())[0]['code']);
    }

    public function testASupplyCodeShowsItsMeaning(): void
    {
        $this->signedIn();
        $this->openNew();

        self::assertStringContainsString('4 – Construction and assembly work', $this->client->getCrawler()->filter('#lines-0-supplyCode')->text());
    }

    public function testANonPayerFormHasNoVat(): void
    {
        $this->signedIn(status: VatStatus::NotRegistered);

        $values = $this->openNew();

        $main = $this->client->getCrawler()->filter('main');
        self::assertSelectorNotExists('[name="lines[0][priceType]"]:not([type="hidden"])');
        self::assertSelectorNotExists('select[name="lines[0][vat]"]');
        self::assertSelectorNotExists('[data-total="vat"]');
        self::assertStringNotContainsString('VAT', $main->text());

        $values = array_merge($values, ['customerId' => $this->customer->id(), 'customerQuery' => 'Example Customer a.s.', 'description' => 'Work']);
        $values['lines'][0] = array_merge($values['lines'][0], ['text' => 'Work', 'price' => '1000']);
        $this->save($values);

        $invoice = $this->onlyInvoice();
        self::assertTrue($invoice->vat()->isZero());
        self::assertSame(100000, $invoice->payable()->minor());
    }

    public function testAForgedVatCellOfAPayerIsRefused(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $values['lines'][0]['vat'] = 'none';

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->invoices());
    }

    public function testTheNewLineButtonWorksWithoutJavaScript(): void
    {
        $this->signedIn();
        $values = $this->filled(['_action' => 'add_line']);

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->invoices());
        self::assertSelectorNotExists('[data-error-summary]');
        self::assertCount(2, $this->currentValues()['lines']);
    }

    public function testRowsMoveAndGoWithoutJavaScript(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $values['lines'] = [$this->lineValues(['text' => 'First']), $this->lineValues(['text' => 'Second'])];

        $this->save(array_merge($values, ['_action' => 'move_up:1']));
        self::assertSame(['Second', 'First'], array_column($this->currentValues()['lines'], 'text'));

        $this->save(array_merge($this->currentValues(), ['_action' => 'move_down:0']));
        self::assertSame(['First', 'Second'], array_column($this->currentValues()['lines'], 'text'));

        $this->save(array_merge($this->currentValues(), ['_action' => 'remove_line:0']));
        self::assertSame(['Second'], array_column($this->currentValues()['lines'], 'text'));

        $this->save(array_merge($this->currentValues(), ['_action' => 'remove_line:0']));
        self::assertCount(1, $this->currentValues()['lines']);
    }

    public function testATypedNameActsAsAPickAndNeverIssues(): void
    {
        $this->signedIn();
        $this->scoped(function (): void {
            $party = $this->em()->getRepository(Party::class)->find($this->customer->id());
            $party?->changeInvoicingDefaults(new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::English, PaymentMethod::Cash, true));
            $this->em()->flush();
        });

        $this->save($this->filled(['customerId' => '', 'customerQuery' => 'Example Cust']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->invoices());
        $values = $this->currentValues();
        self::assertSame($this->customer->id(), $values['customerId']);
        self::assertSame('2026-12-01', $values['dueDate']);
        self::assertSame('cash', $values['paymentMethod']);
        self::assertSame('reverse_charge', $values['lines'][0]['vat']);
        self::assertSelectorTextContains('main', 'Example Customer a.s.');
    }

    public function testARequestWithoutAButtonIssuesNothing(): void
    {
        $this->signedIn();
        $values = $this->filled();
        unset($values['_action']);

        $this->client->request('POST', '/invoices/new', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->invoices());
    }

    public function testPdfAndSaveSavesThenOpensThePdf(): void
    {
        $this->signedIn();

        $this->save(array_merge($this->filled(), ['_action' => 'save_pdf']));

        $invoices = $this->invoices();
        self::assertCount(1, $invoices);
        self::assertResponseRedirects('/invoices/'.$invoices[0]->id().'/pdf');
        $this->client->followRedirect();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    public function testAFormCutOffIsRefusedWholeAndAButtonOfItDoesNothing(): void
    {
        $this->signedIn();
        $values = $this->filled();
        unset($values['_complete']);

        foreach (['save', 'save_pdf', 'add_line'] as $action) {
            $this->client->request('POST', '/invoices/new', array_merge($values, ['_action' => $action]));
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorExists('[data-cut-off]');
            self::assertSelectorNotExists('form#invoice-form');
        }
        self::assertSame([], $this->invoices());
    }

    public function testAnEditCutOffChangesNothing(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->openEdit($invoice);
        $values['description'] = 'Lost';
        unset($values['_complete']);

        $this->saveEdit($invoice, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-cut-off] a[href="/invoices/'.$invoice->id().'/edit"]');
        self::assertSame('Consulting', $this->stored($invoice)->description());
    }

    public function testRecountOfACutOffFormAnswers422(): void
    {
        $this->signedIn();
        $values = $this->openNew();
        unset($values['_complete']);

        $this->client->request('POST', '/invoices/recount', $values);

        self::assertResponseStatusCodeSame(422);
    }

    public function testMoreThanTheLimitOfLinesIsRefused(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $values['lines'] = array_fill(0, InvoiceFormReader::MAX_LINES + 1, $this->lineValues([]));

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-error-summary]', 'at most 100 lines');
        self::assertSame([], $this->invoices());
    }

    public function testTheLimitItselfSaves(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $values['lines'] = array_fill(0, InvoiceFormReader::MAX_LINES, $this->lineValues([]));

        $this->save($values);

        self::assertCount(InvoiceFormReader::MAX_LINES, $this->linesOf($this->onlyInvoice()));
    }

    public function testTheNewLineButtonStopsAtTheLimit(): void
    {
        $this->signedIn();
        $values = $this->filled(['_action' => 'add_line']);
        $values['lines'] = array_fill(0, InvoiceFormReader::MAX_LINES, $this->lineValues([]));

        $this->save($values);

        self::assertCount(InvoiceFormReader::MAX_LINES, $this->currentValues()['lines']);
        self::assertSelectorExists('button[value="add_line"][disabled]');
    }

    public function testANewLineOfAReverseChargeCustomerTakesTheTreatment(): void
    {
        $this->signedIn();
        $this->scoped(function (): void {
            $party = $this->em()->getRepository(Party::class)->find($this->customer->id());
            $party?->changeInvoicingDefaults(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true));
            $this->em()->flush();
        });

        $this->save($this->filled(['_action' => 'add_line']));

        self::assertSame('reverse_charge', $this->currentValues()['lines'][1]['vat']);
        self::assertSelectorExists('template option[value="reverse_charge"][selected]');
    }

    public function testAPickOfAForeignCustomerDropsTheSupplyCode(): void
    {
        $this->signedIn();
        $foreign = $this->scoped(function (): Party {
            $party = new Party(PartyKind::Company, 'Example German GmbH', null, VatId::fromString('DE123456789'), '');
            $party->changeInvoicingDefaults(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::English, PaymentMethod::BankTransfer, true));
            $this->em()->persist($party);
            $this->em()->persist(new PartyAddress($party, AddressType::Billing, new Address('Strasse 1', 'Berlin', '10115', 'DE')));
            $this->em()->flush();

            return $party;
        });
        $values = $this->filled();
        $values['lines'][0] = array_merge($values['lines'][0], ['vat' => 'reverse_charge', 'supplyCode' => '4']);

        $this->save(array_merge($values, ['_action' => 'pick:'.$foreign->id()]));

        self::assertSame('', $this->currentValues()['lines'][0]['supplyCode']);
        self::assertSame('reverse_charge', $this->currentValues()['lines'][0]['vat']);
    }

    public function testAPickOnAnEditKeepsTheRegimeOfTheDocument(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->scoped(function (): void {
            $firm = $this->em()->getRepository(LegalEntity::class)->find($this->firm->id()) ?? throw new \LogicException('Firm.');
            $firm->update($firm->name(), $firm->registrationNumber(), null, $firm->address(), VatStatus::NotRegistered, $firm->registerEntry(), $firm->bankAccounts());
            $party = $this->em()->getRepository(Party::class)->find($this->customer->id());
            $party?->changeInvoicingDefaults(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true));
            $this->em()->flush();
        });
        $values = $this->openEdit($invoice);

        $this->saveEdit($invoice, array_merge($values, ['_action' => 'pick:'.$this->customer->id()]));

        self::assertSame('reverse_charge', $this->currentValues()['lines'][0]['vat']);
    }

    public function testRecountOfAFirmOfAnUnsupportedCountryAnswers422(): void
    {
        $this->signedIn();
        $values = $this->openNew();
        $foreign = $this->scoped(function (): LegalEntity {
            $firm = new LegalEntity('Example Slovak s.r.o.', new RegistrationNumber('CZ', '12345687'), null, new Address('Example Street 1', 'Example City', '100 00', 'SK'), VatStatus::NotRegistered, 'Register', []);
            $this->em()->persist($firm);
            $this->em()->flush();

            return $firm;
        });
        $values['firmId'] = $foreign->id();

        $this->client->request('POST', '/invoices/recount', $values);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function forgedCopies(): iterable
    {
        yield 'a country that does not exist' => [['countryCode' => 'ZZ'], 'invoice.problem.country_invalid'];
        yield 'a non-email address' => [['email' => 'not-an-email'], 'invoice.problem.email_invalid'];
    }

    /**
     * @param array<string, string> $customer
     */
    #[DataProvider('forgedCopies')]
    public function testACopyOfTheCustomerIsChecked(array $customer, string $key): void
    {
        $this->signedIn();

        $this->save($this->filled(['customerEdit' => '1', 'customer' => array_merge(self::CUSTOMER, $customer)]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-error-summary]', $this->text($key));
        self::assertSelectorExists('[aria-invalid="true"]');
    }

    public function testConstantSymbolAcceptsDigitsOnly(): void
    {
        $this->signedIn();

        $this->save($this->filled(['constantSymbol' => '03a8']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-error-summary]', $this->text('invoice.problem.constant_symbol_invalid'));
    }

    public function testADateOutsideTheCenturyIsRefused(): void
    {
        $this->signedIn();

        $this->save($this->filled(['issueDate' => '0001-01-01', 'taxPointDate' => '0001-01-01', 'dueDate' => '0001-01-02']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->invoices());
    }

    public function testAHandSetDueDateIsRememberedThroughARefusedSave(): void
    {
        $this->signedIn();
        $values = $this->filled(['dueDate' => '2026-12-24', 'dueDateSet' => '1', 'customerId' => '', 'customerQuery' => '']);

        $this->save($values);

        self::assertSame('1', $this->currentValues()['dueDateSet']);
        self::assertSelectorExists('form[data-invoice-form-due-date-set-value="true"]');
    }

    public function testAPickKeepsAHandSetDueDate(): void
    {
        $this->signedIn();

        $this->save(array_merge($this->filled(['dueDate' => '2026-12-24', 'dueDateSet' => '1']), ['_action' => 'pick:'.$this->customer->id()]));

        self::assertSame('2026-12-24', $this->currentValues()['dueDate']);
    }

    public function testAnEmptyDescriptionStaysEmptyOnARefusedForm(): void
    {
        $this->signedIn();

        $this->save($this->filled(['description' => '', 'customerId' => '', 'customerQuery' => '']));

        self::assertSame('', $this->currentValues()['description']);
    }

    public function testTheInvoicePagesLeaveTurbo(): void
    {
        $this->signedIn();
        $this->openNew();

        self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');
        self::assertSelectorExists('meta[name="turbo-visit-control"][content="reload"]');
        self::assertSelectorExists('body[data-turbo="false"]');
        self::assertSelectorExists('form#invoice-form[data-turbo="false"]');
    }

    public function testASeriesThatMovedWithoutScriptSaysOnlyThatTheNumberChanged(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $second = $this->secondSeries();
        $values = $this->openEdit($invoice);

        $this->saveEdit($invoice, array_merge($values, ['seriesId' => $second->id()]));
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'Invoice saved as S2026001. The number changed.');
    }

    public function testAHiddenCodeDoesNotSurviveARerender(): void
    {
        $this->signedIn();
        $values = $this->filled(['customerEdit' => '1', 'customer' => array_merge(self::CUSTOMER, ['vatId' => 'DE123456789'])]);
        $values['lines'][0] = array_merge($values['lines'][0], ['vat' => 'reverse_charge', 'supplyCode' => '4']);

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        $values = $this->currentValues();
        self::assertSame('', $values['lines'][0]['supplyCode']);

        $this->save($values);
        self::assertResponseRedirects();
        self::assertCount(1, $this->invoices());
    }

    public function testAFirmChangeUsesTheDateOfTheFormAndClearsWhatWasTyped(): void
    {
        $this->signedIn();
        $second = $this->secondFirm(VatStatus::Registered);
        $values = $this->filled(['issueDate' => '2027-02-10', 'number' => 'X-1', 'variableSymbol' => '777', 'firmId' => $second->id()]);

        $this->save(array_merge($values, ['_action' => 'refresh']));

        $reopened = $this->currentValues();
        self::assertSame('20270001', $reopened['number'] ?? null);
        self::assertSame('20270001', $reopened['numberPeek'] ?? null);
        self::assertSame('20270001', $reopened['variableSymbol']);
        self::assertSelectorTextContains('main', 'The firm changed');
    }

    public function testAPayerFirmAlignsTheLinesOfAReverseChargeCustomer(): void
    {
        $this->signedIn(status: VatStatus::NotRegistered);
        $second = $this->secondFirm(VatStatus::Registered);
        $this->scoped(function (): void {
            $party = $this->em()->getRepository(Party::class)->find($this->customer->id());
            $party?->changeInvoicingDefaults(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true));
            $this->em()->flush();
        });
        $values = $this->filled(['firmId' => $second->id()]);

        $this->save(array_merge($values, ['_action' => 'refresh']));

        self::assertSame('reverse_charge', $this->currentValues()['lines'][0]['vat']);
    }

    public function testAPickChecksTheContactIsACustomerOfTheFirm(): void
    {
        $this->signedIn();
        $supplier = $this->scoped(function (): Party {
            $party = new Party(PartyKind::Person, 'Example Vendor', null, null, '');
            $party->assignRoles([PartyRole::Supplier]);
            $this->em()->persist($party);
            $this->em()->flush();

            return $party;
        });

        $this->save(array_merge($this->filled(), ['_action' => 'pick:'.$supplier->id()]));

        self::assertSame($this->customer->id(), $this->currentValues()['customerId']);
        self::assertSelectorTextContains('[data-error-summary]', $this->text('invoice.problem.customer_role'));
    }

    public function testAKeptTokenOutlivesAnyNumberOfNewForms(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $this->save($values);
        $first = (string) $this->client->getResponse()->headers->get('Location');
        for ($form = 0; $form < 35; ++$form) {
            $this->client->request('GET', '/invoices/new');
        }

        $this->save($values);

        self::assertResponseRedirects($first);
        self::assertCount(1, $this->invoices());
    }

    public function testSeveralMatchesOfferAChoiceWithoutJavaScript(): void
    {
        $this->signedIn();
        $second = $this->scoped(function (): Party {
            $party = new Party(PartyKind::Person, 'Example Customer Two a.s.', null, null, '');
            $this->em()->persist($party);
            $this->em()->persist(new PartyAddress($party, AddressType::Billing, new Address('Second Street', 'Customer City', '200 00', 'CZ')));
            $party->changeInvoicingDefaults(new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::English, PaymentMethod::Cash, true));
            $this->em()->flush();

            return $party;
        });

        $this->save($this->filled(['customerId' => '', 'customerQuery' => 'Example Customer']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->invoices());
        self::assertSelectorTextContains('[data-customer-candidates]', 'Example Customer Two a.s.');

        $this->save(array_merge($this->currentValues(), ['_action' => 'pick:'.$second->id()]));

        $values = $this->currentValues();
        self::assertSame($second->id(), $values['customerId']);
        self::assertSame('2026-12-01', $values['dueDate']);
        self::assertSame('cash', $values['paymentMethod']);
        self::assertSame('en', $values['language']);
        self::assertSame('reverse_charge', $values['lines'][0]['vat']);
    }

    public function testChangingTheFirmReopensItsValues(): void
    {
        $this->signedIn();
        $second = $this->secondFirm(VatStatus::NotRegistered);
        $values = $this->filled();
        $values['firmId'] = $second->id();

        $this->save(array_merge($values, ['_action' => 'refresh']));

        $reopened = $this->currentValues();
        self::assertSame($second->id(), $reopened['firmId']);
        self::assertSame(self::OTHER_IBAN, $reopened['bankAccount']);
        self::assertSame('none', $reopened['lines'][0]['vat']);
        self::assertSame('Work', $reopened['lines'][0]['text']);
        self::assertSame($this->customer->id(), $reopened['customerId']);
    }

    public function testACustomerWhoDoesNotFitTheNewFirmIsDropped(): void
    {
        $this->signedIn();
        $second = $this->secondFirm(VatStatus::Registered);
        $this->scoped(function () use ($second): void {
            $firm = $this->em()->getRepository(LegalEntity::class)->find($second->id()) ?? throw new \LogicException('Firm.');
            $party = $this->em()->getRepository(Party::class)->find($this->customer->id()) ?? throw new \LogicException('Party.');
            $settings = new \App\Party\Domain\PartyFirmSettings($party, $firm);
            $settings->changeRoles([PartyRole::Supplier]);
            $this->em()->persist($settings);
            $this->em()->flush();
        });
        $values = $this->filled();
        $values['firmId'] = $second->id();

        $this->save(array_merge($values, ['_action' => 'refresh']));

        self::assertSame('', $this->currentValues()['customerId']);
    }

    public function testTwoFirmsShowTheIssuerAndTheLastUsedOneOpens(): void
    {
        $this->signedIn();
        $second = $this->secondFirm(VatStatus::NotRegistered);

        $this->openNew(['firm' => $second->id()]);
        self::assertSame($second->id(), $this->client->getCrawler()->filter('select[name="firmId"] option[selected]')->attr('value'));

        $this->client->request('GET', '/invoices/new', ['firm' => $this->foreignFirm()->id()]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOneFirmIsPlainText(): void
    {
        $this->signedIn();
        $this->openNew();

        self::assertSelectorNotExists('select[name="firmId"]');
        self::assertSelectorTextContains('[data-firm-text]', 'Example Firm s.r.o.');
    }

    public function testAForeignCurrencyContactShowsANotice(): void
    {
        $this->signedIn();
        $this->scoped(function (): void {
            $party = $this->em()->getRepository(Party::class)->find($this->customer->id());
            $party?->changeInvoicingDefaults(new InvoicingDefaults(14, Currency::EUR, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false));
            $this->em()->flush();
        });
        $values = $this->filled();

        $this->save(array_merge($values, ['_action' => 'pick:'.$this->customer->id()]));

        self::assertSelectorTextContains('[data-currency-notice]', 'EUR');
    }

    public function testRoundingIsATotalRow(): void
    {
        $this->signedIn();
        $this->scoped(function (): void {
            $firm = $this->em()->getRepository(LegalEntity::class)->find($this->firm->id()) ?? throw new \LogicException('Firm.');
            $settings = FirmInvoicing::standard($firm);
            $settings->change(TotalRounding::WholeUnit, '', '', '', '', '', '');
            self::getContainer()->get(FirmInvoicingSettings::class)->save($settings);
            $this->em()->flush();
        });
        $values = $this->filled();
        $values['lines'][0]['price'] = '100,10';
        $this->save($values);
        $this->openEdit($this->onlyInvoice());

        self::assertSame('-0.12 CZK', $this->nbsp($this->client->getCrawler()->filter('[data-total="rounding"]')->text()));
    }

    public function testATextLikeMarkupStaysText(): void
    {
        $this->signedIn();
        $values = $this->filled(['description' => '<img src="http://example.com/x">']);
        $values['lines'][0]['text'] = '<img src="http://example.com/x">';

        $this->save($values);
        $this->client->followRedirect();

        self::assertSelectorNotExists('main img');
    }

    public function testEveryProblemHasItsMessageInBothLanguages(): void
    {
        $found = [];
        preg_match_all('/invoice\.problem\.[a-z_]+/', implode(' ', array_map(file_get_contents(...), array_filter((array) glob(\dirname(__DIR__, 3).'/src/Invoicing/*/*.php'), is_string(...)))).implode(' ', array_map(file_get_contents(...), array_filter((array) glob(\dirname(__DIR__, 3).'/src/Invoicing/*/*/*.php'), is_string(...)))), $found);
        $keys = array_unique($found[0]);
        self::assertGreaterThan(30, \count($keys));

        $translator = self::getContainer()->get('translator');
        foreach (['cs', 'en'] as $locale) {
            foreach ($keys as $key) {
                self::assertNotSame($key, $translator->trans($key, locale: $locale), $locale.' '.$key);
            }
        }
    }

    /**
     * @param array<string, string> $query
     *
     * @return Form
     */
    private function openNew(array $query = []): array
    {
        $this->client->request('GET', '/invoices/new', $query);

        return $this->currentValues();
    }

    /**
     * @return Form
     */
    private function openEdit(Invoice $invoice): array
    {
        $this->client->request('GET', '/invoices/'.$invoice->id().'/edit');

        return $this->currentValues();
    }

    /**
     * @return Form
     */
    private function openCopy(Invoice $invoice): array
    {
        $this->client->request('GET', '/invoices/'.$invoice->id().'/copy');

        return $this->currentValues();
    }

    /**
     * Invoices, counters and audit entries.
     *
     * @return list<int>
     */
    private function rowCounts(): array
    {
        return $this->scoped(fn (): array => array_map(
            fn (string $class): int => (int) $this->em()->createQuery('SELECT COUNT(e) FROM '.$class.' e')->getSingleScalarResult(),
            [Invoice::class, SeriesCounter::class, AuditEntry::class],
        ));
    }

    private function archiveFirm(): void
    {
        $this->scoped(function (): void {
            $firm = $this->em()->getRepository(LegalEntity::class)->find($this->firm->id()) ?? throw new \LogicException('Firm.');
            $firm->archive(new \DateTimeImmutable('2026-11-01'));
            $this->em()->flush();
        });
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

    /**
     * What the browser would send from the form on screen.
     *
     * @return Form
     */
    private function currentValues(): array
    {
        /** @var Form $values */
        $values = $this->client->getCrawler()->filter('form#invoice-form')->form()->getPhpValues();

        return $values;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return Form
     */
    private function filled(array $changes = []): array
    {
        $values = $this->openNew();
        $values['customerId'] = $this->customer->id();
        $values['customerQuery'] = 'Example Customer a.s.';
        $values['description'] = 'Consulting';
        $values['lines'][0] = array_merge($values['lines'][0], ['text' => 'Work', 'price' => '1000,00', 'quantity' => '1', 'unit' => 'h']);

        /** @var Form $filled */
        $filled = array_merge($values, $changes);

        return $filled;
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function lineValues(array $changes): array
    {
        return array_merge(['text' => 'Work', 'price' => '100,00', 'quantity' => '1', 'unit' => 'h', 'priceType' => 'without_vat', 'vat' => 'base', 'supplyCode' => '', 'discount' => ''], $changes);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function save(array $values): void
    {
        $this->client->request('POST', '/invoices/new', array_merge(['_action' => 'save'], $values));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function saveEdit(Invoice $invoice, array $values): void
    {
        $this->client->request('POST', '/invoices/'.$invoice->id().'/edit', array_merge(['_action' => 'save'], $values));
    }

    private function issued(): Invoice
    {
        $this->save($this->filled());

        return $this->onlyInvoice();
    }

    private function onlyInvoice(): Invoice
    {
        $invoices = $this->invoices();
        self::assertCount(1, $invoices);

        return $invoices[0];
    }

    /**
     * @return list<Invoice> in the order of their numbers
     */
    private function invoices(): array
    {
        return $this->scoped(function (): array {
            $this->em()->clear();

            return $this->em()->createQuery('SELECT i FROM '.Invoice::class.' i ORDER BY i.numberKey')->getResult();
        });
    }

    private function stored(Invoice $invoice): Invoice
    {
        return $this->scoped(function () use ($invoice): Invoice {
            $this->em()->clear();

            return self::getContainer()->get(InvoiceStore::class)->get($invoice->id());
        });
    }

    /**
     * @return list<array{code: ?string}>
     */
    private function linesOf(Invoice $invoice): array
    {
        return $this->scoped(function () use ($invoice): array {
            $store = self::getContainer()->get(InvoiceStore::class);

            return array_map(static fn ($line): array => ['code' => $line->supplyCode()], $store->lines($store->get($invoice->id())));
        });
    }

    /**
     * Runs outside a request, which has no tenant of its own.
     *
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

    private function signedIn(Role $role = Role::Owner, VatStatus $status = VatStatus::Registered, bool $series = true, ?Address $address = null): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], role: $role);
        $this->context->switchTo($this->tenant);
        $vatId = VatStatus::Registered === $status ? VatId::fromString('CZ12345695') : null;
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), $vatId, $address ?? new Address('Example Street 1', 'Example City', '100 00', 'CZ'), $status, 'Example register, entry C 1', [self::IBAN]);
        $this->em()->persist($this->firm);
        if ($series) {
            $this->series = new Series($this->firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
            $this->series->markDefault();
            $this->em()->persist($this->series);
        }
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '+420 000 000 000');
        $this->em()->persist($this->customer);
        $this->em()->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em()->persist(new PartyEmail($this->customer, 'billing@example.com', EmailPurpose::Invoices));
        $this->em()->flush();
        $this->context->reset();
        $this->browser->signIn();
    }

    private function secondSeries(): Series
    {
        return $this->scoped(function (): Series {
            $firm = $this->em()->getRepository(LegalEntity::class)->find($this->firm->id()) ?? throw new \LogicException('Firm.');
            $series = new Series($firm, 'Second', SeriesFormat::fromString('S{YYYY}{NNN}'), DocumentKind::Invoice);
            $this->em()->persist($series);
            $this->em()->flush();

            return $series;
        });
    }

    private function secondFirm(VatStatus $status): LegalEntity
    {
        return $this->scoped(function () use ($status): LegalEntity {
            $vatId = VatStatus::Registered === $status ? VatId::fromString('CZ12345687') : null;
            $firm = new LegalEntity('Example Second s.r.o.', new RegistrationNumber('CZ', '12345687'), $vatId, new Address('Example Street 2', 'Example City', '100 00', 'CZ'), $status, 'Example register, entry C 2', [self::OTHER_IBAN]);
            $this->em()->persist($firm);
            $this->em()->flush();

            return $firm;
        });
    }

    private function foreignFirm(): LegalEntity
    {
        $tenant = $this->browser->fixtures->tenant('Foreign Tenant '.bin2hex(random_bytes(2)));
        $this->context->switchTo($tenant);
        $firm = new LegalEntity('Example Foreign s.r.o.', new RegistrationNumber('CZ', '12345679'), null, new Address('Example Street 3', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, 'Register', []);
        $this->em()->persist($firm);
        $this->em()->flush();
        $this->context->reset();

        return $firm;
    }

    /**
     * @return Figures
     */
    private function json(): array
    {
        /** @var Figures $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function nbsp(string $text): string
    {
        return str_replace(["\u{a0}", "\u{202f}"], ' ', $text);
    }

    private function text(string $key): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, locale: 'en');
    }

    private function em(): EntityManagerInterface
    {
        return $this->browser->em();
    }
}
