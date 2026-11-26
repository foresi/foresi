<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

/**
 * A field of a step's input may take the record an earlier step of the same
 * suggestion makes: {"$step": n, "field": "id"}, n its 1-based position, as the
 * whole value of the field, also inside a list or an object. Only the id of the
 * record can be taken.
 *
 * When proposed or edited, a reference is checked (validate()) and stands in
 * as a fixed placeholder id, so the action's input can be checked before
 * anything exists. When the step runs, it becomes the record the earlier step
 * made (resolve()).
 */
final class StepReferences
{
    public const string STEP = '$step';
    public const string FIELD = 'field';
    /** The id a reference stands as while its input is checked: a valid UUIDv7 that names nothing. */
    public const string PLACEHOLDER = '00000000-0000-7000-8000-000000000000';

    private function __construct()
    {
    }

    /**
     * Whether the value is meant as a reference: an object with "$step".
     */
    public static function isReference(mixed $value): bool
    {
        return \is_array($value) && \array_key_exists(self::STEP, $value);
    }

    /**
     * The input with every reference checked and replaced by the placeholder.
     *
     * @param array<mixed> $input    the step's input by the fields' wire names
     * @param int          $position the 1-based position of the step the input is of
     * @param string       $path     the path of the input in the call, such as suggestions.0.steps.1.input
     *
     * @return array<mixed>
     *
     * @throws InboxRefused invalid_reference for a reference to this step, a later one or none; invalid_input for one of another field than id
     */
    public static function validate(array $input, int $position, string $path): array
    {
        return self::walk($input, $path, static function (array $reference, string $field) use ($position): string {
            $step = self::stepOf($reference, $field);
            if ($step >= $position) {
                throw InboxRefused::invalidReference($field);
            }

            return self::PLACEHOLDER;
        });
    }

    /**
     * The input with every reference replaced by the id of the record its step made.
     *
     * @param array<mixed>       $input   as stored, references included
     * @param array<int, string> $records the record ids of the done steps, by position (Suggestion::doneRecords())
     * @param string             $path    the path of the input for a refusal, such as input
     *
     * @return array<mixed>
     *
     * @throws InboxRefused reference_unresolved for a step that made no record; invalid_reference, invalid_input for a malformed one
     */
    public static function resolve(array $input, array $records, string $path = 'input'): array
    {
        return self::walk($input, $path, static fn (array $reference, string $field): string => $records[self::stepOf($reference, $field)] ?? throw InboxRefused::referenceUnresolved($field));
    }

    /**
     * The positions of the steps the input refers to, each once, in their order.
     *
     * @param array<mixed> $input
     *
     * @return list<int>
     */
    public static function positions(array $input): array
    {
        $positions = [];
        self::walk($input, 'input', static function (array $reference, string $field) use (&$positions): string {
            $positions[] = self::stepOf($reference, $field);

            return self::PLACEHOLDER;
        });
        $positions = array_values(array_unique($positions));
        sort($positions);

        return $positions;
    }

    /**
     * @param array<mixed>                           $value
     * @param \Closure(array<mixed>, string): string $replace
     *
     * @return array<mixed>
     */
    private static function walk(array $value, string $path, \Closure $replace): array
    {
        foreach ($value as $key => $item) {
            $field = $path.'.'.self::segment($key);
            if (self::isReference($item)) {
                \assert(\is_array($item));
                $value[$key] = $replace($item, $field);
            } elseif (\is_array($item)) {
                $value[$key] = self::walk($item, $field, $replace);
            }
        }

        return $value;
    }

    /**
     * @param array<mixed> $reference
     *
     * @throws InboxRefused
     */
    private static function stepOf(array $reference, string $field): int
    {
        $keys = array_keys($reference);
        sort($keys);
        if ([self::STEP, self::FIELD] !== $keys || 'id' !== $reference[self::FIELD]) {
            throw InboxRefused::invalidInput($field, 'A reference is {"$step": n, "field": "id"}: only the id of the record an earlier step makes can be taken.');
        }
        $step = $reference[self::STEP];
        if (!\is_int($step) || $step < 1) {
            throw InboxRefused::invalidReference($field);
        }

        return $step;
    }

    /**
     * A key of the path as a refusal names a field: contact_id is contactId. A key that is no
     * field name (odd characters, or longer than 64) is named * rather than echoed back.
     */
    public static function segment(int|string $key): string
    {
        if (\is_int($key)) {
            return (string) $key;
        }

        return 1 === preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $key) ? lcfirst(str_replace('_', '', ucwords($key, '_'))) : '*';
    }
}
