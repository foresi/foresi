<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Import\Support;

use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Audit\Application\AuditTrail;
use Foresi\Import\Contract\BlankCells;
use Foresi\Import\Contract\FieldDefinition;
use Foresi\Import\Contract\FieldType;
use Foresi\Import\Contract\ImportTarget;
use Foresi\Import\Contract\RecordMatch;
use Foresi\Import\Contract\RecordSnapshot;
use Foresi\Import\Contract\RowError;
use Foresi\Import\Contract\WriteResult;
use Foresi\Shared\Domain\Id;

/**
 * An import target that keeps its records in memory: a code (the match key),
 * a required name and an e-mail. A code matches without its leading zeros. It logs every write with the audit correlation
 * active at the time, so tests see what the pipeline wrote and under which id.
 * A name containing FAIL makes a write or an archive throw; INVALID makes validation fail.
 */
final class FakeTarget implements ImportTarget
{
    public const string KEY = 'fake';

    /** @var array<string, array{values: array<string, mixed>, archived: bool}> */
    public array $records = [];

    /** @var list<array{string, string, string|null}> operation, record id, correlation id */
    public array $writes = [];

    /** @var (callable(array<string, mixed>): void)|null runs inside create(), so a test can make the write fail in the database */
    public $onCreate;

    public function __construct(private readonly AuditCorrelation $correlation, private readonly AuditTrail $trail)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function labelKey(): string
    {
        return 'import.fake';
    }

    public function fields(): array
    {
        return [
            new FieldDefinition('code', 'import.fake.code', FieldType::Identifier, aliases: ['code']),
            new FieldDefinition('name', 'import.fake.name', FieldType::Text, required: true, aliases: ['name']),
            new FieldDefinition('email', 'import.fake.email', FieldType::Email, aliases: ['email']),
        ];
    }

    public function matchKeys(): array
    {
        return ['code', 'email'];
    }

    public function validate(array $values): array
    {
        $name = $values['name'] ?? null;
        if (null === $name) {
            return [new RowError('name', 'import.error.required')];
        }

        return \is_string($name) && str_contains($name, 'INVALID') ? [new RowError('name', 'import.error.unknown_value', ['value' => $name])] : [];
    }

    /** A code is matched without its leading zeros and an e-mail in lower case, as a target matches on its own canonical form. */
    public function matchKeyValue(string $matchKey, array $values): ?string
    {
        $value = $values[$matchKey] ?? null;
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return 'code' === $matchKey ? ltrim($value, '0') : mb_strtolower($value);
    }

    public function match(string $matchKey, array $values): array
    {
        $key = $this->matchKeyValue($matchKey, $values);
        $matches = [];
        foreach ($this->records as $id => $record) {
            if (null !== $key && $this->matchKeyValue($matchKey, $record['values']) === $key) {
                $matches[] = new RecordMatch($id, $record['archived']);
            }
        }

        return $matches;
    }

    public function create(array $values): WriteResult
    {
        $this->failOn($values);
        if (null !== $this->onCreate) {
            ($this->onCreate)($values);
        }
        $id = Id::generate();
        $this->records[$id] = ['values' => $values, 'archived' => false];
        $this->log('create', $id);

        return new WriteResult($id, RecordSnapshot::none());
    }

    public function update(string $id, array $values, BlankCells $blankCells): WriteResult
    {
        $this->failOn($values);
        $before = $this->snapshot($id);
        foreach ($values as $field => $value) {
            if (null !== $value || BlankCells::Clear === $blankCells) {
                $this->records[$id]['values'][$field] = $value;
            }
        }
        $this->log('update', $id);

        return new WriteResult($id, $before);
    }

    public function restoreArchived(string $id): void
    {
        $this->records[$id]['archived'] = false;
        $this->log('restore', $id);
    }

    public function archive(string $id): RecordSnapshot
    {
        $this->failOn($this->records[$id]['values']);
        $before = $this->snapshot($id);
        $this->records[$id]['archived'] = true;
        $this->log('archive', $id);

        return $before;
    }

    public function revert(string $id, RecordSnapshot $before): void
    {
        if ($before->isNone()) {
            $this->records[$id]['archived'] = true;
        } else {
            /** @var array<string, mixed> $values */
            $values = $before->data['values'];
            $this->records[$id] = ['values' => $values, 'archived' => true === $before->data['archived']];
        }
        $this->log('revert', $id);
    }

    public function activeIds(): iterable
    {
        foreach ($this->records as $id => $record) {
            if (!$record['archived']) {
                yield $id;
            }
        }
    }

    public function export(array $ids): iterable
    {
        foreach ($ids as $id) {
            yield array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $this->records[$id]['values']);
        }
    }

    public function changedSince(string $id, RecordSnapshot $before, \DateTimeImmutable $since, array $exceptCorrelationIds): bool
    {
        return $this->trail->changedSince(self::class, $id, $since, ...$exceptCorrelationIds);
    }

    /**
     * A record made outside any import, as a user would.
     *
     * @param array<string, mixed> $values
     */
    public function add(array $values, bool $archived = false): string
    {
        $id = Id::generate();
        $this->records[$id] = ['values' => $values, 'archived' => $archived];

        return $id;
    }

    /** The id of the record made nth, counting from 1. */
    public function nth(int $nth): string
    {
        return array_keys($this->records)[$nth - 1];
    }

    /**
     * @return list<string> the names of the active records, sorted
     */
    public function activeNames(): array
    {
        $names = [];
        foreach ($this->records as $record) {
            if (!$record['archived']) {
                $name = $record['values']['name'] ?? '';
                $names[] = \is_string($name) ? $name : '';
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @return int the number of logged writes of this kind
     */
    public function count(string $operation): int
    {
        return \count(array_filter($this->writes, static fn (array $write): bool => $write[0] === $operation));
    }

    private function snapshot(string $id): RecordSnapshot
    {
        return new RecordSnapshot(['values' => $this->records[$id]['values'], 'archived' => $this->records[$id]['archived']]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function failOn(array $values): void
    {
        $name = $values['name'] ?? null;
        if (\is_string($name) && str_contains($name, 'FAIL')) {
            throw new \RuntimeException('The fake target refuses this record.');
        }
    }

    private function log(string $operation, string $id): void
    {
        $this->writes[] = [$operation, $id, $this->correlation->current()];
    }
}
