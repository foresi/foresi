<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Perf;

use Foresi\Invoicing\Application\InvoicePdf;
use Foresi\Invoicing\Application\IssuedDocumentFactory;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * How long an invoice PDF takes to render, by the number of its lines, up to the most an
 * invoice may have. Excluded from the default suite; the perf workflow and bin/perf run it
 * (docs/performance.md). The median of ten warm renders is kept: the spread between renders
 * was under 3 % when the baseline was measured.
 */
#[Group('perf')]
final class InvoicePdfTimingTest extends InvoiceTestCase
{
    private const array LINES = [1, 10, 100];
    private const int RUNS = 10;
    /** The budget of the largest invoice, in milliseconds: 0.78 s measured on an M1 Pro, room for a slower runner. */
    private const float BUDGET_MS = 1500.0;

    public function testAnInvoiceOfTheMostLinesRendersWithinItsBudget(): void
    {
        $pdf = self::getContainer()->get(InvoicePdf::class);
        $documents = self::getContainer()->get(IssuedDocumentFactory::class);
        $results = [];
        foreach (self::LINES as $count) {
            $lines = array_map(fn (int $n): \Foresi\Invoicing\Application\LineInput => $this->line('100.00', text: \sprintf('Line %d of the work, with diacritics: žluťoučký kůň', $n)), range(1, $count));
            $document = $documents->of($this->issue->issue($this->input($lines, ['variableSymbol' => (string) (20260100 + $count)]))->id());
            // The first render loads the fonts; only the warm ones count.
            $pdf->render($document);
            $times = [];
            $bytes = '';
            for ($run = 0; $run < self::RUNS; ++$run) {
                $start = hrtime(true);
                $bytes = $pdf->render($document)->bytes;
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            sort($times);
            $results[] = [
                'lines' => $count,
                'medianMs' => round(($times[4] + $times[5]) / 2, 1),
                'minMs' => round($times[0], 1),
                'maxMs' => round($times[self::RUNS - 1], 1),
                'kilobytes' => intdiv(\strlen($bytes), 1024),
                'peakMemoryMb' => memory_get_peak_usage(true) >> 20,
            ];
        }
        $this->write($results);

        $largest = $results[array_key_last($results)];
        self::assertLessThanOrEqual(self::BUDGET_MS, $largest['medianMs'], \sprintf('An invoice of %d lines took %.0f ms to render.', $largest['lines'], $largest['medianMs']));
    }

    /**
     * @param list<array<string, int|float>> $results
     */
    private function write(array $results): void
    {
        $root = self::getContainer()->getParameter('kernel.project_dir');
        $dir = $root.'/var/perf';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($dir.'/pdf.json', json_encode(['budgetMs' => self::BUDGET_MS, 'renders' => $results], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
    }
}
