<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

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

    /** When the actor opened the summary of the finished import. */
    #[ORM\Column(name: 'seen_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $seenAt = null;

    #[ORM\Column(name: 'failure_message_key', type: 'string', length: 128, nullable: true)]
    private ?string $failureMessageKey = null;

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
        #[ORM\Column(name: 'actor_user_id', type: 'integer')]
        private int $actorUserId,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->mapping = new Mapping()->toArray();
        $this->options = ImportOptions::standard();
    }

    public function id(): ?int
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

    public function actorUserId(): int
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

    public function seenAt(): ?\DateTimeImmutable
    {
        return $this->seenAt;
    }

    public function failureMessageKey(): ?string
    {
        return $this->failureMessageKey;
    }

    /**
     * @throws IllegalTransition
     */
    public function moveTo(ImportState $next, ?\DateTimeImmutable $at = null): void
    {
        if (!$this->state->canMoveTo($next)) {
            throw IllegalTransition::between($this->state, $next);
        }
        $at ??= new \DateTimeImmutable();
        $this->state = $next;
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
    }

    public function findArchiveCandidates(int $count): void
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('The number of archive candidates must not be negative.');
        }
        $this->archiveCandidates = $count;
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
        $unknown = array_diff(array_keys($counts), array_map(static fn (RowOutcome $outcome): string => $outcome->value, RowOutcome::cases()));
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a row outcome.', reset($unknown)));
        }
        if (array_any($counts, static fn (int $count): bool => $count < 0)) {
            throw new \InvalidArgumentException('The number of rows must not be negative.');
        }

        $created = $counts[RowOutcome::Create->value] ?? 0;
        $updated = $counts[RowOutcome::Update->value] ?? 0;
        $restored = $counts[RowOutcome::Restore->value] ?? 0;
        $skipped = $counts[RowOutcome::Skip->value] ?? 0;
        $archived = $counts[RowOutcome::Archive->value] ?? 0;
        $failed = $counts[RowOutcome::Error->value] ?? 0;

        $this->created += $created;
        $this->updated += $updated;
        $this->restored += $restored;
        $this->skipped += $skipped;
        $this->archived += $archived;
        $this->failed += $failed;
        $this->processed += $created + $updated + $restored + $skipped + $archived + $failed;
        ++$this->chunksDone;
    }
}
