<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Domain;

use App\Import\Domain\BlankCells;
use App\Import\Domain\ColumnProposal;
use App\Import\Domain\DateOrder;
use App\Import\Domain\DecimalSeparator;
use App\Import\Domain\IllegalTransition;
use App\Import\Domain\Import;
use App\Import\Domain\ImportAmbiguousMatch;
use App\Import\Domain\ImportMissingRecord;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\ImportProfile;
use App\Import\Domain\ImportRow;
use App\Import\Domain\ImportState;
use App\Import\Domain\Mapping;
use App\Import\Domain\MatchReason;
use App\Import\Domain\ReplaceScope;
use App\Import\Domain\RowOutcome;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Import::class)]
#[CoversClass(ImportRow::class)]
#[CoversClass(ImportMissingRecord::class)]
#[CoversClass(ImportAmbiguousMatch::class)]
#[CoversClass(ImportProfile::class)]
#[CoversClass(RowOutcome::class)]
final class PipelineRulesTest extends TestCase
{
    private const array SETTINGS = ['format' => 'csv', 'encoding' => 'utf-8', 'delimiter' => ';', 'sheet' => null, 'headerRow' => 1];

    public function testANewImportHasNoProposalsAndNoUndoCounts(): void
    {
        $import = $this->newImport();

        self::assertSame([], $import->proposals());
        self::assertNull($import->presetKey());
        self::assertSame(0, $import->reverted());
        self::assertSame(0, $import->changedAfterImport());
    }

    public function testProposalsAndThePresetAreKept(): void
    {
        $import = $this->newImport();
        $proposals = [new ColumnProposal(0, 'name', MatchReason::Preset), new ColumnProposal(1, null, MatchReason::None)];

        $import->propose($proposals, 'idoklad');

        self::assertEquals($proposals, $import->proposals());
        self::assertSame('idoklad', $import->presetKey());
    }

    /**
     * @return iterable<string, array{ImportMode, ?ReplaceScope, int, ?int, bool, bool}>
     */
    public static function confirmations(): iterable
    {
        yield 'add missing never archives' => [ImportMode::AddMissing, null, 3, null, false, true];
        yield 'update never archives' => [ImportMode::Update, null, 3, null, false, true];
        yield 'full replace with nothing to archive' => [ImportMode::FullReplace, ReplaceScope::All, 0, null, false, true];
        yield 'full replace without the number' => [ImportMode::FullReplace, ReplaceScope::All, 3, null, true, false];
        yield 'full replace with a smaller number' => [ImportMode::FullReplace, ReplaceScope::Profile, 3, 2, true, false];
        yield 'full replace with a larger number' => [ImportMode::FullReplace, ReplaceScope::Profile, 3, 4, true, false];
        yield 'full replace with the number' => [ImportMode::FullReplace, ReplaceScope::All, 3, 3, true, true];
    }

    #[DataProvider('confirmations')]
    public function testAFullReplaceThatArchivesNeedsTheTypedNumber(ImportMode $mode, ?ReplaceScope $scope, int $candidates, ?int $typed, bool $needed, bool $confirmed): void
    {
        $import = $this->newImport();
        $import->useMapping(new Mapping(), new ImportOptions($mode, $scope, 'code', BlankCells::Keep, DateOrder::DayMonthYear, DecimalSeparator::Comma, null), null);
        $import->findArchiveCandidates($candidates);

        $import->confirmArchive($typed);

        self::assertSame($needed, $import->needsArchiveConfirmation());
        self::assertSame($confirmed, $import->isArchiveConfirmed());
        self::assertSame($typed, $import->options()->archiveConfirmation);
    }

    public function testConfirmingKeepsTheOtherOptions(): void
    {
        $import = $this->newImport();
        $options = new ImportOptions(ImportMode::FullReplace, ReplaceScope::Profile, 'code', BlankCells::Clear, DateOrder::YearMonthDay, DecimalSeparator::Point, null);
        $import->useMapping(new Mapping(), $options, null);

        $import->confirmArchive(5);
        $import->confirmArchive(null);

        self::assertEquals($options, $import->options());
    }

    /**
     * @return iterable<string, array{int, list<array{int, int}>, bool}>
     */
    public static function thresholds(): iterable
    {
        yield 'one of three chunks done is too early' => [3, [[500, 500]], false];
        yield 'more than half after two chunks' => [3, [[500, 500], [500, 1]], true];
        yield 'exactly half after two chunks' => [3, [[500, 500], [500, 0]], false];
        yield 'only once, after the second chunk' => [3, [[500, 0], [500, 0], [500, 500]], false];
        yield 'a single chunk is judged when done' => [1, [[3, 2]], true];
        yield 'a single chunk with half of it failing' => [1, [[2, 1]], false];
    }

    /**
     * @param list<array{int, int}> $chunks rows and failed rows per chunk done
     */
    #[DataProvider('thresholds')]
    public function testTheErrorThresholdIsJudgedOnceAfterTheFirstChunks(int $total, array $chunks, bool $reached): void
    {
        $import = $this->newImport();
        $import->countRows(1500, $total);
        foreach ($chunks as [$rows, $failed]) {
            $import->recordChunk([RowOutcome::Create->value => $rows - $failed, RowOutcome::Error->value => $failed]);
        }

        self::assertSame($reached, $import->reachedErrorThreshold());
    }

    public function testRecountSetsTheExactNumbersAndKeepsTheProgress(): void
    {
        $import = $this->newImport();
        $import->countRows(9, 2);
        $import->recordChunk([RowOutcome::Create->value => 9]);

        $import->recount([
            RowOutcome::Create->value => 1, RowOutcome::Update->value => 2, RowOutcome::Restore->value => 3,
            RowOutcome::Skip->value => 4, RowOutcome::Archive->value => 5, RowOutcome::Error->value => 6,
            RowOutcome::Reverted->value => 7,
        ]);

        self::assertSame([1, 2, 3, 4, 5, 6, 21], [$import->created(), $import->updated(), $import->restored(), $import->skipped(), $import->archived(), $import->failed(), $import->processed()]);
        self::assertSame(1, $import->chunksDone());
    }

    public function testRecountRejectsAnUnknownOutcomeAndANegativeNumber(): void
    {
        $import = $this->newImport();
        foreach ([['made_up' => 1], [RowOutcome::Create->value => -1]] as $counts) {
            try {
                $import->recount($counts);
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $import->processed());
            }
        }
    }

    public function testArchivedRecordsAddUpWithoutTouchingTheProgress(): void
    {
        $import = $this->newImport();
        $import->countRows(1, 1);

        $import->recordArchived(2);
        $import->recordArchived(0);
        $import->recordArchived(3);

        self::assertSame(5, $import->archived());
        self::assertSame(0, $import->processed());
        self::assertSame(0, $import->chunksDone());
        $this->expectException(\InvalidArgumentException::class);
        $import->recordArchived(-1);
    }

    public function testRecordsTheRunCouldNotArchiveCountAsFailed(): void
    {
        $import = $this->newImport();
        $import->countRows(1, 1);

        $import->recordArchived(2, 1);
        $import->recordArchived(1, 0);

        self::assertSame([3, 1, 0], [$import->archived(), $import->failed(), $import->processed()]);
        $this->expectException(\InvalidArgumentException::class);
        $import->recordArchived(0, -1);
    }

    public function testRowsWithAnUnreadableKeyAddUpAndCountingStartsThemOver(): void
    {
        $import = $this->newImport();
        self::assertSame(0, $import->unidentified());

        $import->recordUnidentified(2);
        $import->recordUnidentified(0);
        $import->recordUnidentified(1);
        self::assertSame(3, $import->unidentified());

        $import->countRows(5, 1);
        self::assertSame(0, $import->unidentified());

        try {
            $import->recordUnidentified(-1);
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $import->unidentified());
        }
    }

    public function testAnAmbiguousMatchNamesItsRecord(): void
    {
        $import = $this->newImport();
        $match = new ImportAmbiguousMatch($import, 9);

        self::assertNull($match->id());
        self::assertSame($import, $match->import());
        self::assertSame(9, $match->recordId());
        $tenant = TenantId::generate();
        new \ReflectionProperty(ImportAmbiguousMatch::class, 'tenantId')->setValue($match, $tenant);
        self::assertSame($tenant, $match->tenantId());
    }

    public function testAnAmbiguousMatchNeedsItsTenantStamped(): void
    {
        $this->expectException(\LogicException::class);
        new ImportAmbiguousMatch($this->newImport(), 9)->tenantId();
    }

    public function testUndoCountsAddUp(): void
    {
        $import = $this->newImport();

        $import->recordUndo(2, 1);
        $import->recordUndo(0, 0);
        $import->recordUndo(3, 4);

        self::assertSame(5, $import->reverted());
        self::assertSame(5, $import->changedAfterImport());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function negativeUndoCounts(): iterable
    {
        yield 'reverted' => [-1, 0];
        yield 'changed after import' => [0, -1];
    }

    #[DataProvider('negativeUndoCounts')]
    public function testUndoCountsMustNotBeNegative(int $reverted, int $changed): void
    {
        $import = $this->newImport();

        try {
            $import->recordUndo($reverted, $changed);
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            self::assertSame([0, 0], [$import->reverted(), $import->changedAfterImport()]);
        }
    }

    /**
     * @return iterable<string, array{ImportState}>
     */
    public static function undoableStates(): iterable
    {
        yield 'finished' => [ImportState::Finished];
        yield 'finished with errors' => [ImportState::FinishedWithErrors];
        yield 'stopped' => [ImportState::Stopped];
    }

    #[DataProvider('undoableStates')]
    public function testAnEndedRunCanBeUndoneForThirtyDays(ImportState $state): void
    {
        $import = $this->ran($state, new \DateTimeImmutable('2026-10-01 10:00:00'));

        self::assertEquals(new \DateTimeImmutable('2026-10-31 10:00:00'), $import->undoDeadline());
        self::assertNull($import->undoRefusal(new \DateTimeImmutable('2026-10-01 10:00:00')));
        self::assertNull($import->undoRefusal(new \DateTimeImmutable('2026-10-31 10:00:00')));
        self::assertSame('import.refused.undo_expired', $import->undoRefusal(new \DateTimeImmutable('2026-10-31 10:00:01')));
    }

    public function testAnImportThatDidNotRunCannotBeUndone(): void
    {
        foreach ([ImportState::Checked, ImportState::Running, ImportState::Failed, ImportState::Undone] as $state) {
            $import = $this->ran($state, new \DateTimeImmutable('2026-10-01'));

            self::assertNull($import->undoDeadline());
            self::assertSame('import.refused.undo_state', $import->undoRefusal(new \DateTimeImmutable('2026-10-02')));
        }
    }

    public function testARunThatFailedAfterWritingCanBeUndoneWithinTheWindow(): void
    {
        $import = $this->ran(ImportState::Running, new \DateTimeImmutable('2026-10-01'));
        $import->recordChunk([RowOutcome::Create->value => 1]);
        $import->fail('import.failure.stalled', new \DateTimeImmutable('2026-10-01 10:00:00'));

        self::assertEquals(new \DateTimeImmutable('2026-10-31 10:00:00'), $import->undoDeadline());
        self::assertNull($import->undoRefusal(new \DateTimeImmutable('2026-10-02')));
        self::assertSame('import.refused.undo_expired', $import->undoRefusal(new \DateTimeImmutable('2026-10-31 10:00:01')));
    }

    public function testAnUndoThatFailedCanBeTriedAgain(): void
    {
        $import = $this->ran(ImportState::Running, new \DateTimeImmutable('2026-10-01'));
        $import->recordChunk([RowOutcome::Update->value => 2]);
        $import->moveTo(ImportState::Finished, new \DateTimeImmutable('2026-10-01'));
        $import->moveTo(ImportState::Undoing);
        $import->fail('import.failure.stalled', new \DateTimeImmutable('2026-10-02'));

        self::assertNull($import->undoRefusal(new \DateTimeImmutable('2026-10-03')));
        $import->moveTo(ImportState::Undoing);
        self::assertSame(ImportState::Undoing, $import->state());
    }

    public function testAFailedImportThatWroteNothingCannotBeUndone(): void
    {
        $read = $this->ran(ImportState::Checking, new \DateTimeImmutable('2026-10-01'));
        $read->recordChunk([RowOutcome::Create->value => 3]);
        $read->fail('import.failure.stalled', new \DateTimeImmutable('2026-10-01'));
        $started = $this->ran(ImportState::Running, new \DateTimeImmutable('2026-10-01'));
        $started->fail('import.failure.stalled', new \DateTimeImmutable('2026-10-01'));

        foreach ([$read, $started] as $import) {
            self::assertFalse($import->canMoveTo(ImportState::Undoing));
            self::assertSame('import.refused.undo_state', $import->undoRefusal(new \DateTimeImmutable('2026-10-02')));
        }
        $this->expectException(IllegalTransition::class);
        $started->moveTo(ImportState::Undoing);
    }

    public function testAPurgedImportCannotBeUndone(): void
    {
        $import = new Import('party', null, 'contacts.csv', self::SETTINGS, 7, new \DateTimeImmutable('2026-10-01'));
        foreach ([ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running] as $step) {
            $import->moveTo($step);
        }
        $import->moveTo(ImportState::Finished, new \DateTimeImmutable('2026-10-01'));

        self::assertSame('import.refused.undo_expired', $import->undoRefusal(new \DateTimeImmutable('2026-10-02')));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptyKeys(): iterable
    {
        yield 'null' => [null];
        yield 'empty text' => [''];
        yield 'spaces' => ['   '];
        yield 'empty list' => [[]];
        yield 'list of empties' => [[null, '', ' ']];
        yield 'an object' => [new \stdClass()];
    }

    #[DataProvider('emptyKeys')]
    public function testAnEmptyKeyHasNoFingerprint(mixed $value): void
    {
        self::assertNull(ImportRow::fingerprint($value));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function sameKeys(): iterable
    {
        yield 'letter case and spaces' => ['Example@Example.com ', 'example@example.com'];
        yield 'a number and its text' => [12345687, '12345687'];
        yield 'a list in another order' => [['b@example.com', 'A@example.com'], ['a@example.com', 'b@example.com', '']];
        yield 'a date and its day' => [new \DateTimeImmutable('2026-10-01 12:00:00'), '2026-10-01'];
        yield 'true and one' => [true, '1'];
        yield 'false and zero' => [false, '0'];
    }

    #[DataProvider('sameKeys')]
    public function testKeysNamingTheSameRecordShareTheFingerprint(mixed $a, mixed $b): void
    {
        self::assertSame(ImportRow::fingerprint($a), ImportRow::fingerprint($b));
    }

    public function testTheFingerprintIsTheHashOfTheCanonicalText(): void
    {
        self::assertSame(sha1('abc'), ImportRow::fingerprint(' ABC'));
        self::assertSame(sha1("a@example.com\nb@example.com"), ImportRow::fingerprint(['b@example.com', 'a@example.com']));
        self::assertNotSame(ImportRow::fingerprint('C1'), ImportRow::fingerprint('C2'));
        self::assertNotSame(ImportRow::fingerprint(['a', 'b']), ImportRow::fingerprint('a b'));
    }

    public function testARowIsKeyedByItsFingerprint(): void
    {
        $row = new ImportRow($this->newImport(), 2, 0, ['C1']);
        self::assertNull($row->matchValue());

        $row->keyBy(sha1('c1'));

        self::assertSame(sha1('c1'), $row->matchValue());
    }

    /**
     * @return iterable<string, array{ImportMode, int, bool, RowOutcome}>
     */
    public static function matchCounts(): iterable
    {
        foreach (ImportMode::cases() as $mode) {
            yield $mode->value.', no match' => [$mode, 0, false, RowOutcome::Create];
            yield $mode->value.', several matches' => [$mode, 2, false, RowOutcome::Error];
        }
        yield 'add missing, one match' => [ImportMode::AddMissing, 1, false, RowOutcome::Skip];
        yield 'add missing, one archived match' => [ImportMode::AddMissing, 1, true, RowOutcome::Skip];
        yield 'update, one match' => [ImportMode::Update, 1, false, RowOutcome::Update];
        yield 'update, one archived match' => [ImportMode::Update, 1, true, RowOutcome::Restore];
        yield 'full replace, one match' => [ImportMode::FullReplace, 1, false, RowOutcome::Update];
        yield 'full replace, one archived match' => [ImportMode::FullReplace, 1, true, RowOutcome::Restore];
    }

    #[DataProvider('matchCounts')]
    public function testTheMatchesDecideTheOutcome(ImportMode $mode, int $matches, bool $archived, RowOutcome $outcome): void
    {
        self::assertSame($outcome, RowOutcome::forMatches($mode, $matches, $archived));
    }

    public function testTheNumberOfMatchesMustNotBeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RowOutcome::forMatches(ImportMode::Update, -1, false);
    }

    public function testOnlyCreateUpdateAndRestoreWroteARecord(): void
    {
        $wrote = array_values(array_filter(RowOutcome::cases(), static fn (RowOutcome $outcome): bool => $outcome->wroteRecord()));

        self::assertSame([RowOutcome::Create, RowOutcome::Update, RowOutcome::Restore], $wrote);
    }

    public function testAMissingRecordIsArchivedKeptAndUndone(): void
    {
        $import = $this->newImport();
        $record = new ImportMissingRecord($import, 4, 17);

        self::assertNull($record->id());
        self::assertSame($import, $record->import());
        self::assertSame(4, $record->chunk());
        self::assertSame(17, $record->recordId());
        self::assertSame(RowOutcome::Archive, $record->outcome());
        self::assertNull($record->before());
        self::assertFalse($record->processedInRun());
        self::assertFalse($record->processedInUndo());

        $record->archived(['name' => 'Old']);
        self::assertSame(['name' => 'Old'], $record->before());
        self::assertSame(RowOutcome::Archive, $record->outcome());
        self::assertTrue($record->processedInRun());

        $record->undone(RowOutcome::Reverted);
        self::assertSame(RowOutcome::Reverted, $record->outcome());
        self::assertTrue($record->processedInUndo());

        $record->undone(RowOutcome::ChangedAfterImport);
        self::assertSame(RowOutcome::ChangedAfterImport, $record->outcome());
    }

    public function testAMissingRecordTheRunFoundIsKept(): void
    {
        $record = new ImportMissingRecord($this->newImport(), 1, 3);

        $record->kept();
        self::assertSame(RowOutcome::Skip, $record->outcome());
        self::assertTrue($record->processedInRun());
        self::assertNull($record->before());

        $record->passedByUndo();
        self::assertTrue($record->processedInUndo());
        self::assertSame(RowOutcome::Skip, $record->outcome());
    }

    public function testAMissingRecordTheTargetRefusedFailsWithItsMessage(): void
    {
        $record = new ImportMissingRecord($this->newImport(), 1, 3);
        self::assertNull($record->errorKey());
        $record->archived(['name' => 'Old']);
        self::assertNull($record->errorKey());

        $record->failed();

        self::assertSame(RowOutcome::Error, $record->outcome());
        self::assertSame('import.error.archive_failed', $record->errorKey());
        self::assertNull($record->before());
        self::assertTrue($record->processedInRun());
    }

    public function testAnUndoOnlyRevertsOrKeepsAChangedRecord(): void
    {
        $record = new ImportMissingRecord($this->newImport(), 1, 3);

        try {
            $record->undone(RowOutcome::Create);
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            self::assertFalse($record->processedInUndo());
            self::assertSame(RowOutcome::Archive, $record->outcome());
        }
    }

    public function testAMissingRecordKeepsTheStampedTenant(): void
    {
        $record = new ImportMissingRecord($this->newImport(), 1, 3);
        $tenant = TenantId::generate();
        new \ReflectionProperty(ImportMissingRecord::class, 'tenantId')->setValue($record, $tenant);

        self::assertSame($tenant, $record->tenantId());
    }

    public function testAMissingRecordNeedsItsTenantStamped(): void
    {
        $this->expectException(\LogicException::class);
        new ImportMissingRecord($this->newImport(), 1, 3)->tenantId();
    }

    public function testANamedProfileRemembersNothingUntilARunSucceeds(): void
    {
        $options = ImportOptions::standard();
        $at = new \DateTimeImmutable('2026-10-01');

        $profile = ImportProfile::named('Contacts from iDoklad', 'party', 'idoklad', $options, $at);

        self::assertFalse($profile->isRemembered());
        self::assertSame(ImportProfile::NOT_REMEMBERED, $profile->headerSignature());
        self::assertSame([], $profile->mapping()->columns);
        self::assertSame('Contacts from iDoklad', $profile->name());
        self::assertSame('party', $profile->targetKey());
        self::assertSame('idoklad', $profile->presetKey());
        self::assertSame($options, $profile->options());
        self::assertSame($at, $profile->createdAt());

        $profile->remember(new Mapping([0 => 'name']), $options, Mapping::signature(['Name']), new \DateTimeImmutable('2026-10-02'));

        self::assertTrue($profile->isRemembered());
    }

    private function newImport(): Import
    {
        return new Import('party', 'tenant/file', 'contacts.csv', self::SETTINGS, 7, new \DateTimeImmutable('2026-10-01'));
    }

    private function ran(ImportState $state, \DateTimeImmutable $at): Import
    {
        $import = $this->newImport();
        $steps = [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running];
        foreach ($steps as $step) {
            if ($import->state() === $state) {
                return $import;
            }
            $import->moveTo($step);
        }
        if ($import->state() === $state) {
            return $import;
        }
        if (ImportState::Undone === $state) {
            $import->moveTo(ImportState::Finished, $at);
            $import->moveTo(ImportState::Undoing);
        }
        $import->moveTo($state, $at);

        return $import;
    }
}
