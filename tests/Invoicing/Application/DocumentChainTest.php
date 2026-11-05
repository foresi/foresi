<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\DeductibleAdvances;
use App\Invoicing\Application\DocumentChain;
use App\Invoicing\Domain\Invoice;
use App\Party\Domain\DocumentLanguage;

/**
 * From the advance, a tax document and the final invoice, the same chain shows.
 */
final class DocumentChainTest extends AdvanceTestCase
{
    public function testEveryDocumentOfTheChainShowsTheWholeChain(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $first = $this->pay($advance, '605.00', '2026-11-02');
        $this->pay($advance, '605.00', '2026-11-10');
        [$one, $two] = $this->taxDocumentsOf($advance);
        $lines = [];
        foreach (self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), null, DocumentLanguage::Czech) as $deductible) {
            $lines = [...$lines, ...$deductible->lines];
        }
        $final = $this->issue->issue($this->input([$this->line('3000.00'), ...$lines]));

        $expected = [[
            'advance' => ['id' => $advance->id(), 'number' => 'Z920260001'],
            'payments' => [
                ['receivedOn' => '2026-11-02', 'amount' => 60500, 'taxDocument' => ['id' => $one->id(), 'number' => 'D820260001']],
                ['receivedOn' => '2026-11-10', 'amount' => 60500, 'taxDocument' => ['id' => $two->id(), 'number' => 'D820260002']],
            ],
            'finals' => [['id' => $final->id(), 'number' => '20260001']],
        ]];
        foreach ([$advance, $one, $two, $final] as $document) {
            self::assertSame($expected, $this->chainOf($document));
        }
        self::assertSame($first->id(), $one->paymentId());
    }

    public function testAnAdvanceWithoutPaymentsShowsItselfOnly(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);

        self::assertSame([['advance' => ['id' => $advance->id(), 'number' => 'Z920260001'], 'payments' => [], 'finals' => []]], $this->chainOf($advance));
    }

    public function testAnInvoiceWithoutDeductionsHasNoChain(): void
    {
        self::assertSame([], $this->chainOf($this->issue->issue($this->input())));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chainOf(Invoice $document): array
    {
        $this->em->clear();

        return array_map(static fn (array $chain): array => [
            'advance' => $chain['advance'],
            'payments' => array_map(static fn (array $payment): array => ['receivedOn' => $payment['receivedOn']->format('Y-m-d'), 'amount' => $payment['amount']->minor(), 'taxDocument' => $payment['taxDocument']], $chain['payments']),
            'finals' => $chain['finals'],
        ], self::getContainer()->get(DocumentChain::class)->of($this->reloaded($document)));
    }
}
