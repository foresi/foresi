<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Banking\Application\Candidates;
use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\DocumentExports;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Domain\ExportState;
use App\Party\Application\TransactionalWork;
use App\Tests\Banking\Support\LockLog;
use App\Tests\Banking\Support\LoggingInvoiceStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DocumentExportsTest extends InvoiceTestCase
{
    use ClockSensitiveTrait;

    private DocumentExports $exports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exports = self::getContainer()->get(DocumentExports::class);
    }

    public function testAnIssuedDocumentIsNotExported(): void
    {
        $invoice = $this->issue->issue($this->input());

        self::assertSame(ExportState::NotExported, $invoice->exportState());
        self::assertNull($invoice->exportedAt());
        self::assertNull($invoice->changedAfterExportAt());
    }

    public function testMarkingRecordsTheMomentOfEachDocumentGivenAndNoOther(): void
    {
        self::mockTime('2026-11-06 10:00:00');
        $one = $this->issue->issue($this->input());
        $two = $this->issue->issue($this->input());
        $other = $this->issue->issue($this->input());

        $this->exports->markExported([$two->id(), $one->id(), $two->id()]);

        foreach ([$one, $two] as $invoice) {
            $invoice = $this->reloaded($invoice);
            self::assertSame(ExportState::Exported, $invoice->exportState());
            self::assertSame('2026-11-06 10:00:00', $invoice->exportedAt()?->format('Y-m-d H:i:s'));
        }
        self::assertSame(ExportState::NotExported, $this->reloaded($other)->exportState());
    }

    public function testMarkingLocksEveryRowOnceInTheOrderOfTheIdsBeforeItReadsAny(): void
    {
        $one = $this->issue->issue($this->input());
        $two = $this->issue->issue($this->input());
        $log = new LockLog(self::getContainer()->get(Candidates::class));
        $exports = new DocumentExports(new LoggingInvoiceStore($this->store, $log), self::getContainer()->get(TransactionalWork::class), self::getContainer()->get(ConflictRetry::class), self::getContainer()->get(ClockInterface::class));
        $ids = [$one->id(), $two->id()];
        sort($ids);

        $exports->markExported([$ids[1], $ids[0], $ids[1]]);

        self::assertSame(['row:'.$ids[0], 'row:'.$ids[1], 'lock:'.$ids[0]], $log->entries);
        self::assertSame(ExportState::Exported, $this->reloaded($one)->exportState());
        self::assertSame(ExportState::Exported, $this->reloaded($two)->exportState());
    }

    public function testNoIdsMarkNothing(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->exports->markExported([]);

        self::assertSame(ExportState::NotExported, $this->reloaded($invoice)->exportState());
    }

    public function testAnUnknownDocumentMarksNoneOfThem(): void
    {
        $invoice = $this->issue->issue($this->input());

        try {
            $this->exports->markExported([$invoice->id(), '01963c5e-0000-7000-8000-00000000000a']);
            self::fail('Expected an unknown invoice.');
        } catch (UnknownInvoice) {
        }

        self::assertSame(ExportState::NotExported, $this->reloaded($invoice)->exportState());
    }

    public function testEditingAnExportedDocumentChangesItOnceAndAnOpenFormStillSaves(): void
    {
        self::mockTime('2026-11-06 10:00:00');
        $invoice = $this->issue->issue($this->input());
        $this->exports->markExported([$invoice->id()]);

        self::mockTime('2026-11-07 09:00:00');
        // Opened before the export: the export is no save, so the form still holds the version.
        $saved = $this->edit->edit($invoice->id(), $this->input(overrides: ['description' => 'Changed']), 1);

        self::assertSame(ExportState::Changed, $saved->exportState());
        self::assertSame('2026-11-06 10:00:00', $saved->exportedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-07 09:00:00', $saved->changedAfterExportAt()?->format('Y-m-d H:i:s'));

        self::mockTime('2026-11-08 09:00:00');
        $saved = $this->edit->edit($invoice->id(), $this->input(overrides: ['description' => 'Again']), $saved->version());

        self::assertSame('2026-11-07 09:00:00', $this->reloaded($saved)->changedAfterExportAt()?->format('Y-m-d H:i:s'));
    }

    public function testEditingADocumentNeverExportedLeavesItSo(): void
    {
        $invoice = $this->issue->issue($this->input());

        $saved = $this->edit->edit($invoice->id(), $this->input(overrides: ['description' => 'Changed']), 1);

        self::assertSame(ExportState::NotExported, $this->reloaded($saved)->exportState());
        self::assertNull($saved->changedAfterExportAt());
    }

    public function testPaymentDeliveryAndTrashAreNoChangeOfTheContent(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = $this->issue->issue($this->input());
        $this->exports->markExported([$invoice->id(), $other->id()]);

        $this->delivered->mark($invoice->id());
        $this->trash->trash($other->id(), 'Wrong customer');
        $this->trash->restore($other->id());

        self::assertSame(ExportState::Exported, $this->reloaded($invoice)->exportState());
        self::assertSame(ExportState::Exported, $this->reloaded($other)->exportState());
    }

    public function testExportingAgainTakesTheNewMomentAndForgetsTheChange(): void
    {
        self::mockTime('2026-11-06 10:00:00');
        $invoice = $this->issue->issue($this->input());
        $this->exports->markExported([$invoice->id()]);
        $this->edit->edit($invoice->id(), $this->input(overrides: ['description' => 'Changed']), 1);

        self::mockTime('2026-11-09 08:00:00');
        $this->exports->markExported([$invoice->id()]);
        $invoice = $this->reloaded($invoice);

        self::assertSame(ExportState::Exported, $invoice->exportState());
        self::assertSame('2026-11-09 08:00:00', $invoice->exportedAt()?->format('Y-m-d H:i:s'));
        self::assertNull($invoice->changedAfterExportAt());
    }

    public function testClearingGoesBackToNotExportedAndGivesTheSavedDocument(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->exports->markExported([$invoice->id()]);
        $this->edit->edit($invoice->id(), $this->input(overrides: ['description' => 'Changed']), 1);

        $cleared = $this->exports->clear($invoice->id());

        self::assertSame($invoice->id(), $cleared->id());
        $invoice = $this->reloaded($invoice);
        self::assertSame(ExportState::NotExported, $invoice->exportState());
        self::assertNull($invoice->exportedAt());
        self::assertNull($invoice->changedAfterExportAt());
    }

    public function testTheMarksHoldInTheTrashAndLeaveTheVersionAlone(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'Wrong customer');

        $this->exports->markExported([$invoice->id()]);
        self::assertSame(ExportState::Exported, $this->reloaded($invoice)->exportState());
        $cleared = $this->exports->clear($invoice->id());

        self::assertSame(ExportState::NotExported, $cleared->exportState());
        self::assertSame(2, $this->reloaded($invoice)->version());
    }

    public function testClearingAnUnknownDocumentSaysSo(): void
    {
        $this->expectException(UnknownInvoice::class);

        $this->exports->clear('01963c5e-0000-7000-8000-00000000000a');
    }

    public function testTheAggregateTakesTheNewestExportAndClearsBothMoments(): void
    {
        $invoice = $this->issue->issue($this->input());
        $invoice->markExported(new \DateTimeImmutable('2026-11-06 10:00:00'));
        $invoice->markExported(new \DateTimeImmutable('2026-11-06 11:00:00'));

        self::assertSame('2026-11-06 11:00:00', $invoice->exportedAt()?->format('Y-m-d H:i:s'));
        $invoice->clearExport();
        self::assertSame(ExportState::NotExported, $invoice->exportState());
        self::assertNull($invoice->exportedAt());
    }
}
