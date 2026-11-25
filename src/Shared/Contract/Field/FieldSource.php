<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Field;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A source of custom fields (ADR 0045): their definitions, their values, and where
 * a query reads them. The core's source reads the record extensions of the modules;
 * a later one may hold fields defined at runtime. Consumers ask Fields, never a
 * source.
 *
 * A value is a string (a text, a long text, a URL, a select's option key, a decimal
 * number, the id of a contact or a user), an int or a float, a bool, a
 * DateTimeImmutable, a list of option keys (a multi-select) or null.
 */
#[AutoconfigureTag(self::TAG)]
interface FieldSource
{
    public const string TAG = 'foresi.shared.field_source';

    /**
     * @return list<FieldDefinition> the fields of a record kind; none for a kind the source has no fields of
     */
    public function definitions(string $record): array;

    /**
     * The values of records of a kind, only the set ones.
     *
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>> by record id, then by field key
     */
    public function read(string $record, array $ids): array;

    /**
     * Sets the values of one record; null clears a value. The caller's transaction
     * flushes the change.
     *
     * @param array<string, mixed> $values by field key, only keys of this source
     */
    public function write(string $record, string $id, array $values): void;

    /**
     * Where a query reads a field of the record whose row has the alias $root.
     *
     * @param string $alias the alias the field's row takes, unique in the query
     */
    public function expression(string $record, string $key, string $root, string $alias): FieldExpression;
}
