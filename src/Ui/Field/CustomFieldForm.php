<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Field;

use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Application\Field\VisibleFields;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The custom fields of a record's form (ADR 0045), as Ui:CustomFields shows them: the
 * texts its inputs start with, and the parsed values of a posted form. The inputs are
 * named "custom_fields[<key>]" ("custom_fields[<key>][]" for a multi-select), and a
 * reference's typed text "custom_fields_text[<key>]": without JavaScript the text is
 * resolved when exactly one record matches it. Only the fields the actor sees are read,
 * so a guest never sends a hidden one; a blank input, an unticked box or no option
 * chosen clears the value.
 */
final readonly class CustomFieldForm
{
    public const string NAME = 'custom_fields';
    public const string TEXT_NAME = 'custom_fields_text';
    /** The suffix of a reference's name among the values. */
    public const string TEXT = '__text';

    public const string DATE = 'Y-m-d';
    public const string DATETIME = 'Y-m-d\TH:i';

    public function __construct(
        private VisibleFields $visible,
        private Fields $fields,
        private ReferenceNames $names,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * What the inputs of a record's fields start with: its values, or none for a new record.
     *
     * @return array<string, string|list<string>> by field key, a reference's name under "<key>__text"
     */
    public function values(string $record, ?string $id): array
    {
        $values = [];
        foreach ($this->visible->definitions($record) as $definition) {
            $values[$definition->key] = null === $id ? null : $this->fields->value($record, $id, $definition->key);
        }

        return $this->inputs($record, $values);
    }

    /**
     * What the inputs start with for values kept outside a record, as the API writes
     * them, such as the fields a recurring task gives each of its tasks.
     *
     * @param array<string, mixed> $stored by field key, a date as YYYY-MM-DD and a date-time in ISO 8601
     *
     * @return array<string, string|list<string>> by field key, a reference's name under "<key>__text"
     */
    public function valuesFrom(string $record, array $stored): array
    {
        $values = [];
        foreach ($this->visible->definitions($record) as $definition) {
            $value = $stored[$definition->key] ?? null;
            $values[$definition->key] = FieldKind::DateTime === $definition->kind && \is_string($value) && '' !== $value ? new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone(date_default_timezone_get())) : $value;
        }

        return $this->inputs($record, $values);
    }

    /**
     * @param array<string, mixed> $stored by field key, as Fields::value() gives them
     *
     * @return array<string, string|list<string>>
     */
    private function inputs(string $record, array $stored): array
    {
        $values = [];
        foreach ($this->visible->definitions($record) as $definition) {
            $value = $stored[$definition->key] ?? null;
            $values[$definition->key] = $this->text($definition, $value);
            if ($this->references($definition)) {
                $values[$definition->key.self::TEXT] = \is_string($value) && '' !== $value ? ($this->names->of($definition->kind)?->names([$value])[$value] ?? '') : '';
            }
        }

        return $values;
    }

    /**
     * The fields of a posted form, parsed.
     *
     * @param bool        $creating     whether the record is new, so a required field needs a value
     * @param bool        $requiredOnly whether the form shows only the required fields, as a short dialog does
     * @param string|null $key          the one field the form holds, such as a field of a task's sidebar
     */
    public function read(string $record, Request $request, bool $creating, bool $requiredOnly = false, ?string $key = null): CustomFieldSubmission
    {
        $posted = $request->request->all(self::NAME);
        $texts = $request->request->all(self::TEXT_NAME);
        $values = [];
        $parsed = [];
        $errors = [];
        foreach ($this->visible->definitions($record) as $definition) {
            if ((null !== $key && $definition->key !== $key) || ($requiredOnly && !$definition->required)) {
                continue;
            }
            $raw = $posted[$definition->key] ?? null;
            try {
                if ($this->references($definition)) {
                    $text = \is_string($texts[$definition->key] ?? null) ? trim($texts[$definition->key]) : '';
                    [$value, $text] = $this->reference($definition, \is_string($raw) ? trim($raw) : '', $text);
                    $values[$definition->key] = $value ?? '';
                    $values[$definition->key.self::TEXT] = $text;
                } else {
                    $values[$definition->key] = FieldKind::MultiSelect === $definition->kind
                        ? array_values(array_filter(\is_array($raw) ? $raw : [], \is_string(...)))
                        : (\is_string($raw) ? $raw : '');
                    $value = $this->parsed($definition, $values[$definition->key], $request->getLocale());
                }
                if ($creating && $definition->required && null === $value) {
                    throw new \UnexpectedValueException(FieldRefused::REQUIRED);
                }
                $parsed[$definition->key] = $value;
            } catch (\UnexpectedValueException $wrong) {
                $errors[$definition->key] = $this->translator->trans($wrong->getMessage());
            }
        }

        return new CustomFieldSubmission($values, $parsed, $errors);
    }

    /**
     * @param string|list<string> $typed
     *
     * @throws \UnexpectedValueException with the translation key of what is wrong
     */
    private function parsed(FieldDefinition $definition, string|array $typed, string $locale): mixed
    {
        if (\is_array($typed)) {
            foreach ($typed as $option) {
                if (!isset($definition->options[$option])) {
                    throw new \UnexpectedValueException('ui.custom_fields.error.option');
                }
            }

            return [] === $typed ? null : array_values(array_unique($typed));
        }
        $text = trim($typed);
        if ('' === $text) {
            return null;
        }

        return match ($definition->kind) {
            FieldKind::Text, FieldKind::LongText => $text,
            FieldKind::Number => null !== ($number = $this->number($text, $locale)) ? $number : throw new \UnexpectedValueException('ui.custom_fields.error.number'),
            FieldKind::Date => $this->date(self::DATE, $text) ?? throw new \UnexpectedValueException('ui.custom_fields.error.date'),
            FieldKind::DateTime => $this->date(self::DATETIME, $text) ?? $this->date('Y-m-d\TH:i:s', $text) ?? throw new \UnexpectedValueException('ui.custom_fields.error.date'),
            FieldKind::Boolean => true,
            FieldKind::Url => $this->url($text) ? $text : throw new \UnexpectedValueException('ui.custom_fields.error.url'),
            FieldKind::Select => isset($definition->options[$text]) ? $text : throw new \UnexpectedValueException('ui.custom_fields.error.option'),
            FieldKind::MultiSelect, FieldKind::Contact, FieldKind::User => throw new \LogicException('Read apart.'),
        };
    }

    /**
     * The record a reference points to: the id picked, or the one record its typed text matches.
     *
     * @return array{?string, string} the id and the name to show
     *
     * @throws \UnexpectedValueException with the translation key of what is wrong
     */
    private function reference(FieldDefinition $definition, string $id, string $text): array
    {
        $names = $this->names->of($definition->kind);
        if ('' !== $id) {
            $name = $names?->names([$id])[$id] ?? null;

            return null === $name ? throw new \UnexpectedValueException('ui.custom_fields.error.reference') : [$id, $name];
        }
        if ('' === $text) {
            return [null, ''];
        }
        $matches = $names?->matches($text, 2) ?? [];
        if (1 !== \count($matches)) {
            throw new \UnexpectedValueException([] === $matches ? 'ui.custom_fields.error.reference' : 'ui.custom_fields.error.ambiguous');
        }

        return [$matches[0]['id'], $matches[0]['label']];
    }

    /**
     * @return string|list<string>
     */
    private function text(FieldDefinition $definition, mixed $value): string|array
    {
        return match (true) {
            FieldKind::MultiSelect === $definition->kind => \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [],
            $value instanceof \DateTimeInterface => $value->format(FieldKind::DateTime === $definition->kind ? self::DATETIME : self::DATE),
            // A ticked box reads "1", an unticked one "".
            \is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * A typed number with a decimal point, as the locale writes it: "1 234,5" or "1,234.5"; null for no number.
     */
    private function number(string $text, string $locale): ?string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $decimal = $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        $group = $formatter->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
        $text = str_replace([' ', "\u{a0}", "\u{202f}", "\u{2009}"], '', $text);
        if (str_contains($text, ',') && str_contains($text, '.')) {
            // Both: the last one is the decimal point.
            $text = strrpos($text, ',') > strrpos($text, '.') ? str_replace(['.', ','], ['', '.'], $text) : str_replace(',', '', $text);
        } elseif (str_contains($text, ',')) {
            $text = ',' === $group && '.' === $decimal && 1 === preg_match('/^-?\d{1,3}(,\d{3})+$/', $text) ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        }

        return is_numeric($text) ? $text : null;
    }

    private function references(FieldDefinition $definition): bool
    {
        return FieldKind::Contact === $definition->kind || FieldKind::User === $definition->kind;
    }

    private function date(string $format, string $text): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $text);

        // The last errors are false when the text had neither a warning nor an error, such as "2027-02-30" has.
        return false === $date || false !== \DateTimeImmutable::getLastErrors() ? null : $date;
    }

    private function url(string $text): bool
    {
        $parts = parse_url($text);

        return false !== $parts && \in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && '' !== ($parts['host'] ?? '') && 1 !== preg_match('/\s/', $text);
    }
}
