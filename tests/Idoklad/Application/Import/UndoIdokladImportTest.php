<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Idoklad\Application\Import;

use Foresi\Idoklad\Application\Import\UndoIdokladImport;
use Foresi\Idoklad\Domain\IdokladImportRun;
use Foresi\Idoklad\Domain\ImportMode;
use Foresi\Idoklad\Domain\ImportReport;
use PHPUnit\Framework\TestCase;

final class UndoIdokladImportTest extends TestCase
{
    private const string STARTED = '2026-11-06 10:00:00';

    public function testARunThatEndedWellMayBeUndoneForThirtyDays(): void
    {
        $run = $this->finished(ImportMode::Run);

        self::assertTrue(UndoIdokladImport::isUndoable($run, new \DateTimeImmutable('2026-11-07 10:00:00')));
        self::assertTrue(UndoIdokladImport::isUndoable($run, new \DateTimeImmutable('2026-12-06 10:00:00')), 'The thirtieth day is still within.');
        self::assertFalse(UndoIdokladImport::isUndoable($run, new \DateTimeImmutable('2026-12-06 10:00:01')));
    }

    public function testACheckARunningRunAFailedRunAndAnUndoneRunCannotBeUndone(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 10:00:00');
        $running = new IdokladImportRun(ImportMode::Run, new \DateTimeImmutable(self::STARTED));
        $undone = $this->finished(ImportMode::Run);
        $undone->markUndone($now);

        self::assertFalse(UndoIdokladImport::isUndoable($this->finished(ImportMode::Check), $now));
        self::assertFalse(UndoIdokladImport::isUndoable($running, $now));
        self::assertFalse(UndoIdokladImport::isUndoable($this->finished(ImportMode::Run, 'The import failed.'), $now));
        self::assertFalse(UndoIdokladImport::isUndoable($undone, $now));
    }

    private function finished(ImportMode $mode, ?string $error = null): IdokladImportRun
    {
        $run = new IdokladImportRun($mode, new \DateTimeImmutable(self::STARTED));
        $run->finish(new \DateTimeImmutable(self::STARTED), new ImportReport(), $error);

        return $run;
    }
}
