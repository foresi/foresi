<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Application;

use Foresi\Purchasing\Application\DocumentLock;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\SetReceivedDocumentLines;
use Foresi\Purchasing\Application\TrashReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentLineInput;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Events\Support\ReadsEventLog;

/**
 * The lines of a draft or a recorded document, set on their own: they split
 * the recap as it stands, and a recorded document records what changed.
 */
final class SetReceivedDocumentLinesTest extends PurchasingTestCase
{
    use ReadsEventLog;

    public function testSettingLinesRecordsTheChangedEvent(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();
        $version = $this->document($id)->version();

        $this->lines()->set($id, [$this->line('Hosting', 70_000), $this->line('Domain', 30_000)], $version);

        $document = $this->document($id);
        self::assertSame(['Hosting', 'Domain'], array_map(static fn (\Foresi\Purchasing\Domain\ReceivedDocumentLine $line): string => $line->text(), $document->lines()));
        self::assertSame($version + 1, $document->version());
        self::assertSame([['subject' => $id, 'data' => ['received_document_id' => $id, 'what' => 'lines']]], $this->events('purchasing.received_document.changed'));
    }

    public function testEditingARecordedDocumentWithChangedLinesRecordsOnlyTheEditedEvent(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();

        $this->editing()->edit($id, $this->input(lines: [$this->line('Hosting', 70_000), $this->line('Domain', 30_000)]));

        self::assertSame(['Hosting', 'Domain'], array_map(static fn (\Foresi\Purchasing\Domain\ReceivedDocumentLine $line): string => $line->text(), $this->document($id)->lines()));
        self::assertSame([['subject' => $id, 'data' => ['received_document_id' => $id, 'what' => 'edited']]], $this->events('purchasing.received_document.changed'));
    }

    public function testTheSameLinesAgainRecordNothing(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();
        $this->lines()->set($id, [$this->line('Hosting', 100_000)]);
        $version = $this->document($id)->version();

        $this->lines()->set($id, [$this->line('Hosting', 100_000)]);

        self::assertSame($version, $this->document($id)->version());
        self::assertCount(1, $this->events('purchasing.received_document.changed'));
    }

    public function testADraftTakesLinesAndRecordsNoEvent(): void
    {
        $id = $this->draft();
        $this->editing()->edit($id, $this->input());

        $this->lines()->set($id, [$this->line('Hosting', 100_000)]);

        self::assertCount(1, $this->document($id)->lines());
        self::assertSame([], $this->events('purchasing.received_document.changed'));
    }

    public function testAnEmptyListRemovesTheLines(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();
        $this->lines()->set($id, [$this->line('Hosting', 100_000)]);

        $this->lines()->set($id, []);

        self::assertSame([], $this->document($id)->lines());
        self::assertCount(2, $this->events('purchasing.received_document.changed'));
    }

    public function testLinesThatDoNotAddUpAreRefused(): void
    {
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();

        $this->assertRefused(ReceivedDocumentRefused::LINES_DO_NOT_ADD_UP, fn (): ReceivedDocument => $this->lines()->set($id, [$this->line('Hosting', 99_999)]));
        self::assertSame([], $this->document($id)->lines());
        self::assertSame([], $this->events('purchasing.received_document.changed'));
    }

    public function testAStaleVersionTheLockAndTheTrashAreRefused(): void
    {
        $lock = new class implements DocumentLock {
            public bool $all = false;

            public function isLocked(ReceivedDocument $document): bool
            {
                return $this->all;
            }
        };
        self::getContainer()->set(DocumentLock::class, $lock);
        $id = $this->recording()->record($this->draft(), $this->input())->document->id();
        $version = $this->document($id)->version();
        $this->assertRefused(ReceivedDocumentRefused::STALE, fn (): ReceivedDocument => $this->lines()->set($id, [$this->line('Hosting', 100_000)], $version - 1));

        $lock->all = true;
        $this->assertRefused(ReceivedDocumentRefused::LOCKED, fn (): ReceivedDocument => $this->lines()->set($id, [$this->line('Hosting', 100_000)]));

        $lock->all = false;
        self::getContainer()->get(TrashReceivedDocument::class)->trash($id, 'Recorded twice');
        $lock->all = true;
        $this->assertRefused(ReceivedDocumentRefused::TRASHED, fn (): ReceivedDocument => $this->lines()->set($id, [$this->line('Hosting', 100_000)]));
    }

    public function testAGuestIsRefused(): void
    {
        $id = $this->draft();
        $this->signIn($this->guest);

        $this->expectException(PurchasingNotAllowed::class);
        $this->lines()->set($id, []);
    }

    private function lines(): SetReceivedDocumentLines
    {
        return self::getContainer()->get(SetReceivedDocumentLines::class);
    }

    private function line(string $text, int $base): ReceivedDocumentLineInput
    {
        return new ReceivedDocumentLineInput($text, Money::ofMinor($base, Currency::CZK), '21');
    }
}
