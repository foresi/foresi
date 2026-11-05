<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;

/**
 * A document takes its kind from its series, and every path that accepts a series
 * checks it is of the document's kind.
 */
final class SeriesKindTest extends InvoiceTestCase
{
    public function testADocumentTakesTheKindOfItsSeries(): void
    {
        $advances = $this->seriesOfKind(DocumentKind::Advance, 'Advances', 'ZF{YYYY}{NNNN}');

        $invoice = $this->issue->issue($this->input(overrides: ['seriesId' => $advances->id(), 'kind' => DocumentKind::Advance]));

        self::assertSame(DocumentKind::Advance, $this->reloaded($invoice)->kind());
        self::assertSame('ZF20260001', $this->reloaded($invoice)->number());
    }

    public function testAMissingSeriesTakesTheStandardSeriesOfTheKind(): void
    {
        $invoice = $this->issue->issue($this->input(overrides: ['seriesId' => null, 'kind' => DocumentKind::Advance]));

        $stored = $this->reloaded($invoice);
        self::assertSame(DocumentKind::Advance, $stored->kind());
        self::assertSame('Z920260001', $stored->number());
        self::assertSame('standard_advance', $stored->series()->name());
        self::assertTrue($stored->series()->isDefault());
        self::assertSame($stored->series()->id(), $this->catalog->defaultFor($stored->firm(), DocumentKind::Advance)->id());
    }

    public function testTheDefaultOfAKindIsNotTheDefaultOfAnother(): void
    {
        $this->series->markDefault();
        $advances = $this->seriesOfKind(DocumentKind::Advance, 'Advances', 'ZF{YYYY}{NNNN}');
        $advances->markDefault();
        $this->em->flush();
        $this->em->clear();

        self::assertSame($this->series->id(), $this->catalog->defaultFor($this->firm, DocumentKind::Invoice)->id());
        self::assertSame($advances->id(), $this->catalog->defaultFor($this->firm, DocumentKind::Advance)->id());
        self::assertSame($this->series->id(), $this->catalog->defaultFor($this->firm)->id());
        self::assertNull($this->storedIdOf($this->catalog->defaultFor($this->firm, DocumentKind::PaymentTaxDocument)));
    }

    public function testMakingADefaultMovesOnlyTheDefaultOfItsKind(): void
    {
        $this->series->markDefault();
        $advances = $this->seriesOfKind(DocumentKind::Advance, 'Advances', 'ZF{YYYY}{NNNN}');
        $advances->markDefault();
        $more = $this->seriesOfKind(DocumentKind::Advance, 'More advances', 'ZG{YYYY}{NNNN}');
        $this->em->flush();

        $this->catalog->makeDefault($more);
        $this->em->flush();
        $this->em->clear();

        self::assertSame($this->series->id(), $this->catalog->defaultFor($this->firm)->id());
        self::assertSame($more->id(), $this->catalog->defaultFor($this->firm, DocumentKind::Advance)->id());
    }

    public function testTheCatalogListsTheSeriesOfOneKind(): void
    {
        $advances = $this->seriesOfKind(DocumentKind::Advance, 'Advances', 'ZF{YYYY}{NNNN}');
        $this->em->flush();

        self::assertSame([$advances->id()], array_map(static fn (Series $series): string => $series->id(), $this->catalog->forFirm($this->firm, DocumentKind::Advance)));
        self::assertSame([$this->series->id()], array_map(static fn (Series $series): string => $series->id(), $this->catalog->forFirm($this->firm, DocumentKind::Invoice)));
        self::assertCount(2, $this->catalog->forFirm($this->firm));
    }

    public function testASeriesOfAnotherKindIsRefusedOnIssue(): void
    {
        $advances = $this->seriesOfKind(DocumentKind::Advance, 'Advances', 'ZF{YYYY}{NNNN}');

        $this->assertRefusedFor('invoice.problem.series_kind', fn (): mixed => $this->issue->issue($this->input(overrides: ['seriesId' => $advances->id()])));
        $this->assertRefusedFor('invoice.problem.series_kind', fn (): mixed => $this->issue->issue($this->input(overrides: ['kind' => DocumentKind::Advance])));
    }

    public function testRenumberingIntoASeriesOfAnotherKindIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());
        $advances = $this->seriesOfKind(DocumentKind::Advance, 'Advances', 'ZF{YYYY}{NNNN}');

        $this->assertRefusedFor('invoice.problem.series_kind', fn () => $this->edited($invoice, $this->input(overrides: ['seriesId' => $advances->id()])));
        // The kind of the input counts only for a new document: the edited one keeps its own.
        $this->assertRefusedFor('invoice.problem.series_kind', fn () => $this->edited($invoice, $this->input(overrides: ['seriesId' => $advances->id(), 'kind' => DocumentKind::Advance])));
        self::assertSame(DocumentKind::Invoice, $this->reloaded($invoice)->kind());
        self::assertSame($this->series->id(), $this->reloaded($invoice)->series()->id());
    }

    public function testAnEditKeepingTheSeriesIsNotAskedForTheKind(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->edited($invoice, $this->input(overrides: ['seriesId' => null, 'description' => 'Changed', 'kind' => DocumentKind::Advance]));

        self::assertSame('Changed', $this->reloaded($invoice)->description());
    }

    public function testTheDocumentRefusesASeriesOfAnotherKind(): void
    {
        $invoice = $this->issue->issue($this->input());
        $advances = $this->seriesOfKind(DocumentKind::Advance, 'Advances', 'ZF{YYYY}{NNNN}');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('A document keeps its kind, so it takes a series of that kind.');

        $this->reloaded($invoice)->changeNumber($advances, 'ZF20260009');
    }

    private function seriesOfKind(DocumentKind $kind, string $name, string $format): Series
    {
        $series = new Series($this->firm, $name, SeriesFormat::fromString($format), $kind);
        $this->em->persist($series);
        $this->em->flush();

        return $series;
    }

    private function storedIdOf(Series $series): ?string
    {
        return \in_array($series, $this->catalog->everyForFirm($this->firm), true) ? $series->id() : null;
    }
}
