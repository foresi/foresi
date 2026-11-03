<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure\Source;

use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceSettings;
use App\Import\Domain\Import;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\ImportRow;
use App\Import\Domain\Mapping;
use App\Import\Domain\RowError;
use App\Import\Domain\RowOutcome;
use App\Import\Infrastructure\Source\OpenSpoutErrorFileWriter;
use App\Import\Infrastructure\Source\OpenSpoutSourceReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class OpenSpoutErrorFileWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/foresi-errors-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCsvKeepsTheDelimiterOfTheUploadAndNeutralisesEveryFormulaStart(): void
    {
        $this->write(new SourceSettings(SourceFormat::Csv, null, ',', null, 1), [
            ['=SUM(1,2)', '+1'],
            ['-1', '@cmd'],
            ["\tTab", "\rCR"],
            ['Example Company'],
        ]);

        self::assertSame(
            "\xEF\xBB\xBF"
            ."Name,'=Head,import.error_file.column\n"
            ."\"'=SUM(1,2)\",'+1,import.error_file.in_Name\n"
            ."'-1,'@cmd,import.error_file.in_Name\n"
            ."\"'\tTab\",\"'\rCR\",import.error_file.in_Name\n"
            ."\"Example Company\",,import.error_file.in_Name\n",
            (string) file_get_contents($this->path),
        );
    }

    public function testCsvWithoutADelimiterUsesSemicolonsAndQuotesWhatNeedsIt(): void
    {
        $this->write(new SourceSettings(SourceFormat::Csv, null, null, null, 1), [
            ["two\nlines", 'with "quotes"'],
            ['semi;colon', 'Ústí nad Labem'],
        ]);

        self::assertSame(
            "\xEF\xBB\xBF"
            ."Name;'=Head;import.error_file.column\n"
            ."\"two\nlines\";\"with \"\"quotes\"\"\";import.error_file.in_Name\n"
            ."\"semi;colon\";\"Ústí nad Labem\";import.error_file.in_Name\n",
            (string) file_get_contents($this->path),
        );
    }

    public function testXlsxHoldsTextCellsWithTheFormulaStartsNeutralised(): void
    {
        $this->write(new SourceSettings(SourceFormat::Xlsx, null, null, 'Sheet1', 1), [
            ['=SUM(1;2)', '0123'],
            ["two\nlines", '1e5'],
        ]);

        self::assertSame([
            'A1' => 'Name', 'B1' => "'=Head", 'C1' => 'import.error_file.column',
            'A2' => "'=SUM(1;2)", 'B2' => '0123', 'C2' => 'import.error_file.in_Name',
            'A3' => "two\nlines", 'B3' => '1e5', 'C3' => 'import.error_file.in_Name',
        ], XlsxSheet::textCells($this->path));
        XlsxSheet::assertOneWrappedTextStyle($this->path);

        $reader = new OpenSpoutSourceReader();
        self::assertSame(
            [2 => ['=SUM(1;2)', '0123', 'import.error_file.in_Name'], 3 => ["two\nlines", '1e5', 'import.error_file.in_Name']],
            iterator_to_array($reader->rows($this->path, new SourceSettings(SourceFormat::Xlsx, headerRow: 1))),
        );
    }

    /**
     * @param list<list<string>> $raws
     */
    private function write(SourceSettings $settings, array $raws): void
    {
        $import = new Import('party', 'key', 'source', $settings->toArray(), '00000000-0000-0000-0000-000000000000', new \DateTimeImmutable('2026-01-01'));
        $import->readHeaders(['Name', '=Head']);
        $import->useMapping(new Mapping([0 => 'name', 1 => null]), ImportOptions::standard(), null);

        $rows = [];
        foreach ($raws as $index => $raw) {
            $row = new ImportRow($import, $index + 2, 0, $raw);
            $row->decide(RowOutcome::Error, [new RowError('name', 'import.error.required')]);
            $rows[] = $row;
        }

        new OpenSpoutErrorFileWriter(new IdentityTranslator())->write($import, $rows, 'en', $this->path);
    }
}
