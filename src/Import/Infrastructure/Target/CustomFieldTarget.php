<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Infrastructure\Target;

use Foresi\Import\Contract\BlankCells;
use Foresi\Import\Contract\FieldDefinition;
use Foresi\Import\Contract\FieldType;
use Foresi\Import\Contract\HeaderName;
use Foresi\Import\Contract\ImportTarget;
use Foresi\Import\Contract\RecordSnapshot;
use Foresi\Import\Contract\RowError;
use Foresi\Import\Contract\WriteResult;
use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Contract\Field\FieldDefinition as CustomFieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An import target with the custom fields of its records (ADR 0045): every field
 * that allows import is one more field of the target, "custom_<key>", in a group of
 * its own, and one more column of the export. The target's own fields go to the
 * target as they came; the custom ones are written through Fields after it, and
 * an undo of an update puts their earlier values back.
 *
 * A cell is read by the field's kind: a select by an option's key or its label in
 * Czech or English, a multi-select as such options separated by commas or
 * semicolons, a contact or a user by its id, a date and time as ISO 8601 or
 * "YYYY-MM-DD HH:MM". The export writes them the same way, with labels in the
 * language of the person who exports.
 */
final readonly class CustomFieldTarget implements ImportTarget
{
    public const string PREFIX = 'custom_';

    public const string GROUP = 'custom';

    /** The key under which a snapshot keeps the custom fields an update changed. */
    private const string SNAPSHOT = 'custom_fields';

    /** The languages whose option labels a cell may use. */
    private const array LABEL_LOCALES = ['cs', 'en'];

    /** A date and time in the application's time zone, with or without seconds, or ISO 8601 with an offset. */
    private const array DATE_TIME_FORMATS = ['Y-m-d H:i', 'Y-m-d H:i:s', \DATE_ATOM];

    public function __construct(
        private ImportTarget $target,
        private string $record,
        private Fields $fields,
        private ReferenceNames $references,
        private TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return $this->target->key();
    }

    public function labelKey(): string
    {
        return $this->target->labelKey();
    }

    public function fields(): array
    {
        $fields = $this->target->fields();
        foreach ($this->definitions() as $definition) {
            $fields[] = new FieldDefinition(
                self::PREFIX.$definition->key,
                $definition->label,
                $this->type($definition),
                choices: FieldKind::Select === $definition->kind ? array_keys($definition->options) : [],
                group: self::GROUP,
                choiceNames: FieldKind::Select === $definition->kind ? $this->optionNames($definition) : [],
            );
        }

        return $fields;
    }

    public function matchKeys(): array
    {
        return $this->target->matchKeys();
    }

    public function validate(array $values): array
    {
        [$own, $custom] = $this->split($values);
        $errors = $this->target->validate($own);
        foreach ($custom as $key => $value) {
            $read = $this->read($key, $value);
            if ($read instanceof RowError) {
                $errors[] = $read;
            }
        }

        return $errors;
    }

    public function matchKeyValue(string $matchKey, array $values): ?string
    {
        return $this->target->matchKeyValue($matchKey, $this->split($values)[0]);
    }

    public function match(string $matchKey, array $values): array
    {
        return $this->target->match($matchKey, $this->split($values)[0]);
    }

    public function create(array $values): WriteResult
    {
        [$own, $custom] = $this->split($values);
        $result = $this->target->create($own);
        $this->write($result->id, $this->values($custom));

        return $result;
    }

    public function update(string $id, array $values, BlankCells $blankCells): WriteResult
    {
        [$own, $custom] = $this->split($values);
        $changes = $this->values($custom);
        if (BlankCells::Keep === $blankCells) {
            $changes = array_filter($changes, static fn (mixed $value): bool => null !== $value);
        }
        $before = [];
        foreach (array_keys($changes) as $key) {
            $definition = $this->fields->definition($this->record, $key);
            if ($definition instanceof CustomFieldDefinition) {
                $before[$key] = CustomFieldPayload::encoded($definition, $this->fields->value($this->record, $id, $key));
            }
        }
        $result = $this->target->update($id, $own, $blankCells);
        $this->write($id, $changes);
        if ([] === $before) {
            return $result;
        }

        return new WriteResult($result->id, new RecordSnapshot($result->before->data + [self::SNAPSHOT => $before]));
    }

    public function restoreArchived(string $id): void
    {
        $this->target->restoreArchived($id);
    }

    public function archive(string $id): RecordSnapshot
    {
        return $this->target->archive($id);
    }

    public function revert(string $id, RecordSnapshot $before): void
    {
        $data = $before->data;
        $custom = $data[self::SNAPSHOT] ?? null;
        unset($data[self::SNAPSHOT]);
        if (\is_array($custom)) {
            $values = [];
            foreach ($custom as $key => $value) {
                // A field that is no longer installed has nothing to put back.
                $definition = $this->fields->definition($this->record, (string) $key);
                if ($definition instanceof CustomFieldDefinition) {
                    $values[$definition->key] = $this->decoded($definition, $value);
                }
            }
            $this->write($id, $values);
        }

        $this->target->revert($id, new RecordSnapshot($data));
    }

    public function changedSince(string $id, RecordSnapshot $before, \DateTimeImmutable $since, array $exceptCorrelationIds): bool
    {
        // The extension of a record is audited under the record, so the target sees its changes too.
        $data = $before->data;
        unset($data[self::SNAPSHOT]);

        return $this->target->changedSince($id, new RecordSnapshot($data), $since, $exceptCorrelationIds);
    }

    public function activeIds(): iterable
    {
        return $this->target->activeIds();
    }

    public function export(array $ids): iterable
    {
        $definitions = $this->definitions();
        if ([] === $definitions) {
            yield from $this->target->export($ids);

            return;
        }
        $values = $this->fields->values($this->record, $ids);
        foreach ($this->target->export($ids) as $row) {
            $own = $values[$row['id'] ?? ''] ?? [];
            foreach ($definitions as $definition) {
                $row[self::PREFIX.$definition->key] = $this->exported($definition, $own[$definition->key] ?? null);
            }
            yield $row;
        }
    }

    /**
     * @return list<CustomFieldDefinition> the fields of the record that allow import
     */
    private function definitions(): array
    {
        return array_values(array_filter($this->fields->definitions($this->record), static fn (CustomFieldDefinition $definition): bool => $definition->import));
    }

    private function type(CustomFieldDefinition $definition): FieldType
    {
        return match ($definition->kind) {
            FieldKind::Number => FieldType::Decimal,
            FieldKind::Date => FieldType::Date,
            FieldKind::Boolean => FieldType::Boolean,
            FieldKind::Select => FieldType::Choice,
            FieldKind::Contact, FieldKind::User => FieldType::Uuid,
            FieldKind::Text, FieldKind::LongText, FieldKind::Url, FieldKind::DateTime, FieldKind::MultiSelect => FieldType::Text,
        };
    }

    /**
     * The options by their key and their Czech and English labels, folded as headers are.
     *
     * @return array<string, string> folded name => option key
     */
    private function optionNames(CustomFieldDefinition $definition): array
    {
        $names = [];
        foreach ($definition->options as $key => $label) {
            $names[HeaderName::normalise($key)] = $key;
            foreach (self::LABEL_LOCALES as $locale) {
                $names[HeaderName::normalise($this->translator->trans($label, [], 'messages', $locale))] = $key;
            }
        }

        return $names;
    }

    /**
     * The target's own values and the custom ones, the latter by field key.
     *
     * @param array<string, mixed> $values
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function split(array $values): array
    {
        $own = [];
        $custom = [];
        foreach ($values as $key => $value) {
            if (str_starts_with($key, self::PREFIX)) {
                $custom[substr($key, \strlen(self::PREFIX))] = $value;
            } else {
                $own[$key] = $value;
            }
        }

        return [$own, $custom];
    }

    /**
     * The values as Fields takes them, null for an empty cell; a value that cannot be read, or of a field that is not imported, is left out.
     *
     * @param array<string, mixed> $custom by field key
     *
     * @return array<string, mixed>
     */
    private function values(array $custom): array
    {
        $values = [];
        // Only the fields that allow import: a cell of any other is never written.
        foreach ($this->definitions() as $definition) {
            if (!\array_key_exists($definition->key, $custom)) {
                continue;
            }
            $read = $this->read($definition->key, $custom[$definition->key]);
            if (!$read instanceof RowError) {
                $values[$definition->key] = $read;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function write(string $id, array $values): void
    {
        if ([] !== $values) {
            $this->fields->write($this->record, $id, $values);
        }
    }

    /**
     * A normalised cell as Fields takes it.
     */
    private function read(string $key, mixed $value): mixed
    {
        $definition = $this->fields->definition($this->record, $key);
        if (!$definition instanceof CustomFieldDefinition || null === $value) {
            return null;
        }
        $field = self::PREFIX.$key;
        $text = \is_scalar($value) ? (string) $value : '';

        return match ($definition->kind) {
            FieldKind::DateTime => $this->dateTime($text) ?? new RowError($field, 'import.error.invalid_date_time', ['value' => $text]),
            FieldKind::Url => $this->isUrl($text) ? $text : new RowError($field, 'import.error.invalid_url', ['value' => $text]),
            FieldKind::MultiSelect => $this->options($definition, $text),
            FieldKind::Contact, FieldKind::User => $this->reference($definition, $text),
            default => $value,
        };
    }

    /**
     * A moment in one of the forms the import reads, the same moment when written back
     * in that form, so a day or an hour that does not exist is refused.
     */
    private function dateTime(string $text): ?\DateTimeImmutable
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        foreach (self::DATE_TIME_FORMATS as $format) {
            $moment = \DateTimeImmutable::createFromFormat('!'.$format, $text, $zone);
            if (false !== $moment && $moment->format($format) === $text) {
                return $moment->setTimezone($zone);
            }
        }

        return null;
    }

    private function isUrl(string $text): bool
    {
        $parts = parse_url($text);

        return false !== $parts && \in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && '' !== ($parts['host'] ?? '') && 1 !== preg_match('/\s/', $text);
    }

    /**
     * The options a multi-select cell names, by key or label, in the order given.
     *
     * @return list<string>|RowError
     */
    private function options(CustomFieldDefinition $definition, string $text): array|RowError
    {
        $names = $this->optionNames($definition);
        $keys = [];
        $parts = preg_split('/[,;]/', $text);
        foreach (false === $parts ? [] : $parts as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            $key = $names[HeaderName::normalise($part)] ?? null;
            if (null === $key) {
                return new RowError(self::PREFIX.$definition->key, 'import.error.unknown_value', ['value' => $part]);
            }
            $keys[$key] = $key;
        }

        return array_values($keys);
    }

    private function reference(CustomFieldDefinition $definition, string $id): string|RowError
    {
        $names = $this->references->of($definition->kind);
        if ($names instanceof FieldReferenceNames && [] === $names->names([$id])) {
            return new RowError(self::PREFIX.$definition->key, FieldKind::Contact === $definition->kind ? 'import.error.unknown_contact' : 'import.error.unknown_user', ['value' => $id]);
        }

        return $id;
    }

    /**
     * A stored value as the export writes it, in the form the import reads back.
     */
    private function exported(CustomFieldDefinition $definition, mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        return match (true) {
            $value instanceof \DateTimeInterface => FieldKind::Date === $definition->kind
                ? $value->format('Y-m-d')
                : \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i'),
            \is_bool($value) => $this->translator->trans($value ? 'import.value.yes' : 'import.value.no'),
            FieldKind::Select === $definition->kind && \is_string($value) => $this->optionLabel($definition, $value),
            FieldKind::MultiSelect === $definition->kind && \is_array($value) => implode(', ', array_map(fn (mixed $key): string => $this->optionLabel($definition, \is_scalar($key) ? (string) $key : ''), $value)),
            \is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private function optionLabel(CustomFieldDefinition $definition, string $key): string
    {
        $label = $definition->options[$key] ?? null;

        return null === $label ? $key : $this->translator->trans($label);
    }

    /**
     * A value an undo snapshot keeps (CustomFieldPayload::encoded()) as Fields takes it.
     */
    private function decoded(CustomFieldDefinition $definition, mixed $value): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }

        return match ($definition->kind) {
            FieldKind::Date => $this->date($value),
            FieldKind::DateTime => new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone(date_default_timezone_get())),
            default => $value,
        };
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }
}
