<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Field;

use Foresi\Shared\Application\Field\VisibleFields;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The custom fields of a record kind as the API describes them (ADR 0045): the
 * JSON Schema of the custom_fields object, for the OpenAPI document and the MCP
 * tools, and each definition as GET /api/v1/fields and foresi_list_fields list
 * it. Only the fields open to the API (api: true) are described.
 */
final readonly class CustomFieldSchema
{
    public function __construct(private Fields $fields, private VisibleFields $visible, private TranslatorInterface $translator)
    {
    }

    /**
     * The name of the OpenAPI schema of a record kind's custom fields, such as ContactCustomFields.
     */
    public static function component(string $record): string
    {
        return str_replace('_', '', ucwords($record, '_')).'CustomFields';
    }

    /**
     * @param bool $visible only the fields the current actor sees, as an MCP tool lists them; every field open to the API for the OpenAPI document
     *
     * @return array<string, mixed>
     */
    public function of(string $record, bool $visible): array
    {
        $properties = [];
        foreach ($this->definitions($record, $visible) as $definition) {
            $properties[$definition->key] = $this->property($definition);
        }

        return [
            'type' => 'object',
            'description' => 'The custom fields of the '.str_replace('_', ' ', $record).' by key, as foresi_list_fields and GET /api/v1/fields list them; null for a field without a value. A write sends only the fields it changes, null clears one.',
            'properties' => [] === $properties ? new \stdClass() : $properties,
            'additionalProperties' => false,
        ];
    }

    /**
     * @return list<FieldDefinition>
     */
    public function definitions(string $record, bool $visible): array
    {
        $definitions = $visible ? $this->visible->definitions($record) : $this->fields->definitions($record);

        return array_values(array_filter($definitions, static fn (FieldDefinition $definition): bool => $definition->api));
    }

    /**
     * A definition as the API lists it.
     *
     * @return array<string, mixed>
     */
    public function definition(FieldDefinition $definition): array
    {
        return [
            'key' => $definition->key,
            'record' => $definition->record,
            'kind' => $definition->kind->value,
            'label' => $this->translator->trans($definition->label),
            'options' => $definition->kind->selects()
                ? array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $this->translator->trans($label)], array_map(strval(...), array_keys($definition->options)), array_values($definition->options))
                : null,
            'module' => $definition->module,
            'required' => $definition->required,
            'filterable' => $definition->filterable,
            'sortable' => $definition->sortable,
            'searchable' => $definition->searchable,
            'guests' => $definition->guests,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function property(FieldDefinition $definition): array
    {
        $options = array_map(strval(...), array_keys($definition->options));
        $schema = match ($definition->kind) {
            FieldKind::Text, FieldKind::LongText => ['type' => ['string', 'null']],
            FieldKind::Url => ['type' => ['string', 'null'], 'format' => 'uri'],
            FieldKind::Number => ['type' => ['number', 'null']],
            FieldKind::Date => ['type' => ['string', 'null'], 'format' => 'date'],
            FieldKind::DateTime => ['type' => ['string', 'null'], 'format' => 'date-time'],
            FieldKind::Boolean => ['type' => ['boolean', 'null']],
            FieldKind::Select => ['type' => ['string', 'null'], 'enum' => [...$options, null]],
            FieldKind::MultiSelect => ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'enum' => $options]],
            FieldKind::Contact, FieldKind::User => ['type' => ['string', 'null'], 'format' => 'uuid'],
        };
        $about = match ($definition->kind) {
            FieldKind::Select => ' An option key.',
            FieldKind::MultiSelect => ' A list of option keys.',
            FieldKind::Contact => ' The id of a contact.',
            FieldKind::User => ' The id of a person of the organisation.',
            default => '',
        };

        return $schema + ['description' => $this->translator->trans($definition->label).'.'.$about.($definition->required ? ' Required when the record is created.' : '')];
    }
}
