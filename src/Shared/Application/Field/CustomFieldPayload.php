<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Contract\Operation\Omitted;

/**
 * The custom fields of a record as the REST API and the MCP tools carry them
 * (ADR 0045): an object "custom_fields" by field key, with every field the actor
 * sees (VisibleFields) and null for one without a value. A select is an option
 * key, a multi-select a list of them, a date YYYY-MM-DD, a date-time ISO 8601
 * with an offset, a contact or a user its id.
 */
final readonly class CustomFieldPayload
{
    public const string KEY = 'custom_fields';
    private const string DATE_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';

    public function __construct(private Fields $fields, private VisibleFields $visible, private ReferenceNames $references)
    {
    }

    /**
     * The rows of a record kind with their custom fields added under "custom_fields",
     * read in one go.
     *
     * @template T of array<string, mixed>
     *
     * @param list<T> $rows each with the record's "id"
     *
     * @return list<T|array<string, mixed>>
     */
    public function attach(string $record, array $rows): array
    {
        $ids = array_values(array_filter(array_map(static fn (array $row): mixed => $row['id'] ?? null, $rows), \is_string(...)));
        if ([] !== $ids) {
            $this->fields->values($record, $ids);
        }

        return array_map(fn (array $row): array => $row + [self::KEY => $this->of($record, \is_string($row['id'] ?? null) ? $row['id'] : null)], $rows);
    }

    /**
     * @param array<string, mixed> $row with the record's "id"
     *
     * @return array<string, mixed>
     */
    public function attachOne(string $record, array $row): array
    {
        return $this->attach($record, [$row])[0];
    }

    /**
     * The custom fields of one record, an empty object when the actor sees none.
     *
     * @return array<string, mixed>|\stdClass
     */
    public function of(string $record, ?string $id): array|\stdClass
    {
        $values = [];
        foreach ($this->visible->definitions($record) as $definition) {
            $values[$definition->key] = null === $id ? null : self::encoded($definition, $this->fields->value($record, $id, $definition->key));
        }

        return [] === $values ? new \stdClass() : $values;
    }

    /**
     * What a write sent under custom_fields, as Fields::write() takes it: nothing to
     * write when it was left out of an update, every value given otherwise. A create
     * that left it out writes none, so its required fields are refused.
     *
     * @param array<mixed>|Omitted $sent by field key
     *
     * @return array<string, mixed>|null null when an update leaves the custom fields as they are
     *
     * @throws FieldRefused for a key the actor sees no field of, or a value its kind does not take
     */
    public function read(string $record, array|Omitted $sent, bool $creating): ?array
    {
        if ($sent instanceof Omitted) {
            return $creating ? [] : null;
        }
        $values = [];
        foreach ($sent as $key => $value) {
            $key = (string) $key;
            $definition = $this->visible->definition($record, $key) ?? throw new FieldRefused($key, FieldRefused::UNKNOWN);
            $values[$key] = $this->decoded($definition, $value);
        }

        return $values;
    }

    /**
     * A stored value as JSON writes it.
     */
    public static function encoded(FieldDefinition $definition, mixed $value): mixed
    {
        return match (true) {
            null === $value => null,
            FieldKind::Date === $definition->kind && $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            FieldKind::DateTime === $definition->kind && $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            FieldKind::Number === $definition->kind && \is_string($value) && is_numeric($value) => str_contains($value, '.') || str_contains(strtolower($value), 'e') ? (float) $value : (int) $value,
            FieldKind::MultiSelect === $definition->kind && \is_array($value) => array_values($value),
            default => $value,
        };
    }

    /**
     * @throws FieldRefused
     */
    private function decoded(FieldDefinition $definition, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }
        $key = $definition->key;

        return match ($definition->kind) {
            FieldKind::Date => $this->date($key, $value),
            FieldKind::DateTime => $this->dateTime($key, $value),
            FieldKind::Number => \is_int($value) || \is_float($value) ? $value : throw new FieldRefused($key, FieldRefused::INVALID, \sprintf('The custom field "%s" takes a number.', $key)),
            FieldKind::Url => \is_string($value) && ('' === $value || $this->isUrl($value)) ? $value : throw new FieldRefused($key, FieldRefused::INVALID, \sprintf('The custom field "%s" takes an http or https address.', $key)),
            FieldKind::Contact, FieldKind::User => $this->reference($definition, $value),
            FieldKind::MultiSelect => \is_array($value) && array_is_list($value) ? $value : throw new FieldRefused($key, FieldRefused::INVALID, \sprintf('The custom field "%s" takes a list of option keys.', $key)),
            // The registry checks the rest by kind: a text, one of the options, true or false.
            default => $value,
        };
    }

    /**
     * @throws FieldRefused
     */
    private function date(string $key, mixed $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }
        $date = \is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new FieldRefused($key, FieldRefused::INVALID, \sprintf('The custom field "%s" takes a date as YYYY-MM-DD.', $key));
        }

        return $date;
    }

    /**
     * A moment with its offset, kept in the time zone of the application as the screens keep it.
     *
     * @throws FieldRefused
     */
    private function dateTime(string $key, mixed $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }
        try {
            if (!\is_string($value) || 1 !== preg_match(self::DATE_TIME, $value)) {
                throw new \UnexpectedValueException();
            }
            $moment = new \DateTimeImmutable($value);
            [$date] = explode('T', $value);
            if ($moment->format('Y-m-d') !== $date) {
                throw new \UnexpectedValueException();
            }
        } catch (\Exception) {
            throw new FieldRefused($key, FieldRefused::INVALID, \sprintf('The custom field "%s" takes a date and time in ISO 8601 with an offset, such as 2026-11-26T14:30:00+01:00.', $key));
        }

        return $moment->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * @throws FieldRefused
     */
    private function reference(FieldDefinition $definition, mixed $value): ?string
    {
        if ('' === $value) {
            return null;
        }
        $key = $definition->key;
        $what = FieldKind::Contact === $definition->kind ? 'contact' : 'user';
        if (!\is_string($value) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw new FieldRefused($key, FieldRefused::INVALID, \sprintf('The custom field "%s" takes the id of a %s.', $key, $what));
        }
        $names = $this->references->of($definition->kind);
        if ($names instanceof FieldReferenceNames && [] === $names->names([strtolower($value)])) {
            throw new FieldRefused($key, FieldRefused::INVALID, \sprintf('The custom field "%s" takes the id of a %s of the organisation.', $key, $what));
        }

        return strtolower($value);
    }

    private function isUrl(string $text): bool
    {
        $parts = parse_url($text);

        return false !== $parts && \in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && '' !== ($parts['host'] ?? '') && 1 !== preg_match('/\s/', $text);
    }
}
