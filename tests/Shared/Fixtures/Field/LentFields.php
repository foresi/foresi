<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Fixtures\Field;

use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldExpression;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Contract\Field\FieldSource;
use Foresi\Shared\Infrastructure\Field\DoctrineFieldSource;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A field source of the test environment only: the test-only custom fields lent to a
 * record kind of the application, such as the contacts, by a test, for its container.
 * The values stay in the test-only table, keyed by the record's id, so the record's
 * lists read, filter, sort and search them as they would an extension of its own.
 * Nothing is lent until a test lends it.
 */
final class LentFields implements FieldSource
{
    private ?string $record = null;

    public function __construct(private readonly DoctrineFieldSource $fields)
    {
    }

    /**
     * Lends the fields to the record kind in this container, before its lists are read.
     */
    public static function lend(ContainerInterface $container, string $record): Fields
    {
        $lent = $container->get(self::class);
        \assert($lent instanceof self);
        $lent->record = $record;
        $fields = $container->get(Fields::class);
        \assert($fields instanceof Fields);

        return $fields;
    }

    public function definitions(string $record): array
    {
        if (null === $this->record || $record !== $this->record) {
            return [];
        }

        return array_map(static fn (FieldDefinition $field): FieldDefinition => new FieldDefinition(
            $field->key, $record, $field->kind, $field->label, $field->options, $field->module,
            $field->filterable, $field->sortable, $field->required, $field->guests, $field->api, $field->template, $field->import, $field->searchable,
        ), $this->fields->definitions(FixtureNoteExtension::RECORD));
    }

    public function read(string $record, array $ids): array
    {
        return $record === $this->record ? $this->fields->read(FixtureNoteExtension::RECORD, $ids) : [];
    }

    public function write(string $record, string $id, array $values): void
    {
        $this->fields->write(FixtureNoteExtension::RECORD, $id, $values);
    }

    public function expression(string $record, string $key, string $root, string $alias): FieldExpression
    {
        return $this->fields->expression(FixtureNoteExtension::RECORD, $key, $root, $alias);
    }
}
