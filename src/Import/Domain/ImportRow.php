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
 * One source row, staged so the check, the run and the undo work on it in chunks.
 * The raw cells are kept until the import is purged.
 */
#[ORM\Entity]
#[ORM\Table(name: 'import_row')]
#[ORM\Index(name: 'idx_import_row_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_import_row_chunk', columns: ['import_id', 'chunk'])]
#[ORM\UniqueConstraint(name: 'uniq_import_row_number', columns: ['import_id', 'row_number'])]
class ImportRow implements TenantOwned
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true, enumType: RowOutcome::class)]
    private ?RowOutcome $outcome = null;

    /** @var list<array{field: string, messageKey: string, parameters: array<string, int|string>}> */
    #[ORM\Column(type: 'json')]
    private array $errors = [];

    #[ORM\Column(name: 'record_id', type: 'integer', nullable: true)]
    private ?int $recordId = null;

    /** @var array<string, mixed>|null the record before the import changed it */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $before = null;

    #[ORM\Column(name: 'processed_in_run', type: 'boolean')]
    private bool $processedInRun = false;

    #[ORM\Column(name: 'processed_in_undo', type: 'boolean')]
    private bool $processedInUndo = false;

    /**
     * @param list<string> $raw the cells of the row as read
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Import::class)]
        #[ORM\JoinColumn(name: 'import_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Import $import,
        #[ORM\Column(name: 'row_number', type: 'integer')]
        private readonly int $rowNumber,
        #[ORM\Column(type: 'integer')]
        private readonly int $chunk,
        #[ORM\Column(type: 'json')]
        private readonly array $raw,
    ) {
        if ($rowNumber < 1) {
            throw new \InvalidArgumentException('A row number starts at 1.');
        }
    }

    public function id(): ?int
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

    public function rowNumber(): int
    {
        return $this->rowNumber;
    }

    public function chunk(): int
    {
        return $this->chunk;
    }

    /**
     * @return list<string>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function outcome(): ?RowOutcome
    {
        return $this->outcome;
    }

    /**
     * @return list<RowError>
     */
    public function errors(): array
    {
        return array_map(
            static fn (array $error): RowError => new RowError($error['field'], $error['messageKey'], $error['parameters']),
            $this->errors,
        );
    }

    public function recordId(): ?int
    {
        return $this->recordId;
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
     * @param list<RowError> $errors
     */
    public function decide(RowOutcome $outcome, array $errors = []): void
    {
        $this->outcome = $outcome;
        $this->errors = array_map(
            static fn (RowError $error): array => ['field' => $error->field, 'messageKey' => $error->messageKey, 'parameters' => $error->parameters],
            $errors,
        );
    }

    /**
     * @param array<string, mixed>|null $before the record as it was, as plain data
     */
    public function record(?int $recordId, ?array $before): void
    {
        $this->recordId = $recordId;
        $this->before = $before;
    }

    public function markProcessed(): void
    {
        $this->processedInRun = true;
    }

    public function markUndone(): void
    {
        $this->processedInUndo = true;
    }
}
