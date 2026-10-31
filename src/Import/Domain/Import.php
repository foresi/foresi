<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * One load of a source file into a target: the file, how it is read and mapped,
 * the options chosen, the progress and the counters. The staged rows are
 * ImportRow entities; the file and rows are purged after a while, the Import stays.
 *
 * The source settings and the mapping are kept as plain data (the Application
 * classes SourceSettings and, for the mapping, Mapping::toArray()), so the Domain
 * does not depend on the Application.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import')]
#[ORM\Index(name: 'idx_import_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_import_finished', columns: ['state', 'finished_at'])]
class Import implements TenantOwned
{
    /** Undo stays possible this long after the run ended; the purge removes the staged rows at the same age. */
    public const int UNDO_DAYS = 30;

    /** The share of error rows is judged once this many chunks are done (or all of them, when there are fewer). */
    public const int THRESHOLD_CHUNKS = 2;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\ManyToOne(targetEntity: ImportProfile::class)]
    #[ORM\JoinColumn(name: 'profile_id', nullable: true, onDelete: 'SET NULL')]
    private ?ImportProfile $profile = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $headers = [];

    /** @var array<string, mixed> the output of Mapping::toArray() */
    #[ORM\Column(type: 'json')]
    private array $mapping;

    #[ORM\Embedded(class: ImportOptions::class, columnPrefix: 'option_')]
    private ImportOptions $options;

    #[ORM\Column(type: 'string', length: 32, enumType: ImportState::class)]
    private ImportState $state = ImportState::Uploaded;

    #[ORM\Column(type: 'integer')]
    private int $total = 0;

    #[ORM\Column(type: 'integer')]
    private int $processed = 0;

    #[ORM\Column(type: 'integer')]
    private int $created = 0;

    #[ORM\Column(type: 'integer')]
    private int $updated = 0;

    #[ORM\Column(type: 'integer')]
    private int $restored = 0;

    #[ORM\Column(type: 'integer')]
    private int $skipped = 0;

    #[ORM\Column(type: 'integer')]
    private int $archived = 0;

    #[ORM\Column(type: 'integer')]
    private int $failed = 0;

    #[ORM\Column(type: 'integer')]
    private int $chunks = 0;

    #[ORM\Column(name: 'chunks_done', type: 'integer')]
    private int $chunksDone = 0;

    #[ORM\Column(name: 'archive_candidates', type: 'integer')]
    private int $archiveCandidates = 0;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** The last time the import moved: a state change or counters from a chunk. The health check fails an import that has not moved for too long. */
    #[ORM\Column(name: 'progressed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $progressedAt;

    /** When the actor opened the summary of the finished import. */
    #[ORM\Column(name: 'seen_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $seenAt = null;

    #[ORM\Column(name: 'failure_message_key', type: 'string', length: 128, nullable: true)]
    private ?string $failureMessageKey = null;

    /** @var list<array{column: int, field: string|null, reason: string}> what the column matcher proposed */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $proposals = [];

    /** The known source the headers were recognised as. */
    #[ORM\Column(name: 'preset_key', type: 'string', length: 64, nullable: true)]
    private ?string $presetKey = null;

    /** Rows whose match key cell could not be read: they identify no record, so a full replace counts its archive without them. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $unidentified = 0;

    /** Records the undo put back as they were. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $reverted = 0;

    /** Records the undo left alone because someone changed them after the import. */
    #[ORM\Column(name: 'changed_after_import', type: 'integer', options: ['default' => 0])]
    private int $changedAfterImport = 0;

    /**
     * Not readonly: DoctrineImports::findLocked() refreshes the entity, and the ORM cannot rewrite readonly properties.
     *
     * @param array<string, mixed> $sourceSettings the output of SourceSettings::toArray()
     */
    public function __construct(
        #[ORM\Column(name: 'target_key', type: 'string', length: 64)]
        private string $targetKey,
        // Null once the purge removed the file.
        #[ORM\Column(name: 'file_key', type: 'string', length: 100, nullable: true)]
        private ?string $fileKey,
        #[ORM\Column(name: 'original_name', type: 'string', length: 255)]
        private string $originalName,
        #[ORM\Column(name: 'source_settings', type: 'json')]
        private array $sourceSettings,
        #[ORM\Column(name: 'actor_user_id', type: 'guid')]
        private string $actorUserId,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->mapping = new Mapping()->toArray();
        $this->options = ImportOptions::standard();
        $this->progressedAt = $createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    public function profile(): ?ImportProfile
    {
        return $this->profile;
    }

    public function targetKey(): string
    {
        return $this->targetKey;
    }

    public function fileKey(): ?string
    {
        return $this->fileKey;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    /**
     * @return array<string, mixed>
     */
    public function sourceSettings(): array
    {
        return $this->sourceSettings;
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function mapping(): Mapping
    {
        return Mapping::fromArray($this->mapping);
    }

    public function options(): ImportOptions
    {
        return $this->options;
    }

    public function state(): ImportState
    {
        return $this->state;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function processed(): int
    {
        return $this->processed;
    }

    public function created(): int
    {
        return $this->created;
    }

    public function updated(): int
    {
        return $this->updated;
    }

    public function restored(): int
    {
        return $this->restored;
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    public function archived(): int
    {
        return $this->archived;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function chunks(): int
    {
        return $this->chunks;
    }

    public function chunksDone(): int
    {
        return $this->chunksDone;
    }

    public function archiveCandidates(): int
    {
        return $this->archiveCandidates;
    }

    public function actorUserId(): string
    {
        return $this->actorUserId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** The last time the import changed state or counted a chunk; the creation time until then. */
    public function progressedAt(): \DateTimeImmutable
    {
        return $this->progressedAt ?? $this->startedAt ?? $this->createdAt;
    }

    public function seenAt(): ?\DateTimeImmutable
    {
        return $this->seenAt;
    }

    public function failureMessageKey(): ?string
    {
        return $this->failureMessageKey;
    }

    /**
     * @return list<ColumnProposal> one per header, in column order
     */
    public function proposals(): array
    {
        return array_map(
            static fn (array $proposal): ColumnProposal => new ColumnProposal($proposal['column'], $proposal['field'], MatchReason::from($proposal['reason'])),
            $this->proposals,
        );
    }

    public function presetKey(): ?string
    {
        return $this->presetKey;
    }

    public function unidentified(): int
    {
        return $this->unidentified;
    }

    public function reverted(): int
    {
        return $this->reverted;
    }

    public function changedAfterImport(): int
    {
        return $this->changedAfterImport;
    }

    /**
     * The transitions of ImportState, except that a failed import goes back to
     * Undoing only when it wrote rows: a run that failed after it started, or an
     * undo that failed and is tried again. A file that could not be read or
     * checked wrote nothing, so there is nothing to undo.
     */
    public function canMoveTo(ImportState $next): bool
    {
        if (!$this->state->canMoveTo($next)) {
            return false;
        }

        return ImportState::Failed !== $this->state || ImportState::Undoing !== $next || ($this->startedAt instanceof \DateTimeImmutable && $this->processed + $this->archived > 0);
    }

    /**
     * @throws IllegalTransition
     */
    public function moveTo(ImportState $next, ?\DateTimeImmutable $at = null): void
    {
        if (!$this->canMoveTo($next)) {
            throw IllegalTransition::between($this->state, $next);
        }
        $at ??= new \DateTimeImmutable();
        $this->state = $next;
        $this->progressedAt = $at;
        if (ImportState::Running === $next) {
            $this->startedAt = $at;
        }
        // An undo in progress is not finished, so the purge leaves its rows alone.
        if (ImportState::Undoing === $next) {
            $this->finishedAt = null;
        }
        if ($next->isEnd()) {
            $this->finishedAt = $at;
        }
    }

    /**
     * @param string $messageKey translation key of what went wrong
     *
     * @throws IllegalTransition
     */
    public function fail(string $messageKey, ?\DateTimeImmutable $at = null): void
    {
        $this->moveTo(ImportState::Failed, $at);
        $this->failureMessageKey = $messageKey;
    }

    /**
     * @param array<string, mixed> $sourceSettings the resolved output of SourceSettings::toArray()
     */
    public function useSourceSettings(array $sourceSettings): void
    {
        $this->sourceSettings = $sourceSettings;
    }

    /**
     * @param list<string> $headers
     */
    public function readHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    public function useMapping(Mapping $mapping, ImportOptions $options, ?ImportProfile $profile): void
    {
        $this->mapping = $mapping->toArray();
        $this->options = $options;
        $this->profile = $profile;
    }

    /** Sets the size of the staged source and starts the progress over. */
    public function countRows(int $total, int $chunks): void
    {
        if ($total < 0 || $chunks < 0) {
            throw new \InvalidArgumentException('The number of rows and chunks must not be negative.');
        }
        $this->progressedAt = new \DateTimeImmutable();
        $this->total = $total;
        $this->chunks = $chunks;
        $this->chunksDone = 0;
        $this->processed = 0;
        $this->created = 0;
        $this->updated = 0;
        $this->restored = 0;
        $this->skipped = 0;
        $this->archived = 0;
        $this->failed = 0;
        $this->unidentified = 0;
    }

    public function findArchiveCandidates(int $count): void
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('The number of archive candidates must not be negative.');
        }
        $this->archiveCandidates = $count;
    }

    /**
     * Keeps what the column matcher proposed and the known source it recognised, for the mapping screen.
     *
     * @param list<ColumnProposal> $proposals
     */
    public function propose(array $proposals, ?string $presetKey): void
    {
        $this->proposals = array_map(
            static fn (ColumnProposal $proposal): array => ['column' => $proposal->column, 'field' => $proposal->fieldKey, 'reason' => $proposal->reason->value],
            $proposals,
        );
        $this->presetKey = $presetKey;
    }

    /**
     * The number of records the user typed to confirm the archiving of a full replace; null withdraws it.
     */
    public function confirmArchive(?int $count): void
    {
        $o = $this->options;
        $this->options = new ImportOptions($o->mode, $o->scope, $o->matchKey, $o->blankCells, $o->dateOrder, $o->decimalSeparator, $count);
    }

    /** A full replace that would archive records runs only once the user typed their number. */
    public function needsArchiveConfirmation(): bool
    {
        return ImportMode::FullReplace === $this->options->mode && $this->archiveCandidates > 0;
    }

    public function isArchiveConfirmed(): bool
    {
        return !$this->needsArchiveConfirmation() || $this->options->archiveConfirmation === $this->archiveCandidates;
    }

    /**
     * True on the chunk that completes the first THRESHOLD_CHUNKS (or all chunks,
     * when there are fewer) if more than half of the rows done so far failed:
     * the mapping is then most likely wrong.
     */
    public function reachedErrorThreshold(): bool
    {
        return $this->chunksDone === min(self::THRESHOLD_CHUNKS, $this->chunks) && $this->failed * 2 > $this->processed;
    }

    /**
     * The last moment the import can still be undone, or null when its state cannot be undone at all.
     */
    public function undoDeadline(): ?\DateTimeImmutable
    {
        if (!$this->canMoveTo(ImportState::Undoing) || !$this->finishedAt instanceof \DateTimeImmutable) {
            return null;
        }

        return $this->finishedAt->modify(\sprintf('+%d days', self::UNDO_DAYS));
    }

    /**
     * @return string|null why the import cannot be undone now, as a translation key; null when it can
     */
    public function undoRefusal(\DateTimeImmutable $now): ?string
    {
        $deadline = $this->undoDeadline();
        if (!$deadline instanceof \DateTimeImmutable) {
            return 'import.refused.undo_state';
        }
        // The purge removes the file and the staged rows together; without the rows nothing can be put back.
        if (null === $this->fileKey || $now > $deadline) {
            return 'import.refused.undo_expired';
        }

        return null;
    }

    public function markSeen(\DateTimeImmutable $at): void
    {
        $this->seenAt = $at;
    }

    /**
     * Adds the outcomes of one finished chunk to the counters. Call it while the
     * row is locked (SELECT ... FOR UPDATE on PostgreSQL), so parallel chunks add up.
     *
     * @param array<string, int> $counts the number of rows per outcome, keyed by RowOutcome::value
     */
    public function recordChunk(array $counts): void
    {
        $counts = $this->outcomeCounts($counts);
        $this->progressedAt = new \DateTimeImmutable();
        $this->created += $counts[0];
        $this->updated += $counts[1];
        $this->restored += $counts[2];
        $this->skipped += $counts[3];
        $this->archived += $counts[4];
        $this->failed += $counts[5];
        $this->processed += array_sum($counts);
        ++$this->chunksDone;
    }

    /**
     * Replaces the counters with the exact numbers of the whole source, as the
     * check counts them once every chunk is done. The chunk progress stays.
     *
     * @param array<string, int> $counts the number of rows per outcome, keyed by RowOutcome::value
     */
    public function recount(array $counts): void
    {
        [$this->created, $this->updated, $this->restored, $this->skipped, $this->archived, $this->failed] = $this->outcomeCounts($counts);
        $this->progressedAt = new \DateTimeImmutable();
        $this->processed = $this->created + $this->updated + $this->restored + $this->skipped + $this->archived + $this->failed;
    }

    /**
     * Adds records a full replace archived, and those it failed to archive (they
     * count as failed, so the run ends with errors). They are not source rows, so
     * the progress stays.
     */
    public function recordArchived(int $archived, int $failed = 0): void
    {
        if ($archived < 0 || $failed < 0) {
            throw new \InvalidArgumentException('The number of records must not be negative.');
        }
        $this->progressedAt = new \DateTimeImmutable();
        $this->archived += $archived;
        $this->failed += $failed;
    }

    /** Adds rows of a checked chunk whose match key cell could not be read. */
    public function recordUnidentified(int $rows): void
    {
        if ($rows < 0) {
            throw new \InvalidArgumentException('The number of rows must not be negative.');
        }
        $this->unidentified += $rows;
    }

    /** Adds the outcome of one chunk of an undo. */
    public function recordUndo(int $reverted, int $changedAfterImport): void
    {
        if ($reverted < 0 || $changedAfterImport < 0) {
            throw new \InvalidArgumentException('The number of records must not be negative.');
        }
        $this->progressedAt = new \DateTimeImmutable();
        $this->reverted += $reverted;
        $this->changedAfterImport += $changedAfterImport;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array{int, int, int, int, int, int} created, updated, restored, skipped, archived, failed
     */
    private function outcomeCounts(array $counts): array
    {
        $unknown = array_diff(array_keys($counts), array_map(static fn (RowOutcome $outcome): string => $outcome->value, RowOutcome::cases()));
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a row outcome.', reset($unknown)));
        }
        if (array_any($counts, static fn (int $count): bool => $count < 0)) {
            throw new \InvalidArgumentException('The number of rows must not be negative.');
        }

        return [
            $counts[RowOutcome::Create->value] ?? 0,
            $counts[RowOutcome::Update->value] ?? 0,
            $counts[RowOutcome::Restore->value] ?? 0,
            $counts[RowOutcome::Skip->value] ?? 0,
            $counts[RowOutcome::Archive->value] ?? 0,
            $counts[RowOutcome::Error->value] ?? 0,
        ];
    }
}
