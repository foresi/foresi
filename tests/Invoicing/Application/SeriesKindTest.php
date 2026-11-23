<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;

/**
 * A document takes its kind from its series, and every path that accepts a series
 * checks it is of the document's kind.
 */
final class SeriesKindTest extends InvoiceTestCase
{
    public function testTheDefaultOfAKindIsNotTheDefaultOfAnother(): void
    {
        $this->series->markDefault();
        $advances = $this->seriesOfKind(DocumentKind::Advance, 'Advances', 'ZF{YYYY}{NNNN}');
        $advances->markDefault();
        $this->em->flush();
        $this->em->clear();

        self::assertSame($this->series->id(), $this->catalog->defaultFor(DocumentKind::Invoice)->id());
        self::assertSame($advances->id(), $this->catalog->defaultFor(DocumentKind::Advance)->id());
        self::assertSame($this->series->id(), $this->catalog->defaultFor()->id());
        self::assertNull($this->storedIdOf($this->catalog->defaultFor(DocumentKind::PaymentTaxDocument)));
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

        self::assertSame($this->series->id(), $this->catalog->defaultFor()->id());
        self::assertSame($more->id(), $this->catalog->defaultFor(DocumentKind::Advance)->id());
    }

    public function testTheCatalogListsTheSeriesOfOneKind(): void
    {
        $advances = $this->seriesOfKind(DocumentKind::Advance, 'Advances', 'ZF{YYYY}{NNNN}');
        $this->em->flush();

        self::assertSame([$advances->id()], array_map(static fn (Series $series): string => $series->id(), $this->catalog->all(DocumentKind::Advance)));
        self::assertSame([$this->series->id()], array_map(static fn (Series $series): string => $series->id(), $this->catalog->all(DocumentKind::Invoice)));
        self::assertCount(2, $this->catalog->all());
    }

    public function testASeriesOfAnotherKindIsRefusedOnIssue(): void
    {
        $advances = $this->seriesOfKind(DocumentKind::Advance, 'Advances', 'ZF{YYYY}{NNNN}');

        $this->assertRefusedFor('invoice.problem.series_kind', fn (): mixed => $this->issue->issue($this->input(overrides: ['seriesId' => $advances->id()])));
        $this->assertRefusedFor('invoice.problem.series_kind', fn (): mixed => $this->issue->issue($this->input(overrides: ['kind' => DocumentKind::Advance])));
    }

    private function seriesOfKind(DocumentKind $kind, string $name, string $format): Series
    {
        $series = new Series($name, SeriesFormat::fromString($format), $kind);
        $this->em->persist($series);
        $this->em->flush();

        return $series;
    }

    private function storedIdOf(Series $series): ?string
    {
        return \in_array($series, $this->catalog->every(), true) ? $series->id() : null;
    }
}
