<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\DocumentListWriter;
use App\Invoicing\Application\ExportDocumentList;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\InvoiceSumLine;
use App\Invoicing\Application\ListedDocument;
use App\Invoicing\Application\ListedDocuments;
use App\Invoicing\Application\ListFileFormat;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\TaxTreatment;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class ExportDocumentListTest extends TestCase
{
    public function testItReadsTheDocumentsInBatchesInTheOrderOfTheList(): void
    {
        $documents = new FakeListedDocuments(['a', 'b', 'c', 'd', 'e']);
        $writer = new RecordingListWriter();
        $filter = new InvoiceFilter(null, InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1, month: '2026-10');

        new ExportDocumentList($documents, $writer, 2)->write($filter, ListFileFormat::Xlsx, '/tmp/list.xlsx', 'en');

        self::assertSame([['a', 'b'], ['c', 'd'], ['e']], $documents->batches);
        self::assertSame(['a', 'b', 'c', 'd', 'e'], $writer->numbers);
        self::assertSame($documents->rates, $writer->rates);
        self::assertSame([$filter, $filter], $documents->filters);
        self::assertSame(ListFileFormat::Xlsx, $writer->format);
        self::assertSame('/tmp/list.xlsx', $writer->path);
    }

    public function testAFullLastBatchAsksForNoEmptyOne(): void
    {
        $documents = new FakeListedDocuments(['a', 'b', 'c', 'd']);
        $writer = new RecordingListWriter();

        new ExportDocumentList($documents, $writer, 2)->write(new InvoiceFilter(null, InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1), ListFileFormat::Csv, 'php://memory', 'en');

        self::assertSame([['a', 'b'], ['c', 'd']], $documents->batches);
        self::assertSame(['a', 'b', 'c', 'd'], $writer->numbers);
    }

    public function testAnEmptyListWritesTheHeaderAlone(): void
    {
        $documents = new FakeListedDocuments([]);
        $writer = new RecordingListWriter();

        new ExportDocumentList($documents, $writer)->write(new InvoiceFilter(null, InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1), ListFileFormat::Csv, 'php://memory', 'en');

        self::assertSame([], $documents->batches);
        self::assertSame([], $writer->numbers);
    }

    public function testFiveHundredDocumentsAreOneBatch(): void
    {
        $ids = array_map(static fn (int $index): string => 'id'.$index, range(1, 501));
        $documents = new FakeListedDocuments($ids);

        new ExportDocumentList($documents, new RecordingListWriter())->write(new InvoiceFilter(null, InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1), ListFileFormat::Csv, 'php://memory', 'en');

        self::assertSame([500, 1], array_map(\count(...), $documents->batches));
    }
}

final class FakeListedDocuments implements ListedDocuments
{
    /** @var list<list<string>> */
    public array $batches = [];
    /** @var list<InvoiceFilter> */
    public array $filters = [];
    /** @var list<InvoiceSumLine> */
    public array $rates;

    /**
     * @param list<string> $ids
     */
    public function __construct(private readonly array $ids)
    {
        $this->rates = [new InvoiceSumLine(TaxTreatment::Standard, Percent::fromString('21'), Money::ofMinor(100, Currency::CZK), Money::ofMinor(21, Currency::CZK), Money::ofMinor(121, Currency::CZK))];
    }

    public function listedIds(InvoiceFilter $filter): array
    {
        $this->filters[] = $filter;

        return $this->ids;
    }

    public function listedRates(InvoiceFilter $filter): array
    {
        $this->filters[] = $filter;

        return $this->rates;
    }

    public function listedDocuments(array $ids): array
    {
        $this->batches[] = $ids;
        $zero = Money::ofMinor(0, Currency::CZK);
        $day = new \DateTimeImmutable('2026-10-05');

        return array_map(static fn (string $id): ListedDocument => new ListedDocument(
            new InvoiceRow($id, $id, 'Example Firm s.r.o.', 'Example Customer a.s.', $day, $day, $zero, false, false, false, $zero, PaymentState::Unpaid),
            null,
            null,
            $day,
            Currency::CZK,
            '1',
            $zero,
            $zero,
            $zero,
            [],
        ), $ids);
    }
}

final class RecordingListWriter implements DocumentListWriter
{
    /** @var list<string> */
    public array $numbers = [];
    /** @var list<InvoiceSumLine> */
    public array $rates = [];
    public ?ListFileFormat $format = null;
    public ?string $path = null;

    public function write(array $rates, iterable $documents, ListFileFormat $format, string $path, string $locale): void
    {
        $this->rates = $rates;
        $this->format = $format;
        $this->path = $path;
        foreach ($documents as $document) {
            $this->numbers[] = $document->row->number;
        }
    }
}
