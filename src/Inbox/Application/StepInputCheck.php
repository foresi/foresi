<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Shared\Infrastructure\Operation\InputMapper;
use Foresi\Shared\Infrastructure\Operation\InvalidInput;

/**
 * Whether the input of a suggestion's step fits its action, without running
 * it: proposed, or edited by the person before they accept. The input names
 * the fields by their wire names and leaves out the item and mark_done, which
 * the run adds; its references to earlier steps (StepReferences) stand as a
 * placeholder id while the action's input mapper checks it.
 *
 * A refusal names the field by its path in the call, such as
 * suggestions.0.steps.1.input.projectId (input.project_id on the wire), and never quotes the input.
 */
final class StepInputCheck
{
    /** The fields of every action's input that a step leaves out: the run sets them. */
    public const array RUN_FIELDS = ['inbox_item_id' => 'inboxItemId', 'mark_done' => 'markDone'];

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $input    by the fields' wire names
     * @param int          $position the 1-based position of the step in its suggestion
     * @param string       $path     the path of the input in the call, such as suggestions.0.steps.1.input
     *
     * @throws InboxRefused invalid_input, invalid_reference
     */
    public static function check(DeclaredAction $action, string $itemId, array $input, int $position, string $path): void
    {
        if ([] !== $input && array_is_list($input)) {
            throw InboxRefused::invalidInput($path, 'The input of a step is an object of the action\'s fields.');
        }
        foreach (self::RUN_FIELDS as $wire => $name) {
            if (\array_key_exists($wire, $input) || \array_key_exists($name, $input)) {
                throw InboxRefused::invalidInput($path.'.'.$name, 'A step leaves out inbox_item_id and mark_done: accepting it runs the action on this item and leaves marking it done to the last decision.');
            }
        }
        self::refuseNul($input, $path);
        $sent = StepReferences::validate($input, $position, $path);
        try {
            InputMapper::map($action->definition, $sent + ['inbox_item_id' => $itemId, 'mark_done' => false]);
        } catch (InvalidInput $invalid) {
            throw InboxRefused::invalidInput($path.'.'.self::field($invalid->wire), \sprintf('The input of step %d does not fit its action; compare it with the input_schema of the action in foresi_get_inbox_item.', $position));
        }
    }

    /**
     * A text that holds a NUL character, which the database cannot store.
     *
     * @throws InboxRefused invalid_input
     */
    private static function refuseNul(mixed $value, string $path): void
    {
        if (\is_string($value) && str_contains($value, "\0")) {
            throw InboxRefused::invalidInput($path, 'A text may not hold a NUL character.');
        }
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                if (\is_string($key) && str_contains($key, "\0")) {
                    throw InboxRefused::invalidInput($path, 'A field name may not hold a NUL character.');
                }
                self::refuseNul($item, $path.'.'.StepReferences::segment($key));
            }
        }
    }

    /**
     * The field an input mapper refused, by its path segments, each named as a field or *.
     */
    private static function field(string $wire): string
    {
        return implode('.', array_map(StepReferences::segment(...), explode('.', $wire)));
    }
}
