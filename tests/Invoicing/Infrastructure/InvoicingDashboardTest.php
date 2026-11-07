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
use App\Tests\Party\Support\FirmAccounts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The invoicing section of the start page. Today is 20 November 2026.
 */
final class InvoicingDashboardTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const string SECOND_IBAN = 'CZ5508000000001234567899';

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

    public function testWithoutDocumentsThePlaceholderStays(): void
    {
        $this->signedIn();
        // Another tenant's documents show nowhere.
        $this->foreignInvoice();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Your dashboard is on its way');
        self::assertCount(0, $crawler->filter('[data-dashboard-invoicing]'));
    }

    public function testTheOverviewShowsTheNumbersAndLinksToTheirLists(): void
    {
        $this->signedIn();
        $this->issue('2026-11-02', '2026-11-15', '1000.00');
        $this->issue('2026-10-05', '2026-12-31', '2000.00');
        $this->foreignInvoice();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('main [data-dashboard-invoicing] select[name="firm"]'));
        self::assertCount(0, $crawler->filter('main .empty-state, main [data-empty-state]'));
        self::assertSame([
            ['Invoiced – November 2026', '1,210.00 Kč', 'Base 1,000.00 Kč', '/invoices?month=2026-11'],
            ['Invoiced – October 2026', '2,420.00 Kč', 'Base 2,000.00 Kč', '/invoices?month=2026-10'],
            ['Unpaid', '3,630.00 Kč', '2 documents', '/invoices?tab=unpaid'],
            ['Overdue', '1,210.00 Kč', '1 document', '/invoices?tab=overdue'],
        ], $this->kpis($crawler));
        self::assertSame([
            ['Output VAT – November 2026', '210.00 Kč', '/invoices?month=2026-11'],
            ['Output VAT – October 2026', '420.00 Kč', '/invoices?month=2026-10'],
            ['Overdue up to 30 days', '1,210.00 Kč', '/invoices?tab=overdue'],
            ['Overdue 31–60 days', '0.00 Kč', '/invoices?tab=overdue'],
            ['Overdue over 60 days', '0.00 Kč', '/invoices?tab=overdue'],
        ], $crawler->filter('main [data-dashboard-invoicing] dl > div')->each(fn (Crawler $fact): array => [$fact->filter('dt')->text(), $this->plain($fact->filter('dd')->text()), (string) $fact->filter('dd a')->attr('href')]));
        $debtor = $crawler->filter('[data-dashboard-debtors] li');
        self::assertCount(1, $debtor);
        self::assertSame('Example Customer a.s. 2 documents 3,630.00 Kč', $this->plain($debtor->text()));
        self::assertSame('/invoices?tab=unpaid&q=Example%20Customer%20a.s.', $debtor->filter('a')->attr('href'));
        $pending = $crawler->filter('[data-dashboard-pending] [role="status"]');
        self::assertCount(1, $pending);
        self::assertStringContainsString('October 2026 has not gone to the accountant yet (1 document)', $pending->text());
        self::assertSame('/invoices/export?firm='.$this->firm->id().'&month=2026-10', $pending->filter('a')->attr('href'));
    }

    public function testTheReminderLinkPreselectsTheFirmAndMonthOfTheExport(): void
    {
        $this->signedIn();
        $second = $this->secondFirm();

        $crawler = $this->client->request('GET', '/invoices/export?firm='.$second->id().'&month=2026-09');

        self::assertResponseIsSuccessful();
        self::assertSame('2026-09', $crawler->filter('select[name="month"] option[selected]')->attr('value'));
        self::assertSame($second->id(), $crawler->filter('select[name="firm"] option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', '/invoices/export?firm=unknown&month=2026-13');

        self::assertResponseIsSuccessful();
        self::assertSame('2026-10', $crawler->filter('select[name="month"] option[selected]')->attr('value'));
    }

    public function testSeveralFirmsOfferAPickerThatNarrowsTheOverview(): void
    {
        $this->signedIn();
        $this->issue('2026-10-02', '2026-12-31', '1000.00');
        $second = $this->secondFirm();
        $this->issue('2026-10-03', '2026-12-31', '100.00', $second);

        $crawler = $this->client->request('GET', '/');

        $options = $crawler->filter('main [data-dashboard-invoicing] select[name="firm"] option');
        self::assertSame(['', $this->firm->id(), $second->id()], $options->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('1,331.00 Kč', $this->kpis($crawler)[2][1] ?? null, 'All firms by default.');
        self::assertSame([
            'Example Firm s.r.o.: October 2026 has not gone to the accountant yet (1 document) Pass to the accountant',
            'Example Second s.r.o.: October 2026 has not gone to the accountant yet (1 document) Pass to the accountant',
        ], $crawler->filter('[data-dashboard-pending] [role="status"]')->each(static fn (Crawler $reminder): string => $reminder->text()), 'One reminder per firm.');

        $crawler = $this->client->request('GET', '/?firm='.$second->id());

        self::assertSame($second->id(), $crawler->filter('main select[name="firm"] option[selected]')->attr('value'));
        self::assertSame(['Unpaid', '121.00 Kč', '1 document', '/invoices?firm='.$second->id().'&tab=unpaid'], $this->kpis($crawler)[2]);
        self::assertSame(['October 2026 has not gone to the accountant yet (1 document) Pass to the accountant'], $crawler->filter('[data-dashboard-pending] [role="status"]')->each(static fn (Crawler $reminder): string => $reminder->text()));
    }

    public function testCzechNamesTheMonthAsASentenceStartsWithIt(): void
    {
        $this->signedIn(locale: 'cs');
        $this->issue('2026-10-05', '2026-12-31', '2000.00');

        $crawler = $this->client->request('GET', '/');

        self::assertSame('Říjen 2026 ještě není předané účetní (1 doklad) Předat účetní', $crawler->filter('[data-dashboard-pending] [role="status"]')->text());
        self::assertSame('Vyfakturováno – Říjen 2026', $this->kpis($crawler)[1][0]);
    }

    public function testAGuestSeesNoInvoicing(): void
    {
        $this->signedIn(Role::Guest);
        $this->issue('2026-11-02', '2026-12-31', '1000.00');

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/shared');
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-dashboard-invoicing]'));
    }

    /**
     * @return list<list<string>> label, value, note and link of each KPI
     */
    private function kpis(Crawler $crawler): array
    {
        return $crawler->filter('main [data-dashboard-invoicing] [data-kpi]')->each(fn (Crawler $kpi): array => [
            $kpi->filter('div')->eq(0)->text(),
            $this->plain($kpi->filter('div')->eq(1)->text()),
            $this->plain($kpi->filter('div')->eq(2)->text()),
            (string) $kpi->attr('href'),
        ]);
    }

    private function plain(string $text): string
    {
        return str_replace(["\u{a0}", "\u{202f}"], ' ', $text);
    }

    private function issue(string $date, string $due, string $price, ?LegalEntity $firm = null): Invoice
    {
        $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

        return $this->scoped(fn (): Invoice => self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput(
            ($firm ?? $this->firm)->id(),
            $firm instanceof LegalEntity ? null : $this->series->id(),
            null,
            $this->customer->id(),
            null,
            new \DateTimeImmutable($date),
            new \DateTimeImmutable($date),
            new \DateTimeImmutable($due),
            PaymentMethod::BankTransfer,
            $firm instanceof LegalEntity ? self::SECOND_IBAN : self::IBAN,
            '',
            '',
            '',
            'Consulting',
            '',
            '',
            DocumentLanguage::Czech,
            [$line],
            true,
        )));
    }

    private function secondFirm(): LegalEntity
    {
        return $this->scoped(function (): LegalEntity {
            $firm = new LegalEntity('Example Second s.r.o.', new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), new Address('Example Street 2', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 2');
            $this->em()->persist($firm);
            FirmAccounts::add($this->em(), $firm, self::SECOND_IBAN);
            $this->em()->flush();

            return $firm;
        });
    }

    private function foreignInvoice(): void
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
            self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($firm->id(), $series->id(), null, $customer->id(), null, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-15'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Foreign', '', '', DocumentLanguage::Czech, [$line], true));
        } finally {
            $this->context->reset();
        }
    }

    private function signedIn(Role $role = Role::Owner, string $locale = 'en'): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], $locale, $role);
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
        $this->em()->flush();
        $this->context->reset();
        $this->browser->signIn();
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
