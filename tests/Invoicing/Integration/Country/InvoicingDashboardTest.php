<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
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

    public function testTheOverviewShowsTheNumbersAndLinksToTheirLists(): void
    {
        $this->signedIn();
        $this->issue('2026-11-02', '2026-11-15', '1000.00');
        $this->issue('2026-10-05', '2026-12-31', '2000.00');

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('main [data-dashboard-invoicing] [data-filter-sheet-form] select[name="firm"]'));
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
        self::assertSame('/invoices/export?month=2026-10', $pending->filter('a')->attr('href'));
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

    private function issue(string $date, string $due, string $price): Invoice
    {
        $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

        return $this->scoped(fn (): Invoice => self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput(
            $this->series->id(),
            null,
            $this->customer->id(),
            null,
            new \DateTimeImmutable($date),
            new \DateTimeImmutable($date),
            new \DateTimeImmutable($due),
            PaymentMethod::BankTransfer,
            self::IBAN,
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

    private function signedIn(Role $role = Role::Owner, string $locale = 'en'): void
    {
        $this->browser->seed($locale, $role);
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em()->persist($this->firm);
        FirmAccounts::add($this->em(), self::IBAN);
        $this->series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->series->markDefault();
        $this->em()->persist($this->series);
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em()->persist($this->customer);
        $this->em()->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em()->flush();
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
        return $work();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
