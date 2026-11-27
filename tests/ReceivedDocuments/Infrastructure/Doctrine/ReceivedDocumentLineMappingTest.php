<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Infrastructure\Doctrine;

use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\ReceivedDocuments\Domain\ReceivedDocument;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentLine;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentLineInput;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\ReceivedDocuments\Application\PurchasingTestCase;

/**
 * The lines of a received document as the database keeps them: in their
 * order, rewritten in place, and audited under their document.
 */
final class ReceivedDocumentLineMappingTest extends PurchasingTestCase
{
    public function testTheLinesAreKeptInTheirOrder(): void
    {
        $id = $this->withLines([$this->line('Hosting', 70_000), $this->line('Domain', 30_000)]);

        $document = $this->document($id);

        self::assertSame([[0, 'Hosting', 70_000, 'CZK', '21'], [1, 'Domain', 30_000, 'CZK', '21']], $this->stored($document));
    }

    public function testRewritingTheLinesReusesTheirPositions(): void
    {
        $id = $this->withLines([$this->line('Hosting', 70_000), $this->line('Domain', 30_000)]);

        $document = $this->document($id);
        $document->setLines([$this->line('Domain', 30_000), $this->line('Hosting', 50_000), $this->line('Mail', 20_000)]);
        $this->em->flush();
        self::assertSame([[0, 'Domain', 30_000, 'CZK', '21'], [1, 'Hosting', 50_000, 'CZK', '21'], [2, 'Mail', 20_000, 'CZK', '21']], $this->stored($this->document($id)));

        $document = $this->document($id);
        $document->setLines([$this->line('Everything', 100_000)]);
        $this->em->flush();
        self::assertSame([[0, 'Everything', 100_000, 'CZK', '21']], $this->stored($this->document($id)));
        self::assertSame(1, $this->em->getRepository(ReceivedDocumentLine::class)->count([]));
    }

    public function testReorderedLinesKeepTheirIdsInTheDatabase(): void
    {
        $id = $this->withLines([$this->line('Hosting', 70_000), $this->line('Domain', 30_000)]);
        $document = $this->document($id);
        [$first, $second] = array_map(static fn (ReceivedDocumentLine $line): string => $line->id(), $document->lines());

        $document->setLines([$this->line('Domain', 30_000, $second), $this->line('Hosting', 70_000, $first)]);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->document($id)->lines();
        self::assertSame([[$second, 0, 'Domain'], [$first, 1, 'Hosting']], array_map(static fn (ReceivedDocumentLine $line): array => [$line->id(), $line->position(), $line->text()], $stored));
        self::assertSame(2, $this->em->getRepository(ReceivedDocumentLine::class)->count([]));

        $this->document($id)->setLines([$this->line('Domain', 100_000, $second)]);
        $this->em->flush();
        $this->em->clear();
        self::assertSame([[$second, 0]], array_map(static fn (ReceivedDocumentLine $line): array => [$line->id(), $line->position()], $this->document($id)->lines()));
    }

    public function testRemovingTheLinesDeletesThem(): void
    {
        $id = $this->withLines([$this->line('Hosting', 100_000)]);

        $document = $this->document($id);
        $document->setLines([]);
        $this->em->flush();

        self::assertSame([], $this->document($id)->lines());
        self::assertSame(0, $this->em->getRepository(ReceivedDocumentLine::class)->count([]));
    }

    public function testLineChangesAreAuditedUnderTheirDocument(): void
    {
        $id = $this->withLines([$this->line('Hosting', 70_000), $this->line('Domain', 30_000)]);
        $document = $this->document($id);
        $document->setLines([$this->line('Hosting', 100_000, $document->lines()[0]->id())]);
        $this->em->flush();

        $entries = array_map(
            static fn (AuditEntry $entry): array => [$entry->entityClass(), $entry->action()],
            array_values(array_filter($this->em->getRepository(AuditEntry::class)->findBy(['rootId' => $id], ['id' => 'ASC']), static fn (AuditEntry $entry): bool => ReceivedDocumentLine::class === $entry->entityClass())),
        );

        self::assertSame([
            [ReceivedDocumentLine::class, AuditAction::Created],
            [ReceivedDocumentLine::class, AuditAction::Created],
            [ReceivedDocumentLine::class, AuditAction::Updated],
            [ReceivedDocumentLine::class, AuditAction::Deleted],
        ], $entries);
    }

    /**
     * @param list<ReceivedDocumentLineInput> $lines
     */
    private function withLines(array $lines): string
    {
        $id = $this->recording()->create($this->input())->document->id();
        $document = $this->document($id);
        $document->setLines($lines);
        $this->em->flush();

        return $id;
    }

    /**
     * @return list<array{int, string, int, string, string}>
     */
    private function stored(ReceivedDocument $document): array
    {
        return array_map(static fn (ReceivedDocumentLine $line): array => [$line->position(), $line->text(), $line->base()->minor(), $line->base()->currency()->value, $line->vatRate()], $document->lines());
    }

    private function line(string $text, int $base, ?string $id = null): ReceivedDocumentLineInput
    {
        return new ReceivedDocumentLineInput($text, Money::ofMinor($base, Currency::CZK), '21', $id);
    }
}
