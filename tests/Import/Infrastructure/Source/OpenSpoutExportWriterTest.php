<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure\Source;

use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceSettings;
use App\Import\Domain\FieldDefinition;
use App\Import\Domain\FieldType;
use App\Import\Infrastructure\Source\OpenSpoutExportWriter;
use App\Import\Infrastructure\Source\OpenSpoutSourceReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class OpenSpoutExportWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/foresi-export-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCsvHasAByteOrderMarkSemicolonsAndTheTranslatedLabelsAsHeader(): void
    {
        $this->write(SourceFormat::Csv);

        $csv = (string) file_get_contents($this->path);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("label.name;label.city\n", $csv);
        self::assertStringContainsString("\"Example Company\";Prague\n", $csv);
    }

    public function testXlsxHoldsTheSameRows(): void
    {
        $this->write(SourceFormat::Xlsx);

        $reader = new OpenSpoutSourceReader();
        $inspection = $reader->inspect($this->path);
        self::assertSame(SourceFormat::Xlsx, $inspection->format);
        self::assertSame(['label.name', 'label.city'], $inspection->headers);
        self::assertSame([['Example Company', 'Prague'], ["'=SUM(1;2)", ''], ["'@cmd", "'+1"]], [...$reader->rows($this->path, new SourceSettings(SourceFormat::Xlsx, headerRow: 1))]);
    }

    public function testCellsThatASpreadsheetWouldRunAsFormulasAreNeutralised(): void
    {
        $this->write(SourceFormat::Csv);

        $csv = (string) file_get_contents($this->path);
        self::assertStringContainsString("'=SUM(1;2)", $csv);
        self::assertStringContainsString("'@cmd;'+1", $csv);
        self::assertStringNotContainsString(';=SUM', $csv);
    }

    private function write(SourceFormat $format): void
    {
        $fields = [new FieldDefinition('name', 'label.name', FieldType::Text), new FieldDefinition('city', 'label.city', FieldType::Text)];
        $rows = [
            ['name' => 'Example Company', 'city' => 'Prague', 'ignored' => 'x'],
            ['name' => '=SUM(1;2)'],
            ['name' => '@cmd', 'city' => '+1'],
        ];

        new OpenSpoutExportWriter(new IdentityTranslator())->write($fields, $rows, $format, $this->path);
    }
}
