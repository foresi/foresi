<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Export;

use App\Work\Application\TimeEntryRow;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The entries of a time report as the invoice list exports: CSV as UTF-8 with a
 * byte order mark and semicolons, a text a spreadsheet would run as a formula
 * with a leading apostrophe, the hours with the decimal separator of the
 * language and the day as YYYY-MM-DD. One line per entry: the day, the person,
 * the client, the project, the task, the minutes, the hours, whether it is
 * billable and the note.
 */
final readonly class TimeReportCsv
{
    private const string FORMULA_START = "=+-@\t\r";
    private const array COLUMNS = ['date', 'person', 'client', 'project', 'task', 'minutes', 'hours', 'billable', 'note'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param iterable<TimeEntryRow> $entries
     */
    public function write(iterable $entries, string $path, string $locale): void
    {
        $writer = new Writer(new Options(FIELD_DELIMITER: ';', SHOULD_ADD_BOM: true));
        $writer->openToFile($path);
        try {
            $writer->addRow(new Row(array_map(fn (string $column): StringCell => $this->text($this->trans('work.time.report.csv.'.$column, $locale)), self::COLUMNS)));
            $decimal = $this->trans('work.time.report.csv.decimal', $locale);
            $yes = $this->trans('work.time.report.csv.yes', $locale);
            $no = $this->trans('work.time.report.csv.no', $locale);
            foreach ($entries as $entry) {
                $minutes = $entry->minutes ?? 0;
                $writer->addRow(new Row([
                    new StringCell($entry->workedOn->format('Y-m-d')),
                    $this->text($entry->personName),
                    $this->text($entry->clientName ?? ''),
                    $this->text($entry->projectName),
                    $this->text($entry->taskTitle ?? ''),
                    new StringCell((string) $minutes),
                    // A number, so no formula: the hours to two places, as an invoice line takes them.
                    new StringCell(str_replace('.', $decimal, \sprintf('%.2f', $minutes / 60))),
                    new StringCell($entry->billable ? $yes : $no),
                    $this->text($entry->note),
                ]));
            }
        } finally {
            $writer->close();
        }
    }

    private function trans(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], null, $locale);
    }

    /**
     * A cell a spreadsheet would run as a formula is quoted.
     */
    private function text(string $text): StringCell
    {
        return new StringCell('' !== $text && str_contains(self::FORMULA_START, $text[0]) ? "'".$text : $text);
    }
}
