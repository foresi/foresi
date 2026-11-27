<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Margin;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\DeductibleAdvances;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\IssuedDocumentFactory;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Integration\Margin\IssuedRevenue;
use Foresi\Margin\Contract\RevenueDocument;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Invoicing\Application\AdvanceTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The revenue Invoicing gives Margin: the documents the VAT module reads, each
 * on its tax point at the net of its rate summaries in CZK, the supply of an
 * advance counted once.
 */
final class IssuedRevenueTest extends AdvanceTestCase
{
    public function testAnAdvanceAndItsTaxDocumentCountOnce(): void
    {
        $advance = $this->advance([$this->line('96800.00')]);
        $this->pay($advance, '117128.00');
        $taxDocument = $this->onlyTaxDocumentOf($advance);
        $final = $this->issue->issue($this->finalInput([$this->line('96800.00'), ...$this->deductionOf($taxDocument)]));

        $documents = $this->between('2026-11-01', '2026-11-30');

        self::assertSame(9_680_000, $this->sum($documents));
        self::assertEqualsCanonicalizing(
            [[$taxDocument->id(), 'payment_tax_document', 9_680_000], [$final->id(), 'invoice', 0]],
            array_map(static fn (RevenueDocument $document): array => [$document->id, $document->kind, $document->netCzk->minor()], $documents),
            'The advance itself is left out, its tax document counts the supply, the final invoice only the rest.',
        );
    }

    public function testANonPayersAdvanceDeductedOnTheFinalInvoiceCountsOnce(): void
    {
        $this->reconfigureFirm(VatStatus::NotRegistered, null);
        $advance = $this->advance([$this->line('5000.00', treatment: TaxTreatment::NotApplicable, kind: null)]);
        $this->pay($advance, '3000.00');
        $offered = self::getContainer()->get(DeductibleAdvances::class)->for($this->customer->id(), null, DocumentLanguage::Czech);
        $final = $this->issue->issue($this->input([$this->line('5000.00', treatment: TaxTreatment::NotApplicable, kind: null), ...$offered[0]->lines], ['seriesId' => null]));

        $documents = $this->between('2026-11-01', '2026-11-30');

        self::assertSame(200_000, $this->reloaded($final)->payable()->minor(), 'The customer owes 2,000 more.');
        self::assertSame([$final->id()], array_map(static fn (RevenueDocument $document): string => $document->id, $documents));
        self::assertSame(500_000, $this->sum($documents), 'The advance never counted, so the deduction is added back.');
        self::assertSame([500_000, 0], array_map(static fn (\Foresi\Margin\Contract\RevenueLine $line): int => $line->netCzk->minor(), $documents[0]->lines), 'The line that deducts the advance carries nothing.');
    }

    public function testACorrectionDocumentLowersRevenueInItsMonth(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('1000.00')], ['taxPointDate' => new \DateTimeImmutable('2026-10-20')]));
        $this->correctionOf($this->reloaded($invoice), '-400.00');

        self::assertSame(100_000, $this->sum($this->between('2026-10-01', '2026-10-31')));
        $november = $this->between('2026-11-01', '2026-11-30');
        self::assertSame([[-40_000, 'correction_document']], array_map(static fn (RevenueDocument $document): array => [$document->netCzk->minor(), $document->kind], $november));
    }

    public function testRevenueFallsInTheMonthOfTheTaxPoint(): void
    {
        // The tax point is a day, not an instant: the hour of the day Prague and UTC disagree about decides nothing.
        $last = $this->issue->issue($this->input([$this->line('100.00')], ['issueDate' => new \DateTimeImmutable('2026-11-30'), 'taxPointDate' => new \DateTimeImmutable('2026-11-30')]));
        $first = $this->issue->issue($this->input([$this->line('200.00')], ['issueDate' => new \DateTimeImmutable('2026-11-30'), 'taxPointDate' => new \DateTimeImmutable('2026-12-01')]));
        $zone = new \DateTimeZone('Europe/Prague');

        $november = $this->between('2026-11-01', '2026-11-30');
        $december = $this->between('2026-12-01', '2026-12-31');

        self::assertSame([$last->id()], array_map(static fn (RevenueDocument $document): string => $document->id, $november));
        self::assertSame([$first->id()], array_map(static fn (RevenueDocument $document): string => $document->id, $december));
        self::assertSame(['2026-11-30', 'Europe/Prague'], [$november[0]->taxPointOn->format('Y-m-d'), $november[0]->taxPointOn->getTimezone()->getName()]);
        self::assertSame('2026-12-01', $december[0]->taxPointOn->format('Y-m-d'), 'The day of the document is its tax point, not its issue date.');
        $late = new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-12-01', $late->setTimezone($zone)->format('Y-m-d'), 'It is already December in Prague.');
    }

    public function testADocumentCarriesItsCustomerItsUrlAndItsLines(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('1000.00'), $this->line('500.00', text: 'Travel')]));

        $documents = $this->between('2026-11-01', '2026-11-30');

        self::assertCount(1, $documents);
        $document = $documents[0];
        self::assertSame([$invoice->id(), $this->customer->id(), '/invoices/'.$invoice->id(), 150_000], [$document->id, $document->customerId, $document->url, $document->netCzk->minor()]);
        self::assertSame($this->reloaded($invoice)->number(), $document->number);
        self::assertSame([100_000, 50_000], array_map(static fn (\Foresi\Margin\Contract\RevenueLine $line): int => $line->netCzk->minor(), $document->lines));
        self::assertSame([null, null], array_map(static fn (\Foresi\Margin\Contract\RevenueLine $line): ?string => $line->sourceLineId, $document->lines));
    }

    public function testAForeignCurrencyDocumentCountsInCzkAtItsOwnRate(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('1000.00'), $this->line('500.00', kind: VatRateKind::Reduced)]));
        // Issuing takes the home currency only; the stored document is turned into one in euros at 25.125.
        $this->em->getConnection()->executeStatement("UPDATE invoice SET currency = 'EUR', exchange_rate = '25.125' WHERE id = :id", ['id' => $invoice->id()]);

        $documents = $this->between('2026-11-01', '2026-11-30');

        self::assertCount(1, $documents);
        self::assertSame(3_768_750, $documents[0]->netCzk->minor(), 'Both rate summaries converted: 1,500.00 EUR at 25.125.');
        self::assertSame([2_512_500, 1_256_250], array_map(static fn (\Foresi\Margin\Contract\RevenueLine $line): int => $line->netCzk->minor(), $documents[0]->lines), 'Each line converted at the document rate; here they add up to the document to the heller.');
    }

    public function testAnAdvanceAndTheTrashAddNothing(): void
    {
        $this->advance([$this->line('1000.00')]);
        $trashed = $this->issue->issue($this->input([$this->line('300.00')]));
        $this->trash->trash($trashed->id(), 'Wrong customer');

        self::assertSame([], $this->between('2026-11-01', '2026-11-30'));
    }

    public function testADocumentIsFoundByItsIdAndByOneOfItsLines(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('1000.00'), $this->line('500.00', text: 'Travel')]));
        $this->em->clear();
        $source = $this->source();

        $document = $source->document($invoice->id());

        self::assertInstanceOf(RevenueDocument::class, $document);
        self::assertSame([$invoice->id(), 150_000, 'Europe/Prague', '2026-11-01'], [$document->id, $document->netCzk->minor(), $document->taxPointOn->getTimezone()->getName(), $document->taxPointOn->format('Y-m-d')]);
        self::assertEquals($document, iterator_to_array($source->between(new \DateTimeImmutable('2026-11-01', new \DateTimeZone('Europe/Prague')), new \DateTimeImmutable('2026-11-30', new \DateTimeZone('Europe/Prague'))), false)[0], 'Built as between() builds it.');
        $second = $document->lines[1]->id;
        self::assertEquals($document, $source->documentOfLine($second));
        self::assertNull($source->document(Id::generate()));
        self::assertNull($source->documentOfLine(Id::generate()));
    }

    public function testNoDocumentIsFoundForAnAdvanceOrATrashedDocument(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $trashed = $this->issue->issue($this->input([$this->line('300.00')]));
        $this->trash->trash($trashed->id(), 'Wrong customer');
        $trashedLine = $this->em->getConnection()->fetchOne('SELECT id FROM invoice_line WHERE invoice_id = :id', ['id' => $trashed->id()]);
        $advanceLine = $this->em->getConnection()->fetchOne('SELECT id FROM invoice_line WHERE invoice_id = :id', ['id' => $advance->id()]);
        $this->em->clear();
        $source = $this->source();

        self::assertNull($source->document($advance->id()));
        self::assertNull($source->document($trashed->id()));
        self::assertNull($source->documentOfLine($this->stringOf($advanceLine)));
        self::assertNull($source->documentOfLine($this->stringOf($trashedLine)));
    }

    private function stringOf(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return list<RevenueDocument>
     */
    private function between(string $from, string $to): array
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $this->em->clear();

        return iterator_to_array($this->source()->between(new \DateTimeImmutable($from, $zone), new \DateTimeImmutable($to, $zone)), false);
    }

    private function source(): IssuedRevenue
    {
        $container = self::getContainer();

        return new IssuedRevenue($container->get(InvoiceStore::class), $container->get(IssuedDocumentFactory::class), $container->get(UrlGeneratorInterface::class), $this->em, $container->get(InstanceClock::class));
    }

    /**
     * @param list<RevenueDocument> $documents
     */
    private function sum(array $documents): int
    {
        return array_sum(array_map(static fn (RevenueDocument $document): int => $document->netCzk->minor(), $documents));
    }

    /**
     * @return list<LineInput>
     */
    private function deductionOf(Invoice $document): array
    {
        foreach (self::getContainer()->get(DeductibleAdvances::class)->for($this->customer->id(), null, DocumentLanguage::Czech) as $deductible) {
            if ($deductible->id === $document->id()) {
                return $deductible->lines;
            }
        }
        self::fail('The document is not offered.');
    }

    /**
     * @param list<LineInput>      $lines
     * @param array<string, mixed> $overrides
     */
    private function finalInput(array $lines, array $overrides = []): InvoiceInput
    {
        return $this->input($lines, [...['kind' => DocumentKind::Invoice], ...$overrides]);
    }
}
