<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\AccountantPackFormat;
use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\DocumentExports;
use App\Invoicing\Application\InvoiceBundle;
use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Domain\ExportState;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Application\TransactionalWork;
use OpenSpout\Reader\XLSX\Reader;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

final class InvoiceBundleTest extends AdvanceTestCase
{
    private InvoiceBundle $bundle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bundle = self::getContainer()->get(InvoiceBundle::class);
    }

    public function testACzechPackHasCzechFoldersAndTheOwnersFileNames(): void
    {
        $first = $this->issue->issue($this->input([$this->line('1000.00'), $this->line('500.00', kind: VatRateKind::Reduced)], ['variableSymbol' => '20260093']));
        $second = $this->issue->issue($this->input([$this->line('1000.00'), $this->line('500.00', kind: VatRateKind::Reduced)], ['variableSymbol' => '20260093']));
        $advance = $this->advance([$this->line('1000.00')], ['issueDate' => new \DateTimeImmutable('2026-11-03'), 'dueDate' => new \DateTimeImmutable('2026-11-17')]);
        // Issued in November, taxed in October: it stays out of November.
        $this->issue->issue($this->input(null, ['issueDate' => new \DateTimeImmutable('2026-11-02'), 'taxPointDate' => new \DateTimeImmutable('2026-10-31')]));

        [$count, $files] = $this->pack('2026-11-15', true, 'cs');

        self::assertSame(3, $count);
        self::assertSame([
            'faktury/2026-11-01_example-customer-a-s_1770Kc_VS20260093.pdf',
            'faktury/2026-11-01_example-customer-a-s_1770Kc_VS20260093.isdoc',
            'faktury/2026-11-01_example-customer-a-s_1770Kc_VS20260093_'.$second->number().'.pdf',
            'faktury/2026-11-01_example-customer-a-s_1770Kc_VS20260093_'.$second->number().'.isdoc',
            'zalohove-faktury/2026-11-03_example-customer-a-s_1210Kc_VS'.$advance->variableSymbol().'.pdf',
            'seznam.xlsx',
        ], array_keys($files));
        self::assertStringContainsString($first->number(), $files['faktury/2026-11-01_example-customer-a-s_1770Kc_VS20260093.isdoc']);

        [$documents, $recap] = $this->sheets($files['seznam.xlsx']);
        self::assertSame(['Číslo', 'Druh', 'Odběratel', 'IČO', 'DIČ', 'Datum vystavení', 'DUZP', 'Splatnost', 'Měna', 'Kurz', 'Základ', 'DPH', 'Celkem', 'K úhradě', 'Stav exportu předtím'], $documents[0]);
        self::assertSame([$first->number(), 'Faktura', 'Example Customer a.s.', '87654320', 'CZ87654320', '2026-11-01', '2026-11-01', '2026-11-15', 'CZK', 1, 1500, 270, 1770, 1770, 'Neexportováno'], $documents[1]);
        self::assertSame([$advance->number(), 'Zálohová faktura', '', 1000, 210, 1210, 1210], [$documents[3][0], $documents[3][1], $documents[3][6], $documents[3][10], $documents[3][11], $documents[3][12], $documents[3][13]]);
        // The advance is no tax document, so the recap has the invoices only.
        self::assertSame([
            ['Režim', 'Sazba (%)', 'Základ (CZK)', 'DPH (CZK)', 'Celkem (CZK)'],
            ['Běžné plnění', 21, 2000, 420, 2420],
            ['Běžné plnění', 12, 1000, 120, 1120],
            ['Celkem', '', 3000, 540, 3540],
        ], $recap);

        foreach ([$first, $second, $advance] as $document) {
            self::assertSame(ExportState::Exported, $this->reloaded($document)->exportState());
        }
    }

    public function testOnlyPendingTakesNewAndChangedDocumentsAndAllTakesEvery(): void
    {
        $exported = $this->issue->issue($this->input());
        $this->pack('2026-11-01', true, 'en');
        $new = $this->issue->issue($this->input());

        self::assertSame(['invoice' => 1, 'advance' => 0, 'payment_tax_document' => 0, 'correction_document' => 0], $this->bundle->counts($this->firm, new \DateTimeImmutable('2026-11-30'), true));
        self::assertSame(['invoice' => 2, 'advance' => 0, 'payment_tax_document' => 0, 'correction_document' => 0], $this->bundle->counts($this->firm, new \DateTimeImmutable('2026-11-30'), false));

        [$count, $files] = $this->pack('2026-11-01', true, 'en');
        self::assertSame(1, $count);
        self::assertSame(['invoices/2026-11-01_example-customer-a-s_1210CZK_VS20260001.pdf', 'invoices/2026-11-01_example-customer-a-s_1210CZK_VS20260001.isdoc', 'list.xlsx'], array_keys($files));
        self::assertSame($new->number(), $this->sheets($files['list.xlsx'])[0][1][0]);
        self::assertSame('Not exported', $this->sheets($files['list.xlsx'])[0][1][14]);

        [$count, $files] = $this->pack('2026-11-01', false, 'en');
        self::assertSame(2, $count);
        self::assertSame(['Exported', 'Exported'], [$this->sheets($files['list.xlsx'])[0][1][14], $this->sheets($files['list.xlsx'])[0][2][14]]);
        self::assertNotSame('', $exported->id());
    }

    public function testTheTrashAndAnEmptyMonthGiveNothing(): void
    {
        $trashed = $this->issue->issue($this->input());
        $this->trash->trash($trashed->id(), 'Wrong customer');
        $path = (string) tempnam(sys_get_temp_dir(), 'foresi-test-');

        try {
            self::assertSame(0, $this->bundle->writeTo($path, $this->firm, new \DateTimeImmutable('2026-11-01'), false, 'en'));
            self::assertSame(0, (int) filesize($path));
        } finally {
            unlink($path);
        }
        self::assertSame(ExportState::NotExported, $this->reloaded($trashed)->exportState());
    }

    public function testAForeignCurrencyDocumentIsRecappedInTheHomeCurrencyAtItsRate(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('1000.00'), $this->line('500.00', kind: VatRateKind::Reduced), $this->line('200.00', treatment: TaxTreatment::Exempt, kind: null)]));
        // Issuing takes the home currency only; the stored document is turned into one in euros at 25.125.
        $this->em->getConnection()->executeStatement("UPDATE invoice SET currency = 'EUR', exchange_rate = '25.125', payable_minor = 197050 WHERE id = :id", ['id' => $invoice->id()]);
        $this->em->clear();

        [, $files] = $this->pack('2026-11-01', true, 'cs');

        self::assertSame(['faktury/2026-11-01_example-customer-a-s_1970.50EUR_VS20260001.pdf', 'faktury/2026-11-01_example-customer-a-s_1970.50EUR_VS20260001.isdoc', 'seznam.xlsx'], array_keys($files));
        [$documents, $recap] = $this->sheets($files['seznam.xlsx']);
        self::assertSame(['EUR', 25.125, 1700, 270, 1970, 1970.5], \array_slice($documents[1], 8, 6));
        self::assertSame([
            ['Režim', 'Sazba (%)', 'Základ (CZK)', 'DPH (CZK)', 'Celkem (CZK)'],
            ['Běžné plnění', 21, 25125, 5276.25, 30401.25],
            ['Běžné plnění', 12, 12562.5, 1507.5, 14070],
            ['Osvobozeno', '', 5025, 0, 5025],
            ['Celkem', '', 42712.5, 6783.75, 49496.25],
        ], $recap);
    }

    public function testACreditNoteIsNamedByItsNumberWithAMinus(): void
    {
        $original = $this->issue->issue($this->input());
        $credit = $this->correctionOf($original, '-100.00');

        [, $files] = $this->pack('2026-11-01', true, 'en');

        self::assertSame([
            'invoices/2026-11-01_example-customer-a-s_1210CZK_VS20260001.pdf',
            'invoices/2026-11-01_example-customer-a-s_1210CZK_VS20260001.isdoc',
            'corrective-documents/2026-11-20_example-customer-a-s_-121CZK_'.strtolower($credit->number()).'.pdf',
            'corrective-documents/2026-11-20_example-customer-a-s_-121CZK_'.strtolower($credit->number()).'.isdoc',
            'list.xlsx',
        ], array_keys($files));
        [$documents] = $this->sheets($files['list.xlsx']);
        self::assertSame([$credit->number(), 'Corrective tax document', -100, -21, -121, -121], [$documents[2][0], $documents[2][1], $documents[2][10], $documents[2][11], $documents[2][12], $documents[2][13]]);
    }

    public function testAPackThatCannotBeMarkedIsDeletedAndMarksNothing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $broken = new class implements TransactionalWork {
            public function run(callable $work): mixed
            {
                throw new \RuntimeException('The database is gone.');
            }
        };
        $container = self::getContainer();
        $exports = new DocumentExports($this->store, $broken, $container->get(ConflictRetry::class), $container->get(ClockInterface::class));
        $bundle = new InvoiceBundle($this->store, $container->get(IssuedDocumentFactory::class), $container->get(InvoicePdf::class), $container->get(InvoicingCountries::class), $container->get(AccountantPackFormat::class), $exports, new NullLogger());
        $path = (string) tempnam(sys_get_temp_dir(), 'foresi-test-');

        try {
            $bundle->writeTo($path, $this->firm, new \DateTimeImmutable('2026-11-01'), true, 'en');
            self::fail('Expected the marking to fail.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The database is gone.', $failure->getMessage());
        } finally {
            @unlink($path);
        }
        self::assertFileDoesNotExist($path);
        self::assertDirectoryDoesNotExist($path.'.parts');
        self::assertSame(ExportState::NotExported, $this->reloaded($invoice)->exportState());
    }

    public function testThePackIsNamedByFirmAndMonth(): void
    {
        self::assertSame('example-firm-s-r-o_2026-09.zip', $this->bundle->fileName($this->firm, new \DateTimeImmutable('2026-09-30')));
    }

    /**
     * @return array{0: int, 1: array<string, string>}
     */
    private function pack(string $month, bool $pending, string $locale): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'foresi-test-');
        try {
            $count = $this->bundle->writeTo($path, $this->firm, new \DateTimeImmutable($month), $pending, $locale);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $files = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $files[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
            }
            $zip->close();

            return [$count, $files];
        } finally {
            unlink($path);
        }
    }

    /**
     * @return list<list<array<int, mixed>>> the rows of each sheet
     */
    private function sheets(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'foresi-test-').'.xlsx';
        file_put_contents($path, $bytes);
        $reader = new Reader();
        $reader->open($path);
        $sheets = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
                $sheets[] = $rows;
            }
        } finally {
            $reader->close();
            unlink($path);
        }

        return $sheets;
    }
}
