<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\DocumentExports;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Application\View\InvoiceLists;
use Foresi\Invoicing\Contract\DocumentKind;
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
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class InvoiceListExportControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    /** In the language of the user, also once the response streams after the request. */
    private const array HEADER = ['Number', 'Kind', 'Customer', 'Registration number', 'VAT number', 'Issue date', 'Tax point', 'Due date', 'Currency', 'Exchange rate', 'Base', 'VAT', 'Total', 'To pay', 'Paid', 'Left to pay', 'Export state'];

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    /** @var array{firm: LegalEntity, series: Series, customer: Party} */
    private array $world;

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

    public function testTheXlsxHoldsTheMonthOfTheListWithAPairOfColumnsPerRate(): void
    {
        $this->signedIn();
        $single = $this->issue('2026-10-05', ['21' => '100.00']);
        $double = $this->issue('2026-10-06', ['21' => '100.00', '12' => '200.00']);
        $this->issue('2026-09-30', ['21' => '100.00']);
        $this->scoped(static fn () => self::getContainer()->get(DocumentExports::class)->markExported([$single->id()]));

        $rows = $this->xlsx(['month' => '2026-10']);

        self::assertSame('attachment; filename=invoices_2026-10.xlsx', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('max-age=0, must-revalidate, no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([
            [...self::HEADER, 'Base 21 %', 'VAT 21 %', 'Base 12 %', 'VAT 12 %'],
            [$double->number(), 'Invoice', 'Example Customer a.s.', '87654320', 'CZ87654320', '2026-10-06', '2026-10-06', '2026-12-31', 'CZK', 1, 300, 45, 345, 345, 0, 345, 'Not exported', 100, 21, 200, 24],
            [$single->number(), 'Invoice', 'Example Customer a.s.', '87654320', 'CZ87654320', '2026-10-05', '2026-10-05', '2026-12-31', 'CZK', 1, 100, 21, 121, 121, 0, 121, 'Exported', 100, 21, '', ''],
        ], $rows);
    }

    public function testTheCsvTakesTheExportStateAndTheOrderOverAllMonths(): void
    {
        $this->signedIn();
        $september = $this->issue('2026-09-30', ['21' => '100.50']);
        $exported = $this->issue('2026-10-05', ['21' => '100.00']);
        $october = $this->issue('2026-10-06', ['12' => '200.00']);
        $this->scoped(static fn () => self::getContainer()->get(DocumentExports::class)->markExported([$exported->id()]));

        $csv = $this->csv(['export' => 'not_exported', 'sort' => 'number']);

        self::assertSame('attachment; filename=invoices_all.csv', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('text/csv; charset=utf-8', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith("\xEF\xBB\xBFNumber;Kind;Customer;\"Registration number\";", $csv);
        self::assertSame([
            [...self::HEADER, 'Base 21 %', 'VAT 21 %', 'Base 12 %', 'VAT 12 %'],
            [$september->number(), 'Invoice', 'Example Customer a.s.', '87654320', 'CZ87654320', '2026-09-30', '2026-09-30', '2026-12-31', 'CZK', '1', '100.50', '21.11', '121.61', '122.00', '0.00', '122.00', 'Not exported', '100.50', '21.11', '', ''],
            [$october->number(), 'Invoice', 'Example Customer a.s.', '87654320', 'CZ87654320', '2026-10-06', '2026-10-06', '2026-12-31', 'CZK', '1', '200.00', '24.00', '224.00', '224.00', '0.00', '224.00', 'Not exported', '', '', '200.00', '24.00'],
        ], $this->parsed($csv));
    }

    public function testAUserInCzechGetsCzechHeadersAndADecimalComma(): void
    {
        $this->signedIn(locale: 'cs');
        $invoice = $this->issue('2026-10-05', ['21' => '100.50']);

        $rows = $this->parsed($this->csv(['month' => '2026-10']));

        self::assertSame('attachment; filename=faktury_2026-10.csv', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(['Číslo', 'Druh', 'Odběratel'], \array_slice($rows[0], 0, 3));
        self::assertSame(['Zbývá uhradit', 'Stav exportu', 'Základ 21 %', 'DPH 21 %'], \array_slice($rows[0], 15));
        self::assertSame([$invoice->number(), 'Faktura'], \array_slice($rows[1], 0, 2));
        self::assertSame(['100,50', '21,11', '121,61', '122,00', '0,00', '122,00', 'Neexportováno', '100,50', '21,11'], \array_slice($rows[1], 10));
        self::assertSame('attachment; filename=faktury_vse.csv', $this->csvHeaders([]));
    }

    public function testATreatmentWithoutARateHasAPairOfItsOwn(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-10-05', ['21' => '100.00', 'exempt' => '50.00']);

        $rows = $this->xlsx([]);

        self::assertSame(['Base 21 %', 'VAT 21 %', 'Base Exempt', 'VAT Exempt'], \array_slice($rows[0], 17));
        self::assertSame([$invoice->number(), 150, 21, 171], [$rows[1][0], $rows[1][10], $rows[1][11], $rows[1][12]]);
        self::assertSame([100, 21, 50, 0], \array_slice($rows[1], 17));
    }

    public function testTheTabAndTheTextNarrowItAsTheListDoes(): void
    {
        $this->signedIn();
        $kept = $this->issue('2026-10-05', ['21' => '100.00']);
        $trashed = $this->issue('2026-10-06', ['21' => '100.00']);
        $this->scoped(static fn () => self::getContainer()->get(TrashInvoice::class)->trash($trashed->id(), 'Wrong customer'));

        self::assertSame([$trashed->number()], $this->numbers($this->csv(['tab' => 'trash'])));
        self::assertSame([$kept->number()], $this->numbers($this->csv(['tab' => 'unpaid'])));
        self::assertSame([], $this->numbers($this->csv(['tab' => 'paid'])));
        // A tab the kind lacks lists the whole kind, out of the trash.
        self::assertSame([$kept->number()], $this->numbers($this->csv(['tab' => 'settled'])));
        self::assertSame([$kept->number()], $this->numbers($this->csv(['q' => $kept->number()])));
        self::assertSame([], $this->numbers($this->csv(['q' => 'nothing like it'])));
    }

    public function testAnEmptyListGivesTheHeaderAlone(): void
    {
        $this->signedIn();
        $this->issue('2026-09-30', ['21' => '100.00']);

        self::assertSame([self::HEADER], $this->parsed($this->csv(['month' => '2026-10'])));
    }

    public function testEachKindExportsItsOwnList(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-10-05', ['21' => '100.00']);
        $advance = $this->issue('2026-10-06', ['21' => '100.00']);
        $this->scoped(fn (): int => (int) $this->em()->getConnection()->executeStatement("UPDATE invoice SET kind = 'advance', tax_point_date = NULL WHERE id = :id", ['id' => $advance->id()]));

        $rows = $this->parsed($this->csv(['kind' => 'advance', 'month' => '2026-10']));

        self::assertSame('attachment; filename=advance-invoices_2026-10.csv', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertCount(2, $rows);
        self::assertSame([$advance->number(), 'Advance invoice'], \array_slice($rows[1], 0, 2));
        // An advance has no tax point.
        self::assertSame('', $rows[1][6]);
        self::assertSame([$invoice->number()], $this->numbers($this->csv(['month' => '2026-10'])));
        self::assertSame('attachment; filename=tax-documents_all.csv', $this->csvHeaders(['kind' => 'payment_tax_document']));
        self::assertSame('attachment; filename=corrective-documents_all.csv', $this->csvHeaders(['kind' => 'correction_document']));
    }

    public function testCsvQuotesWhatWouldRunAsAFormulaAndXlsxKeepsItAsText(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-10-05', ['21' => '100.00']);
        $this->scoped(fn (): int => (int) $this->em()->getConnection()->executeStatement("UPDATE invoice SET customer_name = '=HYPERLINK(\"https://example.com\")', customer_vat_id = '+CZ1' WHERE id = :id", ['id' => $invoice->id()]));

        $csv = $this->parsed($this->csv([]));
        self::assertSame(['\'=HYPERLINK("https://example.com")', '87654320', '\'+CZ1'], \array_slice($csv[1], 2, 3));
        $xlsx = $this->xlsx([]);
        self::assertSame(['=HYPERLINK("https://example.com")', '87654320', '+CZ1'], \array_slice($xlsx[1], 2, 3));
    }

    public function testANegativeAmountKeepsItsSignAndNoApostrophe(): void
    {
        $this->signedIn();
        $invoice = $this->issue('2026-10-05', ['21' => '100.00']);
        $this->scoped(fn (): int => (int) $this->em()->getConnection()->executeStatement('UPDATE invoice SET payable_minor = -12105 WHERE id = :id', ['id' => $invoice->id()]));

        self::assertSame(['-121.05', '0.00', '-121.05'], \array_slice($this->parsed($this->csv([]))[1], 13, 3));
        self::assertSame([-121.05, 0, -121.05], \array_slice($this->xlsx([])[1], 13, 3));
    }

    public function testTheListOffersItWithItsFilter(): void
    {
        $this->signedIn();
        $this->issue('2026-10-05', ['21' => '100.00']);

        $crawler = $this->client->request('GET', '/advances?month=2026-10&export=exported&tab=paid&q=Work&sort=number&firm='.$this->world['firm']->id());

        $form = $crawler->filter('form[data-list-export]');
        self::assertSame('/invoices/list-export', $form->attr('action'));
        self::assertSame('post', $form->attr('method'));
        self::assertSame('false', $form->attr('data-turbo'));
        $fields = [];
        $form->filter('input[type="hidden"]')->each(static function ($input) use (&$fields): void {
            $fields[(string) $input->attr('name')] = (string) $input->attr('value');
        });
        unset($fields['_token']);
        self::assertSame(['kind' => 'advance', 'view' => 'paid', 'firm' => $this->world['firm']->id(), 'q' => 'Work', 'sort' => 'number', 'month' => '2026-10', 'f' => WorkingFilter::encode(ViewFilter::all(new Condition(InvoiceLists::PAYMENT, 'is', 'paid'), new Condition(InvoiceLists::EXPORT, 'is', 'exported')))], $fields);
        self::assertSame(['csv', 'xlsx'], $form->filter('button[name="format"]')->each(static fn ($button): string => (string) $button->attr('value')));
        // The export sits in the header's "⋯" menu.
        self::assertCount(1, $crawler->filter('#invoices-actions form[data-list-export]'));
    }

    public function testAGuestGets403(): void
    {
        $this->signedIn(Role::Guest);

        $this->client->request('POST', '/invoices/list-export', ['kind' => 'invoice', 'format' => 'csv']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testItNeedsAToken(): void
    {
        $this->signedIn();

        $this->client->request('POST', '/invoices/list-export', ['_token' => 'wrong', 'kind' => 'invoice', 'format' => 'csv']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownKindIs404(): void
    {
        $this->signedIn();
        $this->issue('2026-10-05', ['21' => '100.00']);

        $this->client->request('POST', '/invoices/list-export', ['_token' => $this->token(), 'kind' => 'receipt', 'format' => 'csv']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testItIsPostOnly(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/invoices/list-export');

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * @param array<string, string> $values
     *
     * @return list<list<mixed>>
     */
    private function xlsx(array $values): array
    {
        $this->post($values + ['format' => 'xlsx']);
        self::assertResponseIsSuccessful();
        $path = (string) tempnam(sys_get_temp_dir(), 'foresi-test-');
        file_put_contents($path, $this->client->getInternalResponse()->getContent());
        $reader = new Reader();
        $reader->open($path);
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_values($row->toArray());
                }
            }
        } finally {
            $reader->close();
            unlink($path);
        }

        return $rows;
    }

    /**
     * @param array<string, string> $values
     */
    private function csv(array $values): string
    {
        $this->post($values + ['format' => 'csv']);
        self::assertResponseIsSuccessful();

        return $this->client->getInternalResponse()->getContent();
    }

    /**
     * @param array<string, string> $values
     */
    private function csvHeaders(array $values): ?string
    {
        $this->csv($values);

        return $this->client->getResponse()->headers->get('Content-Disposition');
    }

    /**
     * @return list<list<string>>
     */
    private function parsed(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", substr($csv, 3)), static fn (string $line): bool => '' !== $line));

        return array_map(static fn (string $line): array => array_map(strval(...), str_getcsv($line, ';', '"', '')), $lines);
    }

    /**
     * @return list<string> the numbers below the header
     */
    private function numbers(string $csv): array
    {
        return array_map(static fn (array $row): string => $row[0], \array_slice($this->parsed($csv), 1));
    }

    /**
     * @param array<string, string> $values
     */
    private function post(array $values): void
    {
        $this->client->request('POST', '/invoices/list-export', $values + ['_token' => $this->token(), 'kind' => 'invoice']);
    }

    private function token(): string
    {
        $list = $this->client->request('GET', '/invoices');

        return (string) $list->filter('form[data-list-export] input[name="_token"]')->attr('value');
    }

    /**
     * @param array<int|string, string> $prices net prices by VAT rate: 21 is the base rate, 12 the reduced one, exempt none
     */
    private function issue(string $issueDate, array $prices): Invoice
    {
        return $this->scoped(fn (): Invoice => $this->issueIn($this->world, $issueDate, $prices));
    }

    /**
     * @param array{firm: LegalEntity, series: Series, customer: Party} $world
     * @param array<int|string, string>                                 $prices
     */
    private function issueIn(array $world, string $issueDate, array $prices): Invoice
    {
        $lines = [];
        foreach ($prices as $rate => $price) {
            $exempt = 'exempt' === $rate;
            $kind = '21' === (string) $rate ? VatRateKind::Base : VatRateKind::Reduced;
            $lines[] = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, $exempt ? TaxTreatment::Exempt : TaxTreatment::Standard, $exempt ? null : $kind, null, Percent::fromString('0'));
        }
        $input = new InvoiceInput($world['firm']->id(), $world['series']->id(), null, $world['customer']->id(), null, new \DateTimeImmutable($issueDate), new \DateTimeImmutable($issueDate), new \DateTimeImmutable('2026-12-31'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Work', '', '', DocumentLanguage::Czech, $lines, true);

        return self::getContainer()->get(IssueInvoice::class)->issue($input);
    }

    /**
     * @return array{firm: LegalEntity, series: Series, customer: Party}
     */
    private function world(string $firmName): array
    {
        $firm = new LegalEntity($firmName, new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em()->persist($firm);
        FirmAccounts::add($this->em(), $firm, self::IBAN);
        $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $this->em()->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em()->persist($customer);
        $this->em()->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));

        return ['firm' => $firm, 'series' => $series, 'customer' => $customer];
    }

    private function signedIn(Role $role = Role::Owner, string $locale = 'en'): void
    {
        $this->browser->seed($locale, $role);
        $this->world = $this->world('Example Firm s.r.o.');
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
