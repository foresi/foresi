<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Field;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\Field\FieldRecords;

/**
 * The custom fields GET /api/v1/fields and foresi_list_fields list (ADR 0045):
 * those open to the API that the token's person sees, of every record kind the
 * token may read, or of one kind. No surface creates or changes a definition.
 */
final readonly class FieldList
{
    public function __construct(private FieldRecords $records, private CustomFieldSchema $schema, private ScopeGuard $scopes)
    {
    }

    /**
     * @return list<string> the record kinds that take custom fields
     */
    public function kinds(): array
    {
        $kinds = array_keys($this->records->all());
        sort($kinds);

        return $kinds;
    }

    /**
     * @param string|null $record one record kind, or every kind the token may read
     *
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException for a record kind that takes no custom fields
     * @throws \DomainException          when the token may not read that record kind
     */
    public function of(?string $record): array
    {
        if (null !== $record) {
            $kind = $this->records->get($record) ?? throw new \InvalidArgumentException(\sprintf('record must be one of %s.', implode(', ', $this->kinds())));
            $this->scopes->require($kind->scope());
            $kinds = [$record];
        } else {
            $kinds = array_values(array_filter($this->kinds(), fn (string $kind): bool => $this->scopes->allows($this->records->get($kind)?->scope() ?? '-')));
        }
        $definitions = [];
        foreach ($kinds as $kind) {
            foreach ($this->schema->definitions($kind, true) as $definition) {
                $definitions[] = $this->schema->definition($definition);
            }
        }

        return $definitions;
    }
}
