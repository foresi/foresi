<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Application\CostPairings;
use Foresi\Margin\Application\CostShareCalculation;
use Foresi\Margin\Application\CostSources;
use Foresi\Margin\Application\KnownGaps;
use Foresi\Margin\Application\MappingVersions;
use Foresi\Margin\Application\OccurrenceFill;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostLine;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Domain\ShareSplitter;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Instance\Support\InstanceZone;
use Foresi\Tests\Margin\Support\FixedCostSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The shares of a cost document from its mappings, on a real database with a
 * source that gives the documents the test names.
 */
final class CostShareCalculationTest extends KernelTestCase
{
    private const string DOCUMENT = '0197a1de-0000-7000-8000-0000000000d1';
    private const string CLIENT_A = '0197a1de-0000-7000-8000-00000000000a';
    private const string CLIENT_B = '0197a1de-0000-7000-8000-00000000000b';

    private EntityManagerInterface $em;
    private FixedCostSource $source;
    private CostShareCalculation $calculation;

    protected function setUp(): void
    {
        self::bootKernel();
        InstanceZone::set(self::getContainer());
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->source = new FixedCostSource();
        $this->calculation = new CostShareCalculation($this->em, new CostSources([$this->source]), new ShareSplitter(), self::getContainer()->get(InstanceClock::class), new CostPairings(), new MappingVersions($this->em), new OccurrenceFill(new ShareSplitter()));
    }

    public function testALineMappingBeatsTheDocumentMapping(): void
    {
        $this->document([$this->line('line-1', 70_000), $this->line('line-2', 30_000)]);
        $this->mapDocument(Recipient::client(self::CLIENT_A));
        $this->mapLine('line-1', new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::client(self::CLIENT_B), '50'),
            new AllocationShare(Recipient::own(), '50'),
        ]));

        $this->recalculate();

        self::assertSame([
            'line-1 '.self::CLIENT_B.' 35000 line',
            'line-1 own 35000 line',
            'line-2 '.self::CLIENT_A.' 30000 document',
        ], $this->shares());
    }

    public function testAnUnmappedLineIsStoredAsUnassigned(): void
    {
        $this->document([$this->line('line-1', 70_000)]);

        $this->recalculate();

        self::assertSame(['line-1 - 70000 unassigned'], $this->shares());
    }

    public function testAForeignDocumentWithoutARateIsAKnownGapNotZero(): void
    {
        $this->document([$this->line('line-1', 70_000), new CostLine('line-2', 'Voice API', null)]);
        $this->mapDocument(Recipient::own());

        $this->recalculate();

        self::assertSame(['line-1 own 70000 document'], $this->shares());
        $gaps = new KnownGaps(new CostSources([$this->source]));
        self::assertTrue($gaps->missingExchangeRate(self::DOCUMENT));
        self::assertSame([self::DOCUMENT], $gaps->documentsMissingExchangeRate(['other', self::DOCUMENT]));
    }

    public function testATrashedDocumentHasNoSharesAndARestoredOneHasThemAgain(): void
    {
        $lines = [$this->line('line-1', 70_000)];
        $this->document($lines);
        $this->mapDocument(Recipient::client(self::CLIENT_A));
        $this->recalculate();
        self::assertCount(1, $this->shares());

        $this->document($lines, trashed: true);
        $this->recalculate();
        self::assertSame([], $this->shares());
        self::assertFalse(new KnownGaps(new CostSources([$this->source]))->missingExchangeRate(self::DOCUMENT));

        $this->document($lines);
        $this->recalculate();
        self::assertSame(['line-1 '.self::CLIENT_A.' 70000 document'], $this->shares(), 'The mapping stayed in the trash.');
    }

    public function testARemovedLineDropsItsAllocation(): void
    {
        $this->document([$this->line('line-1', 70_000), $this->line('line-2', 30_000)]);
        $this->mapLine('line-2', new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::client(self::CLIENT_A))]));
        $this->recalculate();
        self::assertSame(['line-1 - 70000 unassigned', 'line-2 '.self::CLIENT_A.' 30000 line'], $this->shares());

        $this->document([$this->line('line-1', 70_000)]);
        $this->recalculate();

        self::assertSame(['line-1 - 70000 unassigned'], $this->shares());
        self::assertSame([], $this->em->getRepository(LineAllocation::class)->findAll());
    }

    public function testRecalculatingTwiceBeforeAFlushDoesNotDoubleTheShares(): void
    {
        $this->document([$this->line('line-1', 70_000)]);

        $this->calculation->recalculate(self::DOCUMENT);
        $this->calculation->recalculate(self::DOCUMENT);
        $this->em->flush();

        self::assertSame(['line-1 - 70000 unassigned'], $this->shares());
    }

    public function testTheMonthIsThatOfTheInstanceZone(): void
    {
        // 23:30 UTC on 30 November is 00:30 on 1 December in Prague.
        $this->document([$this->line('line-1', 10_000)], basis: new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('UTC')));
        $this->recalculate();
        // 22:30 UTC on 30 November is 23:30 on 30 November in Prague.
        $this->document([$this->line('line-1', 10_000)], basis: new \DateTimeImmutable('2026-11-30 22:30:00', new \DateTimeZone('UTC')));
        $this->recalculate();
        self::assertSame(['2026-11-01'], $this->months());

        $this->document([$this->line('line-1', 10_000)], basis: new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('UTC')));
        $this->recalculate();
        self::assertSame(['2026-12-01'], $this->months());
    }

    public function testACreditNoteGivesNegativeShares(): void
    {
        $this->document([$this->line('line-1', -10_001)]);
        $this->mapLine('line-1', new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::client(self::CLIENT_A), '30'),
            new AllocationShare(Recipient::own(), '70'),
        ]));

        $this->recalculate();

        self::assertSame(['line-1 '.self::CLIENT_A.' -3000 line', 'line-1 own -7001 line'], $this->shares());
    }

    public function testEveryLineIsCountedWhateverComesBeforeIt(): void
    {
        $this->document([new CostLine('line-1', 'No rate', null), $this->line('line-2', 10_000), $this->line('line-3', 20_000), $this->line('line-4', 30_000), $this->line('line-5', 40_000)]);
        // A mapping of a line the document no longer has stands before the others, and two lines have their own.
        $this->mapLine('gone', new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::own())]));
        $this->mapLine('line-3', new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::client(self::CLIENT_A))]));
        $this->mapLine('line-4', new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::client(self::CLIENT_B))]));

        $this->recalculate();

        self::assertSame([
            'line-2 - 10000 unassigned',
            'line-3 '.self::CLIENT_A.' 20000 line',
            'line-4 '.self::CLIENT_B.' 30000 line',
            'line-5 - 40000 unassigned',
        ], $this->shares());
        self::assertSame(['line-3', 'line-4'], array_map(static fn (LineAllocation $mapping): string => $mapping->lineKey(), $this->em->getRepository(LineAllocation::class)->findBy([], ['lineKey' => 'ASC'])));
    }

    public function testADocumentNeverTouchesTheMappingsOrSharesOfAnother(): void
    {
        $other = '0197a1de-0000-7000-8000-0000000000d2';
        $this->source->documents[$other] = new CostDocument($other, null, new \DateTimeImmutable('2026-11-24', new \DateTimeZone('Europe/Prague')), [$this->line('other-line', 50_000)], false, 'EN-2', '/purchasing/'.$other);
        $this->em->persist(new DocumentAllocation(Uuid::v7()->toRfc4122(), $other, Recipient::client(self::CLIENT_B), new \DateTimeImmutable(), 'user'));
        $this->em->persist(new LineAllocation(Uuid::v7()->toRfc4122(), $other, 'other-line', new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::own())]), new \DateTimeImmutable(), 'user'));
        $this->em->flush();
        $this->calculation->recalculate($other);
        $this->em->flush();
        $this->em->clear();
        $this->document([$this->line('line-1', 70_000)]);

        $this->recalculate();

        self::assertSame(['line-1 - 70000 unassigned', 'other-line own 50000 line'], $this->shares(), 'The other document keeps its shares, and this one is not assigned to its client.');
        self::assertSame(1, $this->em->getRepository(LineAllocation::class)->count(['documentId' => $other]));
    }

    public function testAZeroAmountGivesNoShare(): void
    {
        $this->document([$this->line('line-1', 0), $this->line('line-2', 100)]);

        $this->recalculate();

        self::assertSame(['line-2 - 100 unassigned'], $this->shares());
    }

    public function testEveryDocumentWithAGapIsNamedNotJustTheFirst(): void
    {
        $this->document([new CostLine('line-1', 'Voice API', null)]);
        $second = '0197a1de-0000-7000-8000-0000000000d2';
        $this->source->documents[$second] = new CostDocument($second, null, new \DateTimeImmutable('2026-11-24'), [new CostLine('line-1', 'Voice API', null)], false, 'EN-2', '/purchasing/'.$second);

        $gaps = new KnownGaps(new CostSources([$this->source]))->documentsMissingExchangeRate([self::DOCUMENT, 'unknown', $second]);

        self::assertSame([self::DOCUMENT, $second], $gaps);
    }

    /**
     * @param list<CostLine> $lines
     */
    private function document(array $lines, bool $trashed = false, ?\DateTimeImmutable $basis = null): void
    {
        $this->source->documents[self::DOCUMENT] = new CostDocument(self::DOCUMENT, null, $basis ?? new \DateTimeImmutable('2026-11-24', new \DateTimeZone('Europe/Prague')), $lines, $trashed, 'EN-1 Cloud Host', '/purchasing/'.self::DOCUMENT);
    }

    private function line(string $key, int $minor): CostLine
    {
        return new CostLine($key, 'Hosting', Money::ofMinor($minor, Currency::CZK));
    }

    private function mapLine(string $key, Allocation $allocation): void
    {
        $this->em->persist(new LineAllocation(Uuid::v7()->toRfc4122(), self::DOCUMENT, $key, $allocation, new \DateTimeImmutable(), 'user'));
        $this->em->flush();
    }

    private function mapDocument(Recipient $recipient): void
    {
        $this->em->persist(new DocumentAllocation(Uuid::v7()->toRfc4122(), self::DOCUMENT, $recipient, new \DateTimeImmutable(), 'user'));
        $this->em->flush();
    }

    private function recalculate(): void
    {
        $this->calculation->recalculate(self::DOCUMENT);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @return list<string> "line recipient amount source", in line and amount order
     */
    private function shares(): array
    {
        $shares = $this->em->getRepository(CostShare::class)->findAll();
        $rows = array_map(static fn (CostShare $share): string => \sprintf('%s %s %d %s', $share->lineKey(), $share->recipient()?->toString() ?? '-', $share->amount()->minor(), $share->source()->value), $shares);
        sort($rows);

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function months(): array
    {
        return array_values(array_unique(array_map(static fn (CostShare $share): string => $share->month()->format('Y-m-d'), $this->em->getRepository(CostShare::class)->findAll())));
    }
}
