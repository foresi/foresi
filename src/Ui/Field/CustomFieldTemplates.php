<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Field;

use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The custom fields of a record (ADR 0045) that allow templates, for the record's
 * document variables: {{ client.custom.<key> }} for a contact. Each value is the
 * text the record's detail shows, in the language of the person who generates the
 * document, and empty when the field has no value, so a template that uses a
 * field never misses it.
 */
final readonly class CustomFieldTemplates
{
    /** The variables of the fields are below this name of the record's source. */
    public const string GROUP = 'custom';

    public function __construct(private Fields $fields, private CustomFieldText $text, private TranslatorInterface $translator)
    {
    }

    /**
     * @return list<array{name: string, label: string, example: string}> the name below the source's prefix, such as "custom.acme_fleet_industry"
     */
    public function definitions(string $record): array
    {
        return array_map(
            fn (FieldDefinition $definition): array => ['name' => self::GROUP.'.'.$definition->key, 'label' => $this->translator->trans($definition->label), 'example' => $this->example($definition)],
            $this->templated($record),
        );
    }

    /**
     * @return array<string, array<string, string>> the texts under "custom" by field key, nothing when the record has no such field
     */
    public function values(string $record, string $id): array
    {
        $templated = $this->templated($record);
        if ([] === $templated) {
            return [];
        }
        $values = [];
        foreach ($templated as $definition) {
            $values[$definition->key] = $this->text->text($definition, $this->fields->value($record, $id, $definition->key)) ?? '';
        }

        return [self::GROUP => $values];
    }

    /**
     * @return list<FieldDefinition>
     */
    private function templated(string $record): array
    {
        return array_values(array_filter($this->fields->definitions($record), static fn (FieldDefinition $definition): bool => $definition->template));
    }

    /**
     * What the template preview shows for a field: a value of its kind as the document prints it.
     */
    private function example(FieldDefinition $definition): string
    {
        $options = array_map(strval(...), array_keys($definition->options));
        $value = match ($definition->kind) {
            FieldKind::Text, FieldKind::LongText => 'Example text',
            FieldKind::Number => '1250',
            FieldKind::Date => new \DateTimeImmutable('2026-11-26'),
            FieldKind::DateTime => new \DateTimeImmutable('2026-11-26 14:30'),
            FieldKind::Boolean => true,
            FieldKind::Url => 'https://example.com',
            FieldKind::Select => $options[0] ?? null,
            FieldKind::MultiSelect => \array_slice($options, 0, 2),
            FieldKind::Contact => 'Example Client s.r.o.',
            FieldKind::User => 'Jana Example',
        };
        if (\in_array($definition->kind, [FieldKind::Contact, FieldKind::User], true)) {
            return \is_string($value) ? $value : '';
        }

        return $this->text->text($definition, $value) ?? '';
    }
}
