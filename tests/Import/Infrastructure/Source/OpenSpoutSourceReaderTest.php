<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure\Source;

use App\Import\Application\Source\Encoding;
use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceRejected;
use App\Import\Application\Source\SourceSettings;
use App\Import\Infrastructure\Source\OpenSpoutSourceReader;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

final class OpenSpoutSourceReaderTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/foresi-source-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testUtf8BomCsvWithCommas(): void
    {
        $inspection = new OpenSpoutSourceReader()->inspect(self::FIXTURES.'/contacts-utf8-bom.csv');

        self::assertSame(SourceFormat::Csv, $inspection->format);
        self::assertSame(Encoding::Utf8Bom, $inspection->encoding);
        self::assertSame(',', $inspection->delimiter);
        self::assertSame([], $inspection->sheets);
        self::assertNull($inspection->sheet);
        self::assertSame(1, $inspection->headerRow);
        self::assertSame(['Company name', 'Registration number', 'Email', 'City', 'Note'], $inspection->headers);
        self::assertSame(3, $inspection->dataRowCount);
        self::assertSame(['Example Company', '12345687', 'testing@example.com', 'Ústí nad Labem', 'Příliš žluťoučký kůň'], $inspection->samples[2]);
        self::assertSame([2, 3, 4], array_keys($inspection->samples));
        self::assertSame('Quoted "value", with comma', $inspection->samples[4][4]);
    }

    public function testWindows1250CsvWithSemicolonsAndHeaderOnRowThree(): void
    {
        $inspection = new OpenSpoutSourceReader()->inspect(self::FIXTURES.'/contacts-windows-1250.csv');

        self::assertSame(Encoding::Windows1250, $inspection->encoding);
        self::assertSame(';', $inspection->delimiter);
        self::assertSame(3, $inspection->headerRow);
        self::assertSame(['Company name', 'Registration number', 'Email', 'City', 'Note'], $inspection->headers);
        self::assertSame(3, $inspection->dataRowCount);
        self::assertSame('Ústí nad Labem', $inspection->samples[4][3]);
        self::assertSame('Příliš žluťoučký kůň', $inspection->samples[4][4]);
    }

    public function testRowsAreStreamedWithSourceRowNumbersBelowTheHeader(): void
    {
        $reader = new OpenSpoutSourceReader();
        $settings = $reader->inspect(self::FIXTURES.'/contacts-windows-1250.csv')->settings();

        $rows = iterator_to_array($reader->rows(self::FIXTURES.'/contacts-windows-1250.csv', $settings));

        self::assertSame([4, 5, 6], array_keys($rows));
        self::assertSame(['Example Studio', '', 'studio@example.com', 'Praha', 'Quoted "value", with comma'], $rows[6]);
    }

    public function testRowsDetectMissingSettingsFromTheFile(): void
    {
        $rows = iterator_to_array(new OpenSpoutSourceReader()->rows(
            self::FIXTURES.'/contacts-windows-1250.csv',
            new SourceSettings(SourceFormat::Csv),
        ));

        self::assertSame([4, 5, 6], array_keys($rows));
        self::assertSame('Ústí nad Labem', $rows[4][3]);
    }

    public function testOverrideOfEncodingAndHeaderRowIsHonoured(): void
    {
        $reader = new OpenSpoutSourceReader();

        $inspection = $reader->inspect(
            self::FIXTURES.'/contacts-windows-1250.csv',
            new SourceSettings(SourceFormat::Csv, Encoding::Iso88592, null, null, 4),
        );

        self::assertSame(Encoding::Iso88592, $inspection->encoding);
        self::assertSame(';', $inspection->delimiter);
        self::assertSame(4, $inspection->headerRow);
        self::assertSame('Example Company', $inspection->headers[0]);
        self::assertSame(2, $inspection->dataRowCount);
        self::assertSame([5, 6], array_keys($inspection->samples));
        // ISO-8859-2 decodes 0xF8 (r with caron in Windows-1250 order) differently from Windows-1250.
        self::assertNotSame('Příliš žluťoučký kůň', $reader->inspect(
            self::FIXTURES.'/contacts-windows-1250.csv',
            new SourceSettings(SourceFormat::Csv, Encoding::Iso88592),
        )->samples[4][4]);
    }

    public function testDelimiterOverrideIsHonoured(): void
    {
        $path = $this->write('a;b,c'."\n".'1;2,3'."\n");

        $inspection = new OpenSpoutSourceReader()->inspect($path, new SourceSettings(SourceFormat::Csv, null, ';'));

        self::assertSame(['a', 'b,c'], $inspection->headers);
    }

    public function testTabAndPipeDelimitedFiles(): void
    {
        $reader = new OpenSpoutSourceReader();

        self::assertSame("\t", $reader->inspect($this->write("a\tb\n1\t2\n"))->delimiter);
        self::assertSame('|', $reader->inspect($this->write("a|b\n1|2\n"))->delimiter);
    }

    public function testHeadersAreTrimmedBomStrippedAndEmptyOnesNamed(): void
    {
        $path = $this->write("\u{FEFF} Name ,,City\nx,y,z\n");

        $inspection = new OpenSpoutSourceReader()->inspect($path);

        self::assertSame(['Name', 'Column 2', 'City'], $inspection->headers);
    }

    public function testBlankRowsAreSkippedButCounted(): void
    {
        $path = $this->write("Name,City\n\nExample,Praha\n,\nOther,Brno\n");
        $reader = new OpenSpoutSourceReader();

        $inspection = $reader->inspect($path);
        $rows = iterator_to_array($reader->rows($path, $inspection->settings()));

        self::assertSame(2, $inspection->dataRowCount);
        self::assertSame([3, 5], array_keys($rows));
        self::assertSame([3, 5], array_keys($inspection->samples));
    }

    public function testQuotedCellsMayContainNewlines(): void
    {
        $path = $this->write("Name,Note\nExample,\"line one\nline two\"\nOther,x\n");
        $reader = new OpenSpoutSourceReader();

        $rows = iterator_to_array($reader->rows($path, $reader->inspect($path)->settings()));

        self::assertSame([2, 3], array_keys($rows));
        self::assertSame("line one\nline two", $rows[2][1]);
    }

    public function testSamplesAreLimitedToTwentyRows(): void
    {
        $path = $this->write("Name\n".str_repeat("x\n", 30));

        $inspection = new OpenSpoutSourceReader()->inspect($path);

        self::assertCount(20, $inspection->samples);
        self::assertSame(30, $inspection->dataRowCount);
        self::assertSame(2, array_key_first($inspection->samples));
    }

    public function testWindows1250AfterALongAsciiPrefixIsStillDetected(): void
    {
        $path = $this->write("Name;City\n".str_repeat("Example;Praha\n", 6000)."Example \xE8;\xD8\xEDm\n");
        $reader = new OpenSpoutSourceReader();

        $inspection = $reader->inspect($path);
        $rows = iterator_to_array($reader->rows($path, $inspection->settings()));

        self::assertSame(Encoding::Windows1250, $inspection->encoding);
        self::assertSame(['Example č', 'Řím'], $rows[6002]);
    }

    public function testUtf8CharacterSplitAcrossReadChunksIsValid(): void
    {
        $path = $this->write("Name\n".str_repeat('x', 1_048_576 - 6).'ž'."\nOther\n");

        $inspection = new OpenSpoutSourceReader()->inspect($path);

        self::assertSame(Encoding::Utf8, $inspection->encoding);
        self::assertSame(2, $inspection->dataRowCount);
    }

    public function testInvalidBytesInAForcedUtf8FileAreRejectedWithTheRow(): void
    {
        $path = $this->write("Name;City\nExample;Praha\nExample;Pra\xE8\n");
        $reader = new OpenSpoutSourceReader();

        try {
            iterator_to_array($reader->rows($path, new SourceSettings(SourceFormat::Csv, Encoding::Utf8, ';', null, 1)));
            self::fail('Expected rejection');
        } catch (SourceRejected $e) {
            self::assertSame('import.source.rejected.invalid_encoding', $e->reason);
            self::assertSame(['row' => 3], $e->parameters);
        }
    }

    public function testRowsOfAnUnknownSheetAreRejected(): void
    {
        $this->expectRejection('import.source.rejected.unknown_format');

        iterator_to_array(new OpenSpoutSourceReader()->rows(
            self::FIXTURES.'/two-sheets.xlsx',
            new SourceSettings(SourceFormat::Xlsx, null, null, 'Missing', 1),
        ));
    }

    public function testHeaderIsFoundWhenTheFirstTenRowsAreBlank(): void
    {
        $path = $this->write(str_repeat("\n", 12)."Name,City\nExample,Praha\n");

        $inspection = new OpenSpoutSourceReader()->inspect($path);

        self::assertSame(13, $inspection->headerRow);
        self::assertSame(['Name', 'City'], $inspection->headers);
        self::assertSame(1, $inspection->dataRowCount);
    }

    public function testXlsxWithTwoSheets(): void
    {
        $reader = new OpenSpoutSourceReader();

        $first = $reader->inspect(self::FIXTURES.'/two-sheets.xlsx');

        self::assertSame(SourceFormat::Xlsx, $first->format);
        self::assertNull($first->encoding);
        self::assertNull($first->delimiter);
        self::assertSame(['Contacts', 'Archive'], $first->sheets);
        self::assertSame('Contacts', $first->sheet);
        self::assertSame(1, $first->headerRow);
        self::assertSame(['Company name', 'Registration number', 'Email', 'City', 'Note'], $first->headers);
        self::assertSame(3, $first->dataRowCount);
        self::assertSame('Ústí nad Labem', $first->samples[2][3]);

        $second = $reader->inspect(self::FIXTURES.'/two-sheets.xlsx', new SourceSettings(SourceFormat::Xlsx, null, null, 'Archive'));

        self::assertSame('Archive', $second->sheet);
        self::assertSame(['Company name', 'Closed'], $second->headers);
        self::assertSame(['Example Old', 'yes'], $second->samples[2]);
        self::assertSame(['Contacts', 'Archive'], $second->sheets);
    }

    public function testXlsxRowsOfTheChosenSheet(): void
    {
        $reader = new OpenSpoutSourceReader();

        $rows = iterator_to_array($reader->rows(
            self::FIXTURES.'/two-sheets.xlsx',
            new SourceSettings(SourceFormat::Xlsx, null, null, 'Archive', 1),
        ));

        self::assertSame([2 => ['Example Old', 'yes']], $rows);
    }

    public function testUnknownSheetIsRejected(): void
    {
        $this->expectRejection('import.source.rejected.unknown_format');

        new OpenSpoutSourceReader()->inspect(self::FIXTURES.'/two-sheets.xlsx', new SourceSettings(SourceFormat::Xlsx, null, null, 'Missing'));
    }

    public function testXlsxNumbersAndDatesAreDisplayedAsText(): void
    {
        $rows = iterator_to_array(new OpenSpoutSourceReader()->rows(
            self::FIXTURES.'/numbers-and-dates.xlsx',
            new SourceSettings(SourceFormat::Xlsx),
        ));

        self::assertSame([2 => ['1234567', '0.5', '2026-10-01']], $rows);
    }

    public function testXlsxCellValuesOfEveryType(): void
    {
        $path = $this->directory.'/types.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Value']));
        foreach ([1234567.0, 12345678901234567890.0, 1.0E+25, 0.000012, -2.50, 100, true, false, 'text', new \DateTimeImmutable('2026-10-01 13:45:10')] as $value) {
            $writer->addRow(new Row([Cell::fromValue($value, $value instanceof \DateTimeImmutable ? new Style()->setFormat('yyyy-mm-dd hh:mm:ss') : null)]));
        }
        $writer->close();

        $rows = iterator_to_array(new OpenSpoutSourceReader()->rows($path, new SourceSettings(SourceFormat::Xlsx)));

        self::assertSame(
            ['1234567', '12345678901235000000', '10000000000000000000000000', '0.000012', '-2.5', '100', '1', '0', 'text', '2026-10-01 13:45:10'],
            array_map(static fn (array $row): string => $row[0], array_values($rows)),
        );
    }

    public function testXlsxBlankRowsKeepSourceNumbers(): void
    {
        $path = $this->directory.'/gaps.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Name', 'City']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Example', 'Praha']));
        $writer->close();
        $reader = new OpenSpoutSourceReader();

        $inspection = $reader->inspect($path);

        self::assertSame(1, $inspection->dataRowCount);
        self::assertSame([3 => ['Example', 'Praha']], iterator_to_array($reader->rows($path, $inspection->settings())));
    }

    public function testEmptyFileIsRejected(): void
    {
        $this->expectRejection('import.source.rejected.empty');

        new OpenSpoutSourceReader()->inspect($this->write(''));
    }

    public function testFileWithOnlyBlankRowsIsRejected(): void
    {
        $this->expectRejection('import.source.rejected.empty');

        new OpenSpoutSourceReader()->inspect($this->write("\n\n,\n"));
    }

    public function testBinaryFileIsRejectedAsUnknownFormat(): void
    {
        $path = $this->directory.'/legacy.xls';
        file_put_contents($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 64));
        $this->expectRejection('import.source.rejected.unknown_format');

        new OpenSpoutSourceReader()->inspect($path);
    }

    public function testZipWithoutWorkbookIsRejectedAsUnknownFormat(): void
    {
        $path = $this->directory.'/other.zip';
        $this->zip($path, ['readme.txt' => 'hello']);
        $this->expectRejection('import.source.rejected.unknown_format');

        new OpenSpoutSourceReader()->inspect($path);
    }

    public function testMissingFileIsRejectedAsUnknownFormat(): void
    {
        $this->expectRejection('import.source.rejected.unknown_format');

        new OpenSpoutSourceReader()->inspect($this->directory.'/missing.csv');
    }

    public function testFileOverTheSizeLimitIsRejected(): void
    {
        $path = $this->write("Name\n".str_repeat("x\n", 600));

        self::assertSame(600, new OpenSpoutSourceReader(maxFileBytes: 1_048_576)->inspect($path)->dataRowCount);

        file_put_contents($path, str_repeat('x', 2_097_152));
        try {
            new OpenSpoutSourceReader(maxFileBytes: 2_097_151)->inspect($path);
            self::fail('Expected rejection');
        } catch (SourceRejected $e) {
            self::assertSame('import.source.rejected.too_large', $e->reason);
            self::assertSame(['limit' => 1], $e->parameters);
        }
        new OpenSpoutSourceReader(maxFileBytes: 2_097_152)->inspect($path);
    }

    public function testDefaultSizeLimitIsFiftyMegabytes(): void
    {
        $path = $this->directory.'/big.csv';
        $handle = $this->create($path);
        ftruncate($handle, 50 * 1_048_576 + 1);
        fclose($handle);
        $this->expectRejection('import.source.rejected.too_large');

        new OpenSpoutSourceReader()->inspect($path);
    }

    public function testTooManyDataRowsAreRejectedByInspectAndRows(): void
    {
        $path = $this->write("Name\n".str_repeat("x\n", 6));
        $reader = new OpenSpoutSourceReader(maxDataRows: 5);

        try {
            $reader->inspect($path);
            self::fail('Expected rejection');
        } catch (SourceRejected $e) {
            self::assertSame('import.source.rejected.too_many_rows', $e->reason);
            self::assertSame(['limit' => 5], $e->parameters);
        }

        $this->expectRejection('import.source.rejected.too_many_rows');
        iterator_to_array($reader->rows($path, new SourceSettings(SourceFormat::Csv)));
    }

    public function testExactlyTheRowLimitIsAccepted(): void
    {
        $path = $this->write("Name\n".str_repeat("x\n", 5));
        $reader = new OpenSpoutSourceReader(maxDataRows: 5);

        self::assertSame(5, $reader->inspect($path)->dataRowCount);
        self::assertCount(5, iterator_to_array($reader->rows($path, new SourceSettings(SourceFormat::Csv))));
    }

    public function testRowLimitApplyToXlsxToo(): void
    {
        $this->expectRejection('import.source.rejected.too_many_rows');

        new OpenSpoutSourceReader(maxDataRows: 2)->inspect(self::FIXTURES.'/two-sheets.xlsx');
    }

    public function testRejectionHappensBeforeIterationStarts(): void
    {
        $path = $this->write("Name\nx\n");

        $this->expectRejection('import.source.rejected.too_large');

        new OpenSpoutSourceReader(maxFileBytes: 3)->rows($path, new SourceSettings(SourceFormat::Csv));
    }

    public function testArchiveOverTheUncompressedLimitIsRejected(): void
    {
        $path = $this->directory.'/large.xlsx';
        $this->zip($path, [
            'xl/workbook.xml' => 'a',
            'xl/worksheets/sheet1.xml' => random_bytes(2000),
            'xl/worksheets/sheet2.xml' => random_bytes(2000),
        ]);

        try {
            new OpenSpoutSourceReader(maxUncompressedBytes: 4001)->inspect($path);
        } catch (SourceRejected $e) {
            // The archive is no readable workbook; only the size check matters here.
            self::assertSame('import.source.rejected.unknown_format', $e->reason);
        }

        try {
            new OpenSpoutSourceReader(maxUncompressedBytes: 4000)->inspect($path);
            self::fail('Expected rejection');
        } catch (SourceRejected $e) {
            self::assertSame('import.source.rejected.archive_too_large', $e->reason);
            self::assertSame(['limit' => 0], $e->parameters);
        }
    }

    public function testDefaultUncompressedLimitIsFiveHundredMegabytes(): void
    {
        $parameters = new \ReflectionMethod(OpenSpoutSourceReader::class, '__construct')->getParameters();

        self::assertSame(50 * 1_048_576, $parameters[0]->getDefaultValue());
        self::assertSame(200_000, $parameters[1]->getDefaultValue());
        self::assertSame(500 * 1_048_576, $parameters[2]->getDefaultValue());
        self::assertSame(100, $parameters[3]->getDefaultValue());
    }

    public function testSingleEntryOverTheCompressionRatioIsRejected(): void
    {
        $path = $this->directory.'/bomb.xlsx';
        $this->zip($path, [
            'xl/workbook.xml' => 'a',
            'xl/worksheets/sheet1.xml' => str_repeat("\0", 4_000_000),
        ]);
        $this->expectRejection('import.source.rejected.suspicious_compression');

        new OpenSpoutSourceReader()->inspect($path);
    }

    public function testRatioExactlyAtTheLimitIsAccepted(): void
    {
        $path = $this->directory.'/ratio.xlsx';
        $this->zip($path, ['xl/workbook.xml' => str_repeat("\0", 10_000)]);
        $stat = (new \ZipArchive());
        $stat->open($path);
        $entry = $stat->statName('xl/workbook.xml');
        $stat->close();
        self::assertIsArray($entry);
        $ratio = (int) ceil($entry['size'] / max(1, $entry['comp_size']));

        try {
            new OpenSpoutSourceReader(maxCompressionRatio: $ratio)->inspect($path);
        } catch (SourceRejected $e) {
            self::assertSame('import.source.rejected.unknown_format', $e->reason);
        }

        $this->expectRejection('import.source.rejected.suspicious_compression');
        new OpenSpoutSourceReader(maxCompressionRatio: $ratio - 1)->inspect($path);
    }

    public function testStreaming200000CsvRowsStaysUnder64Megabytes(): void
    {
        $path = $this->directory.'/large.csv';
        $handle = $this->create($path);
        fwrite($handle, "Company name,Registration number,Email,City\n");
        for ($i = 1; $i <= 200_000; ++$i) {
            fwrite($handle, 'Example Company '.$i.',12345687,testing'.$i.'@example.com,Ústí nad Labem'."\n");
        }
        fclose($handle);
        $reader = new OpenSpoutSourceReader();

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $inspection = $reader->inspect($path);
        $count = 0;
        foreach ($reader->rows($path, $inspection->settings()) as $row) {
            ++$count;
        }
        $delta = memory_get_peak_usage() - $before;

        self::assertSame(200_000, $count);
        self::assertSame(200_000, $inspection->dataRowCount);
        self::assertLessThan(64 * 1_048_576, $delta);
    }

    public function testStreaming200000XlsxRowsStaysUnder64Megabytes(): void
    {
        $path = $this->directory.'/large.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Company name', 'Registration number', 'Email', 'City']));
        for ($i = 1; $i <= 200_000; ++$i) {
            $writer->addRow(Row::fromValues(['Example Company '.$i, 12345687, 'testing'.$i.'@example.com', 'Ústí nad Labem']));
        }
        $writer->close();
        $reader = new OpenSpoutSourceReader();

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $inspection = $reader->inspect($path);
        $count = 0;
        foreach ($reader->rows($path, $inspection->settings()) as $row) {
            ++$count;
        }
        $delta = memory_get_peak_usage() - $before;

        self::assertSame(200_000, $count);
        self::assertSame(200_000, $inspection->dataRowCount);
        self::assertLessThan(64 * 1_048_576, $delta);
    }

    public function testSettingsRoundTripThroughAnArray(): void
    {
        $settings = new SourceSettings(SourceFormat::Csv, Encoding::Windows1250, "\t", null, 3);

        self::assertEquals($settings, SourceSettings::fromArray($settings->toArray()));
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($settings->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertEquals($settings, SourceSettings::fromArray($decoded));
        $xlsx = new SourceSettings(SourceFormat::Xlsx, null, null, 'Contacts', 1);
        self::assertEquals($xlsx, SourceSettings::fromArray($xlsx->toArray()));
        self::assertEquals(new SourceSettings(SourceFormat::Csv), SourceSettings::fromArray(['format' => 'csv']));
    }

    private function write(string $contents): string
    {
        $path = $this->directory.'/'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param array<string, string> $entries
     */
    private function zip(string $path, array $entries): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
    }

    /**
     * @return resource
     */
    private function create(string $path)
    {
        $handle = fopen($path, 'wb');
        if (false === $handle) {
            self::fail('Cannot create '.$path);
        }

        return $handle;
    }

    private function expectRejection(string $reason): void
    {
        $this->expectException(SourceRejected::class);
        $this->expectExceptionMessageIsOrContains($reason);
    }
}
