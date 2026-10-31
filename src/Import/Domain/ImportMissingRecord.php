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
 * An existing record a full replace would archive because the source does not
 * hold it. The check stages exactly the records the user then confirms, so the
 * run never archives more; the undo restores what the run archived. Chunks
 * continue the numbering of the source rows, so one sequence covers both.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_missing_record')]
#[ORM\Index(name: 'idx_import_missing_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_import_missing_chunk', columns: ['import_id', 'chunk'])]
#[ORM\UniqueConstraint(name: 'uniq_import_missing_record', columns: ['import_id', 'record_id'])]
class ImportMissingRecord implements TenantOwned
{
    /** The message of a record the run could not archive. */
    public const string ARCHIVE_FAILED = 'import.error.archive_failed';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 32, enumType: RowOutcome::class)]
    private RowOutcome $outcome = RowOutcome::Archive;

    /** @var array<string, mixed>|null the record before the run archived it */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $before = null;

    #[ORM\Column(name: 'processed_in_run', type: 'boolean')]
    private bool $processedInRun = false;

    #[ORM\Column(name: 'processed_in_undo', type: 'boolean')]
    private bool $processedInUndo = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Import::class)]
        #[ORM\JoinColumn(name: 'import_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Import $import,
        #[ORM\Column(type: 'integer')]
        private readonly int $chunk,
        #[ORM\Column(name: 'record_id', type: 'guid')]
        private readonly string $recordId,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    public function import(): Import
    {
        return $this->import;
    }

    public function chunk(): int
    {
        return $this->chunk;
    }

    public function recordId(): string
    {
        return $this->recordId;
    }

    /** Archive until the run decides; Skip when the run found the record after all, Error when archiving failed, then Reverted or ChangedAfterImport after an undo. */
    public function outcome(): RowOutcome
    {
        return $this->outcome;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function before(): ?array
    {
        return $this->before;
    }

    public function processedInRun(): bool
    {
        return $this->processedInRun;
    }

    public function processedInUndo(): bool
    {
        return $this->processedInUndo;
    }

    /**
     * @param array<string, mixed> $before
     */
    public function archived(array $before): void
    {
        $this->outcome = RowOutcome::Archive;
        $this->before = $before;
        $this->processedInRun = true;
    }

    /** The run met the record in the source after all (or could not archive it), so it stays. */
    public function kept(): void
    {
        $this->outcome = RowOutcome::Skip;
        $this->processedInRun = true;
    }

    /** The target refused to archive the record; it stays active and counts as failed. */
    public function failed(): void
    {
        $this->outcome = RowOutcome::Error;
        $this->before = null;
        $this->processedInRun = true;
    }

    /** The translation key explaining a record that failed, or null. */
    public function errorKey(): ?string
    {
        return RowOutcome::Error === $this->outcome ? self::ARCHIVE_FAILED : null;
    }

    /** The undo has nothing to put back for a record the run did not archive. */
    public function passedByUndo(): void
    {
        $this->processedInUndo = true;
    }

    public function undone(RowOutcome $outcome): void
    {
        if (!\in_array($outcome, [RowOutcome::Reverted, RowOutcome::ChangedAfterImport], true)) {
            throw new \InvalidArgumentException('An undo either reverts a record or leaves a changed one alone.');
        }
        $this->outcome = $outcome;
        $this->processedInUndo = true;
    }
}
