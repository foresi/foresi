<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Field;

use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A custom field's value as plain text in the language of the person who reads it
 * (ADR 0045): a select and a multi-select by their labels, a number, a date and a
 * date and time as the screens write them, yes or no, a contact or a person by
 * name. The detail of a record and the document templates show values this way.
 */
final readonly class CustomFieldText
{
    public function __construct(private TranslatorInterface $translator, private ReferenceNames $names)
    {
    }

    /**
     * Null for a value that is not set.
     */
    public function text(FieldDefinition $definition, mixed $value): ?string
    {
        if (null === $value || '' === $value || [] === $value) {
            return null;
        }

        return match ($definition->kind) {
            FieldKind::Text, FieldKind::LongText, FieldKind::Url => \is_scalar($value) ? (string) $value : null,
            FieldKind::Number => is_numeric($value) ? $this->number($value) : null,
            FieldKind::Date => $value instanceof \DateTimeInterface ? $value->format($this->translator->trans('format.date')) : null,
            FieldKind::DateTime => $value instanceof \DateTimeInterface ? $value->format($this->translator->trans('format.datetime')) : null,
            FieldKind::Boolean => $this->translator->trans(true === $value ? 'ui.attribute.yes' : 'ui.attribute.no'),
            FieldKind::Select => \is_string($value) ? $this->option($definition, $value) : null,
            FieldKind::MultiSelect => \is_array($value) ? implode(', ', array_map(fn (mixed $option): string => \is_string($option) ? $this->option($definition, $option) : '', $value)) : null,
            FieldKind::Contact, FieldKind::User => \is_string($value) ? $this->name($definition, $value) : null,
        };
    }

    private function option(FieldDefinition $definition, string $option): string
    {
        return isset($definition->options[$option]) ? $this->translator->trans($definition->options[$option]) : $option;
    }

    /**
     * The name of the contact or person a field points to, or a note that they are no longer there.
     */
    private function name(FieldDefinition $definition, string $id): string
    {
        $names = $this->names->of($definition->kind);

        return ($names instanceof FieldReferenceNames ? $names->names([$id])[$id] ?? null : null) ?? $this->translator->trans('ui.custom_fields.unknown');
    }

    private function number(int|float|string $value): string
    {
        $text = new \NumberFormatter($this->translator->getLocale(), \NumberFormatter::DECIMAL)->format((float) $value);

        return false === $text ? (string) $value : $text;
    }
}
