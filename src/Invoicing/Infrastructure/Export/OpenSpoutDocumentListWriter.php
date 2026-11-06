<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Export;

use App\Invoicing\Application\DocumentListWriter;
use App\Invoicing\Application\InvoiceSumLine;
use App\Invoicing\Application\ListedDocument;
use App\Invoicing\Application\ListFileFormat;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\RateSummary;
use App\Invoicing\Domain\TaxTreatment;
use App\Shared\Domain\Money;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The list as the address book exports: CSV as UTF-8 with a byte order mark and
 * semicolons, or XLSX. In CSV a text a spreadsheet would run as a formula gets a
 * leading apostrophe, and an amount has the decimal separator of the language. In
 * XLSX a text is a typed string, never a formula, and an amount is a number to add up.
 * Dates are YYYY-MM-DD, as in the pack for the accountant.
 */
final readonly class OpenSpoutDocumentListWriter implements DocumentListWriter
{
    private const string FORMULA_START = "=+-@\t\r";
    private const array COLUMNS = ['number', 'kind', 'customer', 'registration', 'vat_id', 'issue_date', 'tax_point', 'due_date', 'currency', 'exchange_rate', 'base', 'vat', 'total', 'payable'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function write(array $rates, iterable $documents, ListFileFormat $format, string $path, string $locale): void
    {
        $writer = ListFileFormat::Xlsx === $format ? new XlsxWriter() : new CsvWriter(new CsvOptions(FIELD_DELIMITER: ';', SHOULD_ADD_BOM: true));
        $writer->openToFile($path);
        try {
            $this->header($writer, $rates, $format, $locale);
            foreach ($documents as $document) {
                $writer->addRow(new Row($this->cells($document, $rates, $format, $locale)));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * @param list<InvoiceSumLine> $rates
     */
    private function header(WriterInterface $writer, array $rates, ListFileFormat $format, string $locale): void
    {
        $titles = array_map(fn (string $column): string => $this->trans('invoice.export.column.'.$column, $locale), self::COLUMNS);
        $titles[] = $this->trans('invoice.list_export.paid', $locale);
        $titles[] = $this->trans('invoice.list_export.left', $locale);
        $titles[] = $this->trans('invoice.list_export.state', $locale);
        foreach ($rates as $rate) {
            $label = $this->label($rate->treatment, $rate->rate, $locale);
            $titles[] = $this->translator->trans('invoice.list_export.rate_base', ['label' => $label], null, $locale);
            $titles[] = $this->translator->trans('invoice.list_export.rate_vat', ['label' => $label], null, $locale);
        }
        $writer->addRow(new Row(array_map(fn (string $title): StringCell => $this->text($title, $format), $titles)));
    }

    /**
     * @param list<InvoiceSumLine> $rates
     *
     * @return list<Cell>
     */
    private function cells(ListedDocument $document, array $rates, ListFileFormat $format, string $locale): array
    {
        $row = $document->row;
        $cells = [
            $this->text($row->number, $format),
            $this->text($this->trans('series.kind.'.$row->kind->value, $locale), $format),
            $this->text($row->customerName, $format),
            $this->text($document->registrationNumber ?? '', $format),
            $this->text($document->vatId ?? '', $format),
            $this->text($row->issueDate->format('Y-m-d'), $format),
            $this->text($document->taxPointDate?->format('Y-m-d') ?? '', $format),
            $this->text($row->dueDate->format('Y-m-d'), $format),
            $this->text($document->currency->value, $format),
            $this->decimal($document->exchangeRate, $format, $locale),
            $this->amount($document->net, $format, $locale),
            $this->amount($document->vat, $format, $locale),
            $this->amount($document->gross, $format, $locale),
            $this->amount($row->payable, $format, $locale),
            $this->amount($row->paid, $format, $locale),
            $this->amount($row->remaining(), $format, $locale),
            $this->text($this->trans('invoice.export_state.'.$row->exportState()->value, $locale), $format),
        ];
        $summaries = [];
        foreach ($document->summaries as $summary) {
            $summaries[$this->key($summary->treatment, $summary->rate)] = $summary;
        }
        foreach ($rates as $rate) {
            $summary = $summaries[$this->key($rate->treatment, $rate->rate)] ?? null;
            if ($summary instanceof RateSummary) {
                $cells[] = $this->amount($summary->base, $format, $locale);
                $cells[] = $this->amount($summary->vat, $format, $locale);
            } else {
                // A rate the document has no line in stays empty, not a zero it never had.
                $cells[] = $this->text('', $format);
                $cells[] = $this->text('', $format);
            }
        }

        return $cells;
    }

    private function label(TaxTreatment $treatment, ?Percent $rate, string $locale): string
    {
        return $treatment->hasRate() && $rate instanceof Percent
            ? $this->translator->trans('invoice.list_export.rate', ['rate' => $rate->basisPoints() / 100], null, $locale)
            : $this->trans('invoice.vat.'.$treatment->value, $locale);
    }

    private function key(TaxTreatment $treatment, ?Percent $rate): string
    {
        return $treatment->value.'|'.($treatment->hasRate() && $rate instanceof Percent ? $rate->basisPoints() : '-');
    }

    private function amount(Money $money, ListFileFormat $format, string $locale): Cell
    {
        $minor = $money->minor();
        if (ListFileFormat::Xlsx === $format) {
            return new NumericCell($minor / 100);
        }

        return $this->decimal(\sprintf('%s%d.%02d', $minor < 0 ? '-' : '', intdiv(abs($minor), 100), abs($minor) % 100), $format, $locale);
    }

    private function decimal(string $value, ListFileFormat $format, string $locale): Cell
    {
        if (ListFileFormat::Xlsx === $format) {
            return new NumericCell((float) $value);
        }

        // A number, so no formula: the apostrophe stays out of a negative amount.
        return new StringCell(str_replace('.', $this->trans('invoice.list_export.decimal', $locale), $value));
    }

    private function trans(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], null, $locale);
    }

    /**
     * Always a string cell; in CSV, where any cell may run, one that starts as a formula is quoted.
     */
    private function text(string $text, ListFileFormat $format): StringCell
    {
        return new StringCell(ListFileFormat::Csv === $format && '' !== $text && str_contains(self::FORMULA_START, $text[0]) ? "'".$text : $text);
    }
}
