<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Field;

/**
 * The custom fields of the records (ADR 0045), from every field source: what the
 * screens, the lists, the API and the other consumers read and write them through.
 *
 * The values of a record kind are remembered for the request: a list reads the
 * values of its page once (values()) and then each value without a query (value()).
 */
interface Fields
{
    /**
     * @return list<FieldDefinition> the fields of a record kind, such as ContactExtension::RECORD
     */
    public function definitions(string $record): array;

    public function definition(string $record, string $key): ?FieldDefinition;

    /**
     * The set values of records of a kind, read once a request.
     *
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>> by record id, then by field key
     */
    public function values(string $record, array $ids): array;

    /**
     * One value of a record, null when it is not set; read with the record's other
     * values when values() did not read them yet.
     */
    public function value(string $record, string $id, string $key): mixed;

    /**
     * Sets values of one record; null clears one. The caller's transaction flushes
     * the change.
     *
     * @param array<string, mixed> $values by field key
     *
     * @throws \InvalidArgumentException for a key the record has no field of, or a value its kind does not take
     */
    public function write(string $record, string $id, array $values): void;

    /**
     * Where a query reads a field of the record whose row has the alias $root, under
     * the alias $alias, or "cf_<key>" when none is given.
     *
     * @throws \InvalidArgumentException for a key the record has no field of
     */
    public function expression(string $record, string $key, string $root, ?string $alias = null): FieldExpression;

    /**
     * The conditions of the record's text search for its searchable fields, each an
     * "EXISTS (...)" that compares the lowercase value with the parameter by LIKE
     * with "!" as the escape character, to be joined with OR to the record's own.
     *
     * @param string $parameter the name of the parameter, without the colon, that holds "%text%" in lower case, escaped
     *
     * @return list<string>
     */
    public function searched(string $record, string $root, string $parameter): array;
}
