<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
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

/**
 * @phpstan-type Form array{lines: list<array<string, string>>, firmId: string, seriesId: string, customerId: string, customerQuery: string, description: string, orderNumber: string, textBefore: string, textAfter: string, paymentMethod: string, bankAccount: string, firstIssueOn: string, unit: string, every: string, taxPoint: string, dueDays: string, end: string, endOn: string, endAfter: string, delivery: string, version: string, copyOf: string, ...}
 */
final class RecurringFormControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

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
        Clock::set(new MockClock('2026-11-02 11:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheNewFormOpensWithTheScheduleDefaults(): void
    {
        $this->signedIn();

        $values = $this->openNew();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'New recurring invoice');
        self::assertSelectorExists('main [data-action-bar]');
        self::assertSelectorNotExists('#number');
        self::assertSelectorNotExists('#issueDate');
        self::assertSame('2026-11-02', $values['firstIssueOn']);
        self::assertSame('month', $values['unit']);
        self::assertSame('1', $values['every']);
        self::assertSame('issue_date', $values['taxPoint']);
        self::assertSame('14', $values['dueDays']);
        self::assertSame('never', $values['end']);
        self::assertSame('send', $values['delivery']);
        self::assertSame($this->series->id(), $values['seriesId']);
        self::assertCount(1, $values['lines']);
        self::assertSelectorExists('[data-first-issue-today]');
    }

    public function testCreatingStoresTheTemplateAndGoesToTheList(): void
    {
        $this->signedIn();
        $values = $this->filled(['description' => 'Hosting {month/year}', 'orderNumber' => 'PO-{year}', 'firstIssueOn' => '2026-12-31', 'unit' => 'month', 'every' => '3', 'onLastDayOfMonth' => '1', 'taxPoint' => 'last_day_of_month', 'dueDays' => '30', 'end' => 'after_count', 'endAfter' => '4', 'delivery' => 'review']);

        $this->save($values);

        $template = $this->onlyTemplate();
        self::assertResponseRedirects('/invoices/recurring');
        $row = $this->templateRow($template->id());
        self::assertSame('2026-12-31', $row['first_issue_on']);
        self::assertSame('2026-12-31', $row['next_occurrence']);
        self::assertSame('month', $row['recurrence_unit']);
        self::assertSame(3, $row['recurrence_every']);
        self::assertTrue((bool) $row['on_last_day_of_month']);
        self::assertSame('after_count', $row['recurrence_end']);
        self::assertSame(4, $row['end_after']);
        self::assertSame('last_day_of_month', $row['tax_point']);
        self::assertSame(30, $row['due_days']);
        self::assertSame('review', $row['delivery']);
        self::assertSame('Hosting {month/year}', $row['description']);
        self::assertSame('PO-{year}', $row['order_number']);
        self::assertSame($this->customer->id(), $row['customer_id']);
        self::assertSame($this->firm->id(), $row['legal_entity_id']);
        self::assertNotNull($row['owner_id'], 'whoever creates a template owns it');
        $lines = $this->lineRows($template->id());
        self::assertCount(1, $lines);
        self::assertSame('Hosting {month/year}', $lines[0]['text']);
        self::assertSame(120000, $lines[0]['price_minor']);

        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role=status]', 'The recurring invoice is saved. The next invoice is issued on 2026-12-31.');
        self::assertSelectorExists('tbody a[href="/invoices/recurring/'.$template->id().'/edit"]', 'the list leads back to it');
    }

    public function testFromAnInvoiceCopiesCustomerLinesAndTexts(): void
    {
        $this->signedIn();
        $source = $this->issued();

        $values = $this->openNew(['from' => $source->id()]);

        self::assertResponseIsSuccessful();
        self::assertSame($this->customer->id(), $values['customerId']);
        self::assertSame('Example Customer a.s.', $values['customerQuery']);
        self::assertSame('Consulting', $values['description']);
        self::assertSame('PO-7', $values['orderNumber']);
        self::assertSame('Before the lines', $values['textBefore']);
        self::assertSame('After the lines', $values['textAfter']);
        self::assertSame($this->series->id(), $values['seriesId']);
        self::assertSame('21', $values['dueDays'], 'the due days of the source invoice');
        self::assertCount(2, $values['lines']);
        self::assertSame('Work', $values['lines'][0]['text']);
        self::assertSame('Travel', $values['lines'][1]['text']);
        self::assertSame('10', $values['lines'][1]['discount']);
        self::assertSame($source->id(), $values['copyOf']);
        self::assertSame(0, $this->templateCount(), 'opening the form writes nothing');

        $this->save($values);

        self::assertSame(1, $this->templateCount());
        $template = $this->onlyTemplate();
        self::assertCount(2, $this->lineRows($template->id()));
        self::assertSame('Before the lines', $this->templateRow($template->id())['text_before']);
    }

    public function testAnInvoiceOfAnotherTenantIsUnknown(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/invoices/recurring/new', ['from' => '00000000-0000-7000-8000-000000000000']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheInvoiceListOffersARecurringInvoice(): void
    {
        $this->signedIn();
        $source = $this->issued();

        $this->client->request('GET', '/invoices');

        self::assertSelectorExists('a[href="/invoices/recurring/new?from='.$source->id().'"]');
    }

    public function testErrorsKeepWhatWasTyped(): void
    {
        $this->signedIn();
        $values = $this->filled(['description' => 'Kept {month}', 'firstIssueOn' => '2026-10-01', 'every' => '0', 'end' => 'on_date', 'endOn' => '']);
        $values['lines'][0]['price'] = 'abc';

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-error-summary]');
        $summary = $this->client->getCrawler()->filter('[data-error-summary]')->text();
        self::assertStringContainsString('Repeat every', $summary);
        self::assertStringContainsString('Last issue', $summary);
        self::assertStringContainsString('Price', $summary);
        self::assertSelectorExists('a[href="#every"][data-error-link]');
        self::assertSelectorExists('a[href="#endOn"][data-error-link]');
        $kept = $this->currentValues();
        self::assertSame('Kept {month}', $kept['description']);
        self::assertSame('0', $kept['every']);
        self::assertSame('on_date', $kept['end']);
        self::assertSame('abc', $kept['lines'][0]['price']);
        self::assertSame(0, $this->templateCount());
    }

    public function testAFirstIssueInThePastIsRefusedByTheRules(): void
    {
        $this->signedIn();

        $this->save($this->filled(['firstIssueOn' => '2026-11-01']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('a[href="#firstIssueOn"][data-error-link]');
        self::assertSelectorExists('#firstIssueOn[aria-invalid="true"]');
        self::assertSame(0, $this->templateCount());
    }

    public function testRowActionsWorkWithoutJavaScript(): void
    {
        $this->signedIn();
        $values = $this->filled();

        $this->client->request('POST', '/invoices/recurring/new', array_merge($values, ['_action' => 'add_line']));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(2, $this->currentValues()['lines']);
        self::assertSame(0, $this->templateCount());
    }

    public function testATypedNameIsAPickNeverASave(): void
    {
        $this->signedIn();
        $values = $this->filled(['customerId' => '', 'customerQuery' => 'Example Customer']);

        $this->save($values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($this->customer->id(), $this->currentValues()['customerId']);
        self::assertSame(0, $this->templateCount());
    }

    public function testEditStoresTheChange(): void
    {
        $this->signedIn();
        $template = $this->template();
        $values = $this->openEdit($template);
        self::assertSame('Hosting {month/year}', $values['description']);
        self::assertSame('2026-12-01', $values['firstIssueOn']);
        self::assertSame('1', $values['version']);

        $values['description'] = 'Support {quarter}/{year}';
        $values['unit'] = 'year';
        $this->saveEdit($template, $values);

        self::assertResponseRedirects('/invoices/recurring');
        $row = $this->templateRow($template->id());
        self::assertSame('Support {quarter}/{year}', $row['description']);
        self::assertSame('year', $row['recurrence_unit']);
        self::assertSame(2, $row['version']);
    }

    public function testEditWithAStaleVersionShowsTheConflict(): void
    {
        $this->signedIn();
        $template = $this->template();
        $values = $this->openEdit($template);
        $other = $this->openEdit($template);
        $other['description'] = 'Saved elsewhere';
        $this->saveEdit($template, $other);

        $values['description'] = 'Mine';
        $this->saveEdit($template, $values);

        self::assertResponseStatusCodeSame(409);
        self::assertSelectorExists('[data-reload-prompt] a[href="/invoices/recurring/'.$template->id().'/edit"]');
        self::assertSame('Mine', $this->currentValues()['description']);
        self::assertSame('Saved elsewhere', $this->templateRow($template->id())['description']);
    }

    public function testAnotherTenantsTemplateIs404(): void
    {
        $this->signedIn();
        $template = $this->foreignTemplate();

        $this->client->request('GET', '/invoices/recurring/'.$template->id().'/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testThePreviewShowsFiveDatesForMonthlyOn31st(): void
    {
        $this->signedIn();

        $this->preview($this->filled(['firstIssueOn' => '2027-01-31']));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#recurring-preview');
        self::assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31'], $this->previewDates());
    }

    public function testThePreviewStopsAtTheEndRule(): void
    {
        $this->signedIn();

        $this->preview($this->filled(['firstIssueOn' => '2026-12-01', 'end' => 'after_count', 'endAfter' => '2']));

        self::assertSame(['2026-12-01', '2027-01-01'], $this->previewDates());
        self::assertSelectorTextContains('[data-preview-end]', 'Repeating ends');
    }

    public function testThePreviewResolvesVariables(): void
    {
        $this->signedIn();

        $this->preview($this->filled(['firstIssueOn' => '2026-12-01', 'description' => '{document_number}: hosting {month/year}']));

        $descriptions = $this->client->getCrawler()->filter('[data-preview-description]')->each(static fn ($node): string => trim($node->text()));
        self::assertSame('[invoice number]: hosting 12/2026', $descriptions[0]);
        self::assertSame('[invoice number]: hosting 1/2027', $descriptions[1]);
    }

    public function testThePreviewAnswers422WithTheFieldErrorsItCanShow(): void
    {
        $this->signedIn();

        $this->preview($this->filled(['every' => '100']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#recurring-preview [data-preview-problem]');
        self::assertSelectorTextContains('[data-preview-problem]', 'Repeat every');
    }

    public function testThePreviewWritesNothing(): void
    {
        $this->signedIn();

        $this->preview($this->filled());

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->templateCount());
    }

    public function testDoubleSubmitCreatesOne(): void
    {
        $this->signedIn();
        $values = $this->filled(['firstIssueOn' => '2026-12-01']);

        $this->save($values);
        $this->save($values);

        self::assertSame(1, $this->templateCount());
        self::assertResponseRedirects('/invoices/recurring');
    }

    public function testStaffOnly(): void
    {
        $this->signedIn(Role::Subcontractor);

        $this->client->request('GET', '/invoices/recurring/new');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/invoices/recurring/preview');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAForgedTokenIsRefused(): void
    {
        $this->signedIn();
        $values = $this->filled();
        $values['_token'] = 'forged';

        $this->save($values);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->templateCount());
    }

    /**
     * @param array<string, string> $query
     *
     * @return Form
     */
    private function openNew(array $query = []): array
    {
        $this->client->request('GET', '/invoices/recurring/new', $query);

        return $this->currentValues();
    }

    /**
     * @return Form
     */
    private function openEdit(RecurringInvoice $template): array
    {
        $this->client->request('GET', '/invoices/recurring/'.$template->id().'/edit');
        self::assertResponseIsSuccessful();

        return $this->currentValues();
    }

    /**
     * @return Form
     */
    private function currentValues(): array
    {
        /** @var Form $values */
        $values = $this->client->getCrawler()->filter('form#recurring-form')->form()->getPhpValues();

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
        $values['description'] = 'Hosting';
        $values['lines'][0] = array_merge($values['lines'][0], ['text' => 'Hosting {month/year}', 'price' => '1200,00', 'quantity' => '1', 'unit' => 'ks']);

        /** @var Form $filled */
        $filled = array_merge($values, $changes);

        return $filled;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function save(array $values): void
    {
        $this->client->request('POST', '/invoices/recurring/new', array_merge(['_action' => 'save'], $values));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function saveEdit(RecurringInvoice $template, array $values): void
    {
        $this->client->request('POST', '/invoices/recurring/'.$template->id().'/edit', array_merge(['_action' => 'save'], $values));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function preview(array $values): void
    {
        $this->client->request('POST', '/invoices/recurring/preview', $values, server: ['HTTP_TURBO_FRAME' => 'recurring-preview']);
    }

    /**
     * @return list<string>
     */
    private function previewDates(): array
    {
        return $this->client->getCrawler()->filter('[data-preview-issue]')->each(static fn ($node): string => trim($node->text()));
    }

    private function issued(): Invoice
    {
        return $this->scoped(function (): Invoice {
            $line = static fn (string $text, string $price, string $discount): LineInput => new LineInput($text, Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString($discount));
            $day = new \DateTimeImmutable('2026-10-15');

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($this->firm->id(), $this->series->id(), null, $this->customer->id(), null, $day, $day, $day->modify('+21 days'), PaymentMethod::BankTransfer, self::IBAN, '', '', 'PO-7', 'Consulting', 'Before the lines', 'After the lines', DocumentLanguage::Czech, [$line('Work', '1000.00', '0'), $line('Travel', '200.00', '10')], true));
        });
    }

    private function template(): RecurringInvoice
    {
        return $this->scoped(function (): RecurringInvoice {
            $line = new LineInput('Hosting {month/year}', Quantity::fromString('1'), 'ks', Money::of('1200.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
            $recurrence = new Recurrence(new \DateTimeImmutable('2026-12-01', new \DateTimeZone('Europe/Prague')), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null);

            return self::getContainer()->get(SaveRecurringInvoice::class)->create(new RecurringInput($this->firm->id(), $this->series->id(), $this->customer->id(), PaymentMethod::BankTransfer, self::IBAN, '', '', 'Hosting {month/year}', '', '', DocumentLanguage::Czech, [$line], $recurrence, TaxPointRule::IssueDate, 14, RecurringDelivery::Send));
        });
    }

    private function foreignTemplate(): RecurringInvoice
    {
        $own = [$this->tenant, $this->firm, $this->series, $this->customer];
        $this->tenant = $this->browser->fixtures->tenant('Foreign Tenant '.bin2hex(random_bytes(2)));
        $this->scoped(function (): void {
            $this->firm = new LegalEntity('Example Foreign s.r.o.', new RegistrationNumber('CZ', '12345679'), VatId::fromString('CZ12345679'), new Address('Example Street 3', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Register', [self::IBAN]);
            $this->em()->persist($this->firm);
            $this->series = new Series($this->firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
            $this->series->markDefault();
            $this->em()->persist($this->series);
            $this->customer = new Party(PartyKind::Company, 'Foreign Customer a.s.', new RegistrationNumber('CZ', '87654320'), null, '');
            $this->em()->persist($this->customer);
            $this->em()->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
            $this->em()->flush();
        });
        try {
            return $this->template();
        } finally {
            [$this->tenant, $this->firm, $this->series, $this->customer] = $own;
        }
    }

    private function onlyTemplate(): RecurringInvoice
    {
        return $this->scoped(function (): RecurringInvoice {
            $this->em()->clear();
            $templates = $this->em()->getRepository(RecurringInvoice::class)->findAll();
            self::assertCount(1, $templates);

            return $templates[0];
        });
    }

    private function templateCount(): int
    {
        return \count($this->em()->getConnection()->fetchFirstColumn('SELECT id FROM recurring_invoice'));
    }

    /**
     * @return array<string, mixed>
     */
    private function templateRow(string $id): array
    {
        $this->em()->clear();
        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM recurring_invoice WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lineRows(string $id): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM recurring_invoice_line WHERE recurring_invoice_id = :id ORDER BY position', ['id' => $id]);
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

    private function signedIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant '.bin2hex(random_bytes(3))], role: $role);
        $this->context->switchTo($this->tenant);
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1', [self::IBAN]);
        $this->em()->persist($this->firm);
        $this->series = new Series($this->firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->series->markDefault();
        $this->em()->persist($this->series);
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '+420 000 000 000');
        $this->em()->persist($this->customer);
        $this->em()->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em()->persist(new PartyEmail($this->customer, 'billing@example.com', EmailPurpose::Invoices));
        $this->em()->flush();
        $this->context->reset();
        $this->browser->signIn();
    }

    private function em(): EntityManagerInterface
    {
        return $this->browser->em();
    }
}
