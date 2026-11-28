<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Currency;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechInvoicing;
use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechSupplyCodes;
use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechVatRates;
use Foresi\Country\Integration\Invoicing\CzechRepublic\IsdocWriter;
use Foresi\Country\Integration\Invoicing\CzechRepublic\QrPlatba;
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
use Foresi\Tests\Invoicing\Support\HomeCurrencyCountry;
use Foresi\Tests\Party\Support\FirmAccounts;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The list export of invoices issued in a currency with 0 minor units (JPY) or 3
 * (BHD): in XLSX an amount is a number with as many decimals as the currency has and
 * a number format of that many zeros, and in CSV it is written with those decimals
 * alone.
 */
final class MinorUnitsInvoiceExportTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private string $lastXlsx = '';
    /** @var list<string> */
    private array $files = [];
    /** @var array{series: Series, customer: Party} */
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
        array_map(unlink(...), $this->files);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, string, list<int|float>, list<string>, string}>
     */
    public static function currencies(): iterable
    {
        // code, unit price, quantity, net/VAT/total/payable in the XLSX, the same in the CSV, the number format of the cells
        yield 'JPY has no minor units' => ['JPY', '1500', '2', [3000, 630, 3630, 3630], ['3000', '630', '3630', '3630'], '0'];
        yield 'BHD has three' => ['BHD', '12.345', '1', [12.345, 2.592, 14.937, 14.937], ['12.345', '2.592', '14.937', '14.937'], '0.000'];
    }

    /**
     * @param list<int|float> $cells
     * @param list<string>    $text
     */
    #[DataProvider('currencies')]
    public function testTheXlsxAndTheCsvHoldTheAmountsWithTheDecimalsOfTheCurrency(string $code, string $price, string $quantity, array $cells, array $text, string $format): void
    {
        $this->signedIn($code);
        $invoice = $this->issue($code, $price, $quantity);

        $rows = $this->xlsx();

        self::assertSame($code, $rows[1][8]);
        self::assertSame([$invoice->number(), ...$cells], [$rows[1][0], ...\array_slice($rows[1], 10, 4)]);
        // Net, VAT, total, payable: the amounts are numbers with the format of the currency, not of the crown.
        self::assertSame([$format], array_values(array_unique($this->numberFormats())));
        self::assertSame([$invoice->number(), ...$text], $this->csvAmounts($invoice->number()));
    }

    /**
     * @return list<list<mixed>>
     */
    private function xlsx(): array
    {
        $path = $this->exported('xlsx');
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
        }

        return $rows;
    }

    /**
     * The format codes of the amount cells of the last XLSX, one per column of the first row below the header.
     *
     * @return list<string>
     */
    private function numberFormats(): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->lastXlsx));
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $custom = [];
        if (false !== preg_match_all('/<numFmt numFmtId="(\d+)" formatCode="([^"]+)"/', $styles, $found, \PREG_SET_ORDER)) {
            foreach ($found as $one) {
                $custom[(int) $one[1]] = $one[2];
            }
        }
        $builtin = [0 => 'General', 1 => '0', 2 => '0.00'];
        preg_match('/<cellXfs[^>]*>(.*?)<\/cellXfs>/s', $styles, $xfs);
        preg_match_all('/<xf [^>]*numFmtId="(\d+)"/', $xfs[1] ?? '', $styleFormats);
        $formats = array_map(static fn (string $id): string => $custom[(int) $id] ?? $builtin[(int) $id] ?? 'id'.$id, $styleFormats[1]);
        // Row 2 of the sheet: the amounts are K..N, columns 11 to 14.
        preg_match('/<row r="2".*?<\/row>/s', $sheet, $row);
        preg_match_all('/<c r="([K-N])2" s="(\d+)"/', $row[0] ?? '', $cells, \PREG_SET_ORDER);

        return array_map(static fn (array $cell): string => $formats[(int) $cell[2]] ?? 'none', $cells);
    }

    /**
     * @return list<string> the number and the amounts of the invoice, as the CSV writes them
     */
    private function csvAmounts(string $number): array
    {
        $csv = file_get_contents($this->exported('csv'));
        self::assertIsString($csv);
        foreach (explode("\n", substr($csv, 3)) as $line) {
            $row = array_map(strval(...), str_getcsv($line, ';', '"', ''));
            if ($number === $row[0]) {
                return [$row[0], ...\array_slice($row, 10, 4)];
            }
        }
        self::fail('The invoice is not in the CSV.');
    }

    private function exported(string $format): string
    {
        $list = $this->client->request('GET', '/invoices');
        $token = (string) $list->filter('form[data-list-export] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/invoices/list-export', ['_token' => $token, 'kind' => 'invoice', 'format' => $format]);
        self::assertResponseIsSuccessful();
        $path = $this->files[] = (string) tempnam(sys_get_temp_dir(), 'foresi-test-');
        file_put_contents($path, $this->client->getInternalResponse()->getContent());
        if ('xlsx' === $format) {
            $this->lastXlsx = $path;
        }

        return $path;
    }

    private function issue(string $code, string $price, string $quantity): Invoice
    {
        $line = new LineInput('Work', Quantity::fromString($quantity), 'h', Money::of($price, Currency::of($code)), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
        $input = new InvoiceInput($this->world['series']->id(), null, $this->world['customer']->id(), null, new \DateTimeImmutable('2026-10-05'), new \DateTimeImmutable('2026-10-05'), new \DateTimeImmutable('2026-12-31'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Work', '', '', DocumentLanguage::Czech, [$line], true);

        return self::getContainer()->get(IssueInvoice::class)->issue($input);
    }

    private function signedIn(string $homeCurrency): void
    {
        $container = self::getContainer();
        // The Czech rules with the currency under test as the home currency, set before anything has used the country.
        $czech = new CzechInvoicing(new CzechVatRates(), new CzechSupplyCodes(), $container->get(IsdocWriter::class), $container->get(QrPlatba::class));
        $container->set(CzechInvoicing::class, new HomeCurrencyCountry($czech, Currency::of($homeCurrency)));
        $this->browser->seed('en', Role::Owner);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em()->persist($firm);
        FirmAccounts::add($this->em(), self::IBAN);
        $series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $this->em()->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em()->persist($customer);
        $this->em()->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->world = ['series' => $series, 'customer' => $customer];
        $this->em()->flush();
        $this->browser->signIn();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
