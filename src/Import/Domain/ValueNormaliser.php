<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

use App\Shared\Domain\Countries;

/** Turns the text of a source cell into the value a target field holds. */
final readonly class ValueNormaliser
{
    private const array TRUE_WORDS = ['ano', 'a', 'yes', 'y', 'true', '1', 'x'];
    private const array FALSE_WORDS = ['ne', 'n', 'no', 'false', '0'];

    /**
     * @param array<array-key, mixed> $valueMap what the user decided a source value means, by source text
     *
     * @return mixed null for an empty cell; a string, int, bool, \DateTimeImmutable or list of e-mail addresses for the field type; a RowError for text that cannot be read
     */
    public function normalise(FieldDefinition $field, string $raw, NormalisationOptions $options, array $valueMap = []): mixed
    {
        $text = Text::clean($raw);
        if ('' === $text) {
            return null;
        }

        return match ($field->type) {
            FieldType::Text => $text,
            FieldType::Email => $this->email($field, $text),
            FieldType::EmailList => $this->emailList($field, $text),
            FieldType::Phone => $this->phone($field, $text),
            FieldType::Date => DateParser::parse($text, $options->dateOrder) ?? $this->error($field, 'import.error.invalid_date', $text),
            FieldType::Decimal => $this->decimal($field, $text, $options),
            FieldType::Integer => $this->integer($field, $text),
            FieldType::Boolean => $this->boolean($field, $text, $valueMap),
            FieldType::Choice => $this->choice($field, $text, $valueMap),
            FieldType::Country => $this->country($field, $text, $valueMap),
            FieldType::Identifier => mb_strtoupper(Text::squash($text)),
        };
    }

    private function email(FieldDefinition $field, string $text): string|RowError
    {
        return false === filter_var($text, \FILTER_VALIDATE_EMAIL) ? $this->error($field, 'import.error.invalid_email', $text) : $text;
    }

    /**
     * @return list<string>|RowError|null
     */
    private function emailList(FieldDefinition $field, string $text): array|RowError|null
    {
        $addresses = [];
        preg_match_all('/[^,;\s]+/u', $text, $found);
        foreach ($found[0] as $address) {
            if (false === filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                return $this->error($field, 'import.error.invalid_email', $address);
            }
            $addresses[$address] = $address;
        }

        return [] === $addresses ? null : array_values($addresses);
    }

    private function phone(FieldDefinition $field, string $text): string|RowError
    {
        $digits = preg_match_all('/\d/', $text);
        if (1 !== preg_match('/^\+?[\d\s().\/-]+$/u', $text) || $digits < 5 || $digits > 20) {
            return $this->error($field, 'import.error.invalid_phone', $text);
        }

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private function decimal(FieldDefinition $field, string $text, NormalisationOptions $options): string|RowError
    {
        $number = Text::squash($text);
        // The other separator is accepted only as thousands grouping: 1.234.567,89 yes, 12.50 no.
        $pattern = DecimalSeparator::Comma === $options->decimalSeparator
            ? '/^([+-]?)(\d+|[1-9]\d{0,2}(?:\.\d{3})+)(?:,(\d+))?$/'
            : '/^([+-]?)(\d+|[1-9]\d{0,2}(?:,\d{3})+)(?:\.(\d+))?$/';
        if (1 !== preg_match($pattern, $number, $parts)) {
            return $this->error($field, 'import.error.invalid_decimal', $text);
        }
        $whole = str_replace(['.', ','], '', $parts[2]);

        return ('-' === $parts[1] ? '-' : '').$whole.(isset($parts[3]) ? '.'.$parts[3] : '');
    }

    private function integer(FieldDefinition $field, string $text): int|RowError
    {
        $number = preg_replace('/^([+-]?)0+(?=\d)/', '$1', Text::squash($text)) ?? $text;
        $value = filter_var($number, \FILTER_VALIDATE_INT);

        return false === $value ? $this->error($field, 'import.error.invalid_integer', $text) : $value;
    }

    /**
     * @param array<array-key, mixed> $valueMap
     */
    private function boolean(FieldDefinition $field, string $text, array $valueMap): bool|RowError
    {
        $word = mb_strtolower($text);
        if (\in_array($word, self::TRUE_WORDS, true)) {
            return true;
        }
        if (\in_array($word, self::FALSE_WORDS, true)) {
            return false;
        }
        $mapped = $this->mapped($valueMap, $text);

        return \is_bool($mapped) ? $mapped : $this->error($field, 'import.error.unknown_value', $text);
    }

    /**
     * @param array<array-key, mixed> $valueMap
     */
    private function choice(FieldDefinition $field, string $text, array $valueMap): mixed
    {
        $mapped = $this->mapped($valueMap, $text);
        if (null !== $mapped) {
            return \is_string($mapped) && \in_array($mapped, $field->choices, true) ? $mapped : $this->error($field, 'import.error.unknown_value', $text);
        }
        foreach ($field->choices as $choice) {
            if (mb_strtolower($choice) === mb_strtolower($text)) {
                return $choice;
            }
        }

        $named = $field->choiceNames[HeaderName::normalise($text)] ?? null;

        return $named ?? $this->error($field, 'import.error.unknown_value', $text);
    }

    /**
     * @param array<array-key, mixed> $valueMap
     */
    private function country(FieldDefinition $field, string $text, array $valueMap): mixed
    {
        $mapped = $this->mapped($valueMap, $text);
        if (null !== $mapped) {
            return \is_string($mapped) && Countries::supports($mapped) ? $mapped : $this->error($field, 'import.error.unknown_value', $text);
        }

        return Countries::find($text) ?? $this->error($field, 'import.error.unknown_value', $text);
    }

    /**
     * What the user decided the text means; the keys of the map may carry stray white space.
     *
     * @param array<array-key, mixed> $valueMap
     */
    private function mapped(array $valueMap, string $text): mixed
    {
        foreach ($valueMap as $key => $value) {
            if (Text::clean((string) $key) === $text) {
                return $value;
            }
        }

        return null;
    }

    private function error(FieldDefinition $field, string $messageKey, string $value): RowError
    {
        return new RowError($field->key, $messageKey, ['value' => $value]);
    }
}
