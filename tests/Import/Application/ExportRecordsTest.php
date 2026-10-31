<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Application;

use App\Import\Application\ExportRecords;
use App\Import\Application\ExportWriter;
use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\RecordSnapshot;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Application\Target\WriteResult;
use App\Import\Domain\BlankCells;
use App\Import\Domain\FieldDefinition;
use App\Import\Domain\FieldType;
use PHPUnit\Framework\TestCase;

final class ExportRecordsTest extends TestCase
{
    public function testRecordsAreReadInBatchesAndWrittenInOrderThroughTheTargetsFields(): void
    {
        $target = new RecordingTarget();
        $writer = new CollectingWriter();

        new ExportRecords(new TargetRegistry([$target]), $writer)->write('thing', (static function (): \Generator {
            yield from range(1, 1201);
        })(), SourceFormat::Xlsx, 'php://output');

        self::assertSame([ExportRecords::BATCH, ExportRecords::BATCH, 201], array_map(count(...), $target->batches));
        self::assertSame(range(1, 1201), array_merge(...$target->batches));
        self::assertEquals($target->fields(), $writer->fields);
        self::assertCount(1201, $writer->rows);
        self::assertSame(['name' => 'Thing 1201'], $writer->rows[1200]);
        self::assertSame(SourceFormat::Xlsx, $writer->format);
        self::assertSame('php://output', $writer->path);
    }

    public function testNoIdsAreReadAsNoBatch(): void
    {
        $target = new RecordingTarget();
        $writer = new CollectingWriter();

        new ExportRecords(new TargetRegistry([$target]), $writer)->write('thing', [], SourceFormat::Csv, 'php://output');

        self::assertSame([], $target->batches);
        self::assertSame([], $writer->rows);
    }

    public function testAnExactBatchIsNotFollowedByAnEmptyOne(): void
    {
        $target = new RecordingTarget();
        $writer = new CollectingWriter();

        new ExportRecords(new TargetRegistry([$target]), $writer)->write('thing', range(1, ExportRecords::BATCH), SourceFormat::Csv, 'php://output');

        self::assertCount(1, $target->batches);
        self::assertCount(ExportRecords::BATCH, $writer->rows);
    }

    public function testAnUnknownTargetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExportRecords(new TargetRegistry([]), new CollectingWriter())->write('nothing', [], SourceFormat::Csv, 'php://output');
    }
}

final class CollectingWriter implements ExportWriter
{
    /** @var list<FieldDefinition> */
    public array $fields = [];
    /** @var list<array<string, string>> */
    public array $rows = [];
    public ?SourceFormat $format = null;
    public string $path = '';

    public function write(array $fields, iterable $rows, SourceFormat $format, string $path): void
    {
        $this->fields = $fields;
        foreach ($rows as $row) {
            $this->rows[] = $row;
        }
        $this->format = $format;
        $this->path = $path;
    }
}

final class RecordingTarget implements ImportTarget
{
    /** @var list<list<int>> */
    public array $batches = [];

    public function key(): string
    {
        return 'thing';
    }

    public function labelKey(): string
    {
        return 'import.target.thing';
    }

    public function fields(): array
    {
        return [new FieldDefinition('name', 'field.name', FieldType::Text)];
    }

    public function export(array $ids): iterable
    {
        $this->batches[] = $ids;
        foreach ($ids as $id) {
            yield ['name' => 'Thing '.$id];
        }
    }

    public function matchKeys(): array
    {
        return [];
    }

    public function validate(array $values): array
    {
        return [];
    }

    public function match(string $matchKey, array $values): array
    {
        return [];
    }

    public function create(array $values): WriteResult
    {
        return new WriteResult(1, RecordSnapshot::none());
    }

    public function update(int $id, array $values, BlankCells $blankCells): WriteResult
    {
        return new WriteResult($id, RecordSnapshot::none());
    }

    public function restoreArchived(int $id): void
    {
    }

    public function archive(int $id): RecordSnapshot
    {
        return RecordSnapshot::none();
    }

    public function revert(int $id, RecordSnapshot $before): void
    {
    }

    public function changedSince(int $id, RecordSnapshot $before, \DateTimeImmutable $since, array $exceptCorrelationIds): bool
    {
        return false;
    }

    public function activeIds(): iterable
    {
        return [];
    }
}
