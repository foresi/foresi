<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\View;

use Foresi\Ui\Column\RegistryWorkingDisplay;
use Foresi\Ui\Contract\WorkingDisplay;

/**
 * The quick filters of a list that keeps no saved views, such as the board or the week of time:
 * the page pins its own by default, and its Display sheet pins others in the address, as
 * pins=<names in order> (empty for none), as WorkingDisplay reads them for a list with views.
 * Nothing is stored; a link keeps them.
 */
final readonly class QuickPins
{
    /**
     * @param list<string>                                          $pinned   the names pinned, in order
     * @param list<array{key: string, label: string, pinned: bool}> $pinnable what the Display sheet offers, those pinned first
     * @param array<string, string>                                 $query    pins, only when it differs from the page's own
     */
    private function __construct(public array $pinned, public array $pinnable, public bool $modified, public array $query)
    {
    }

    /**
     * @param array<array-key, mixed> $query   the page's query
     * @param array<string, string>   $fields  the label of each filter one can pin, by name, in order
     * @param list<string>            $default the names the page pins by default
     */
    public static function read(array $query, array $fields, array $default): self
    {
        $names = array_keys($fields);
        $own = RegistryWorkingDisplay::pins($default, $names) ?? [];
        $pinned = \array_key_exists(WorkingDisplay::PINNED, $query) ? (RegistryWorkingDisplay::pins($query[WorkingDisplay::PINNED], $names) ?? []) : $own;
        $pinnable = [];
        foreach ($pinned as $name) {
            $pinnable[$name] = ['key' => $name, 'label' => $fields[$name], 'pinned' => true];
        }
        foreach ($fields as $name => $label) {
            $pinnable[$name] ??= ['key' => $name, 'label' => $label, 'pinned' => false];
        }
        $modified = $pinned !== $own;

        return new self($pinned, array_values($pinnable), $modified, $modified ? [WorkingDisplay::PINNED => implode(',', $pinned)] : []);
    }
}
