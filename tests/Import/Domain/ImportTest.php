<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Domain;

use App\Import\Domain\BlankCells;
use App\Import\Domain\DateOrder;
use App\Import\Domain\DecimalSeparator;
use App\Import\Domain\IllegalTransition;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\ImportProfile;
use App\Import\Domain\ImportRow;
use App\Import\Domain\ImportState;
use App\Import\Domain\Mapping;
use App\Import\Domain\ReplaceScope;
use App\Import\Domain\RowError;
use App\Import\Domain\RowOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Import::class)]
#[CoversClass(ImportState::class)]
#[CoversClass(IllegalTransition::class)]
#[CoversClass(ImportOptions::class)]
#[CoversClass(ImportProfile::class)]
#[CoversClass(ImportRow::class)]
#[CoversClass(RowOutcome::class)]
final class ImportTest extends TestCase
{
    private const array SETTINGS = ['format' => 'csv', 'encoding' => 'utf-8', 'delimiter' => ';', 'sheet' => null, 'headerRow' => 1];

    /**
     * @return iterable<string, array{ImportState, ImportState}>
     */
    public static function allowed(): iterable
    {
        $pairs = [
            [ImportState::Uploaded, ImportState::Reading],
            [ImportState::Reading, ImportState::Mapping],
            [ImportState::Mapping, ImportState::Reading],
            [ImportState::Mapping, ImportState::Checking],
            [ImportState::Checking, ImportState::Checked],
            [ImportState::Checked, ImportState::Mapping],
            [ImportState::Checked, ImportState::Queued],
            [ImportState::Queued, ImportState::Running],
            [ImportState::Running, ImportState::Finished],
            [ImportState::Running, ImportState::FinishedWithErrors],
            [ImportState::Running, ImportState::Failed],
            [ImportState::Running, ImportState::Stopped],
            [ImportState::Reading, ImportState::Failed],
            [ImportState::Checking, ImportState::Failed],
            [ImportState::Undoing, ImportState::Failed],
            [ImportState::Finished, ImportState::Undoing],
            [ImportState::FinishedWithErrors, ImportState::Undoing],
            [ImportState::Stopped, ImportState::Undoing],
            [ImportState::Undoing, ImportState::Undone],
        ];
        foreach ([ImportState::Uploaded, ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running] as $state) {
            $pairs[] = [$state, ImportState::Cancelled];
        }
        foreach ($pairs as [$from, $to]) {
            yield $from->value.' to '.$to->value => [$from, $to];
        }
    }

    /**
     * @return iterable<string, array{ImportState, ImportState}>
     */
    public static function forbidden(): iterable
    {
        $allowed = [];
        foreach (self::allowed() as [$from, $to]) {
            $allowed[$from->value.'>'.$to->value] = true;
        }
        foreach (ImportState::cases() as $from) {
            foreach (ImportState::cases() as $to) {
                if (!isset($allowed[$from->value.'>'.$to->value])) {
                    yield $from->value.' to '.$to->value => [$from, $to];
                }
            }
        }
    }

    #[DataProvider('allowed')]
    public function testAllowedTransitionsPass(ImportState $from, ImportState $to): void
    {
        $import = $this->importIn($from);

        $import->moveTo($to);

        self::assertSame($to, $import->state());
    }

    #[DataProvider('forbidden')]
    public function testForbiddenTransitionsThrow(ImportState $from, ImportState $to): void
    {
        $import = $this->importIn($from);

        $this->expectException(IllegalTransition::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('from "%s" to "%s"', $from->value, $to->value));
        $import->moveTo($to);
    }

    public function testAForbiddenTransitionLeavesTheStateAlone(): void
    {
        $import = $this->newImport();

        try {
            $import->moveTo(ImportState::Running);
            self::fail('Expected IllegalTransition.');
        } catch (IllegalTransition) {
            self::assertSame(ImportState::Uploaded, $import->state());
        }
    }

    public function testANewImportStartsUploadedWithZeroCounters(): void
    {
        $import = $this->newImport();

        self::assertSame(ImportState::Uploaded, $import->state());
        self::assertSame('party', $import->targetKey());
        self::assertSame('tenant/file', $import->fileKey());
        self::assertSame('contacts.csv', $import->originalName());
        self::assertSame(self::SETTINGS, $import->sourceSettings());
        self::assertSame(7, $import->actorUserId());
        self::assertSame([], $import->headers());
        self::assertSame([], $import->mapping()->columns);
        self::assertNull($import->profile());
        self::assertNull($import->startedAt());
        self::assertNull($import->finishedAt());
        self::assertNull($import->seenAt());
        self::assertNull($import->failureMessageKey());
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], [
            $import->total(), $import->processed(), $import->created(), $import->updated(), $import->restored(),
            $import->skipped(), $import->archived(), $import->failed(), $import->chunks(), $import->chunksDone(), $import->archiveCandidates(),
        ]);
        self::assertEquals(ImportOptions::standard(), $import->options());
    }

    public function testRunningAndFinishingStampTheTimes(): void
    {
        $import = $this->importIn(ImportState::Queued);
        $start = new \DateTimeImmutable('2026-10-01 10:00:00');
        $end = new \DateTimeImmutable('2026-10-01 10:05:00');

        $import->moveTo(ImportState::Running, $start);
        self::assertSame($start, $import->startedAt());
        self::assertNull($import->finishedAt());

        $import->moveTo(ImportState::Finished, $end);
        self::assertSame($end, $import->finishedAt());
        self::assertSame($start, $import->startedAt());
    }

    #[DataProvider('terminalStates')]
    public function testEveryEndingStateStampsFinishedAt(ImportState $from, ImportState $to): void
    {
        $import = $this->importIn($from);
        $at = new \DateTimeImmutable('2026-10-01 10:00:00');

        $import->moveTo($to, $at);

        self::assertSame($at, $import->finishedAt());
    }

    /**
     * @return iterable<string, array{ImportState, ImportState}>
     */
    public static function terminalStates(): iterable
    {
        yield 'finished' => [ImportState::Running, ImportState::Finished];
        yield 'finished with errors' => [ImportState::Running, ImportState::FinishedWithErrors];
        yield 'failed' => [ImportState::Running, ImportState::Failed];
        yield 'stopped' => [ImportState::Running, ImportState::Stopped];
        yield 'cancelled' => [ImportState::Checked, ImportState::Cancelled];
        yield 'undone' => [ImportState::Undoing, ImportState::Undone];
    }

    public function testAStoppedImportCanBeUndone(): void
    {
        $import = $this->importIn(ImportState::Stopped);

        $import->moveTo(ImportState::Undoing);
        $import->moveTo(ImportState::Undone, new \DateTimeImmutable('2026-10-02'));

        self::assertSame(ImportState::Undone, $import->state());
    }

    public function testOnlyIdleStatesAreAbandonable(): void
    {
        $abandonable = array_filter(ImportState::cases(), static fn (ImportState $state): bool => $state->isAbandonable());

        self::assertSame(
            [ImportState::Uploaded, ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked],
            array_values($abandonable),
        );
    }

    public function testMiddleStatesDoNotStampFinishedAt(): void
    {
        $import = $this->importIn(ImportState::Uploaded);

        $import->moveTo(ImportState::Reading);

        self::assertNull($import->finishedAt());
    }

    public function testUndoingClearsFinishedAtSoPurgeLeavesItAlone(): void
    {
        $import = $this->importIn(ImportState::Running);
        $import->moveTo(ImportState::Finished, new \DateTimeImmutable('2026-10-01'));

        $import->moveTo(ImportState::Undoing);

        self::assertNull($import->finishedAt());
    }

    public function testFailRecordsTheMessageKey(): void
    {
        $import = $this->importIn(ImportState::Running);

        $import->fail('import.failure.unreadable', new \DateTimeImmutable('2026-10-01'));

        self::assertSame(ImportState::Failed, $import->state());
        self::assertSame('import.failure.unreadable', $import->failureMessageKey());
        self::assertEquals(new \DateTimeImmutable('2026-10-01'), $import->finishedAt());
    }

    public function testFailFromAnIllegalStateThrowsAndKeepsNoMessage(): void
    {
        $import = $this->newImport();

        try {
            $import->fail('import.failure.unreadable');
            self::fail('Expected IllegalTransition.');
        } catch (IllegalTransition) {
            self::assertNull($import->failureMessageKey());
        }
    }

    public function testCountersAddUpOverChunks(): void
    {
        $import = $this->importIn(ImportState::Running);
        $import->countRows(10, 2);

        $import->recordChunk([
            RowOutcome::Create->value => 3,
            RowOutcome::Update->value => 2,
            RowOutcome::Restore->value => 1,
        ]);
        $import->recordChunk([
            RowOutcome::Skip->value => 1,
            RowOutcome::Archive->value => 2,
            RowOutcome::Error->value => 1,
        ]);

        self::assertSame(10, $import->total());
        self::assertSame(10, $import->processed());
        self::assertSame(3, $import->created());
        self::assertSame(2, $import->updated());
        self::assertSame(1, $import->restored());
        self::assertSame(1, $import->skipped());
        self::assertSame(2, $import->archived());
        self::assertSame(1, $import->failed());
        self::assertSame(2, $import->chunks());
        self::assertSame(2, $import->chunksDone());
    }

    public function testAnEmptyChunkStillCountsAsDone(): void
    {
        $import = $this->importIn(ImportState::Running);
        $import->countRows(0, 1);

        $import->recordChunk([]);

        self::assertSame(1, $import->chunksDone());
        self::assertSame(0, $import->processed());
    }

    public function testUndoOutcomesAreNotImportCounters(): void
    {
        $import = $this->importIn(ImportState::Running);

        $import->recordChunk([RowOutcome::Reverted->value => 4, RowOutcome::ChangedAfterImport->value => 1, RowOutcome::Create->value => 2]);

        self::assertSame(2, $import->processed());
        self::assertSame(2, $import->created());
    }

    public function testRecordChunkRejectsAnUnknownOutcome(): void
    {
        $import = $this->importIn(ImportState::Running);

        $this->expectException(\InvalidArgumentException::class);
        $import->recordChunk(['explode' => 1]);
    }

    public function testRecordChunkRejectsANegativeCount(): void
    {
        $import = $this->importIn(ImportState::Running);

        $this->expectException(\InvalidArgumentException::class);
        $import->recordChunk([RowOutcome::Create->value => -1]);
    }

    public function testRecordChunkChangesNothingWhenOneEntryIsInvalid(): void
    {
        $import = $this->importIn(ImportState::Running);

        try {
            $import->recordChunk([RowOutcome::Create->value => 2, 'explode' => 1]);
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $import->created());
            self::assertSame(0, $import->chunksDone());
        }
    }

    public function testCountingRowsAgainResetsTheProgress(): void
    {
        $import = $this->importIn(ImportState::Running);
        $import->countRows(5, 1);
        $import->recordChunk([RowOutcome::Create->value => 5]);

        $import->countRows(8, 2);

        self::assertSame([8, 2, 0, 0, 0], [$import->total(), $import->chunks(), $import->processed(), $import->created(), $import->chunksDone()]);
    }

    public function testCountRowsRejectsNegativeNumbers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->newImport()->countRows(-1, 0);
    }

    public function testMappingIsStoredAsPlainDataAndReadBack(): void
    {
        $import = $this->newImport();
        $profile = new ImportProfile('Contacts', 'party', null, new Mapping(), ImportOptions::standard(), 'abc', new \DateTimeImmutable('2026-10-01'));
        $mapping = new Mapping([0 => 'name', 1 => null], ['kind' => ['Firma' => 'company']]);
        $options = new ImportOptions(ImportMode::Update, null, 'registration_number', BlankCells::Clear, DateOrder::YearMonthDay, DecimalSeparator::Point, null);

        $import->useMapping($mapping, $options, $profile);
        $import->readHeaders(['Name', 'Kind']);

        self::assertEquals($mapping, $import->mapping());
        self::assertSame($options, $import->options());
        self::assertSame($profile, $import->profile());
        self::assertSame(['Name', 'Kind'], $import->headers());
    }

    public function testArchiveCandidatesAndSeen(): void
    {
        $import = $this->newImport();
        $at = new \DateTimeImmutable('2026-10-02');

        $import->findArchiveCandidates(12);
        $import->markSeen($at);

        self::assertSame(12, $import->archiveCandidates());
        self::assertSame($at, $import->seenAt());
    }

    public function testArchiveCandidatesMustNotBeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->newImport()->findArchiveCandidates(-1);
    }

    public function testSourceSettingsCanBeReplacedByTheResolvedOnes(): void
    {
        $import = $this->newImport();
        $resolved = ['format' => 'csv', 'encoding' => 'windows-1250', 'delimiter' => ',', 'sheet' => null, 'headerRow' => 2];

        $import->useSourceSettings($resolved);

        self::assertSame($resolved, $import->sourceSettings());
    }

    public function testAFullReplaceNeedsAScope(): void
    {
        $this->expectException(\DomainException::class);
        new ImportOptions(ImportMode::FullReplace, null, 'name', BlankCells::Keep, DateOrder::DayMonthYear, DecimalSeparator::Comma, null);
    }

    public function testOnlyAFullReplaceHasAScope(): void
    {
        $this->expectException(\DomainException::class);
        new ImportOptions(ImportMode::Update, ReplaceScope::All, 'name', BlankCells::Keep, DateOrder::DayMonthYear, DecimalSeparator::Comma, null);
    }

    public function testTheArchiveConfirmationMustBePositive(): void
    {
        $this->expectException(\DomainException::class);
        new ImportOptions(ImportMode::FullReplace, ReplaceScope::Profile, 'name', BlankCells::Keep, DateOrder::DayMonthYear, DecimalSeparator::Comma, 0);
    }

    public function testAFullReplaceKeepsItsScopeAndConfirmation(): void
    {
        $options = new ImportOptions(ImportMode::FullReplace, ReplaceScope::All, 'name', BlankCells::Keep, DateOrder::DayMonthYear, DecimalSeparator::Comma, 5);

        self::assertSame(ReplaceScope::All, $options->scope);
        self::assertSame(5, $options->archiveConfirmation);
    }

    public function testTheProfileRemembersMappingOptionsAndSignature(): void
    {
        $profile = new ImportProfile('Contacts', 'party', 'idoklad', new Mapping([0 => 'name']), ImportOptions::standard(), 'sig', new \DateTimeImmutable('2026-10-01'));
        $later = new \DateTimeImmutable('2026-10-05');
        $options = new ImportOptions(ImportMode::Update, null, 'name', BlankCells::Clear, DateOrder::MonthDayYear, DecimalSeparator::Point, null);

        $profile->remember(new Mapping([0 => 'email']), $options, 'sig2', $later);

        self::assertSame('Contacts', $profile->name());
        self::assertSame('party', $profile->targetKey());
        self::assertSame('idoklad', $profile->presetKey());
        self::assertSame([0 => 'email'], $profile->mapping()->columns);
        self::assertSame($options, $profile->options());
        self::assertSame('sig2', $profile->headerSignature());
        self::assertSame($later, $profile->updatedAt());
        self::assertNull($profile->id());
    }

    public function testARowKeepsItsErrorsAndOutcome(): void
    {
        $import = $this->newImport();
        $row = new ImportRow($import, 3, 0, ['Example Company', '12345687']);

        self::assertNull($row->outcome());
        self::assertSame([], $row->errors());
        self::assertFalse($row->processedInRun());
        self::assertFalse($row->processedInUndo());

        $row->decide(RowOutcome::Error, [new RowError('email', 'import.error.invalid_email', ['value' => 'x'])]);
        $row->record(42, ['name' => 'Old name']);
        $row->markProcessed();
        $row->markUndone();

        self::assertSame($import, $row->import());
        self::assertSame(3, $row->rowNumber());
        self::assertSame(0, $row->chunk());
        self::assertSame(['Example Company', '12345687'], $row->raw());
        self::assertSame(RowOutcome::Error, $row->outcome());
        self::assertEquals([new RowError('email', 'import.error.invalid_email', ['value' => 'x'])], $row->errors());
        self::assertSame(42, $row->recordId());
        self::assertSame(['name' => 'Old name'], $row->before());
        self::assertTrue($row->processedInRun());
        self::assertTrue($row->processedInUndo());
    }

    public function testARowNumberMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImportRow($this->newImport(), 0, 0, []);
    }

    private function newImport(): Import
    {
        return new Import('party', 'tenant/file', 'contacts.csv', self::SETTINGS, 7, new \DateTimeImmutable('2026-10-01'));
    }

    private function importIn(ImportState $state): Import
    {
        $import = $this->newImport();
        $paths = [
            ImportState::Uploaded->value => [],
            ImportState::Reading->value => [ImportState::Reading],
            ImportState::Mapping->value => [ImportState::Reading, ImportState::Mapping],
            ImportState::Checking->value => [ImportState::Reading, ImportState::Mapping, ImportState::Checking],
            ImportState::Checked->value => [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked],
            ImportState::Queued->value => [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued],
            ImportState::Running->value => [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running],
            ImportState::Finished->value => [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running, ImportState::Finished],
            ImportState::FinishedWithErrors->value => [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running, ImportState::FinishedWithErrors],
            ImportState::Failed->value => [ImportState::Reading, ImportState::Failed],
            ImportState::Stopped->value => [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running, ImportState::Stopped],
            ImportState::Cancelled->value => [ImportState::Cancelled],
            ImportState::Undoing->value => [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running, ImportState::Finished, ImportState::Undoing],
            ImportState::Undone->value => [ImportState::Reading, ImportState::Mapping, ImportState::Checking, ImportState::Checked, ImportState::Queued, ImportState::Running, ImportState::Finished, ImportState::Undoing, ImportState::Undone],
        ];
        foreach ($paths[$state->value] as $step) {
            $import->moveTo($step);
        }
        self::assertSame($state, $import->state());

        return $import;
    }
}
