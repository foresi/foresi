<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Column;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Application\View\SavedViewUserStates;
use Foresi\Ui\Contract\ListDisplay;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;

/**
 * The working display of a view with what the person last used on it (spec D-display-3):
 * columns, their widths or a density they change are kept on the server for them alone, and come back
 * when they open the view again without any in the address. The address wins for what it
 * names, the rest stays as remembered; naming the view's own (as Reset does with an empty
 * density=) forgets them.
 */
final readonly class RememberedDisplay
{
    public function __construct(
        private WorkingDisplay $display,
        private SavedViewUserStates $states,
        private SavedViews $views,
        private ActorRole $actor,
    ) {
    }

    /**
     * @param string                  $columns the list's key in the column registry
     * @param array<string, mixed>    $context the list's context, as ListColumnRegistry::display() takes it
     * @param string                  $list    the list's key among the saved views' lists
     * @param array<array-key, mixed> $query   the list's query
     * @param list<string>            $groups  what the list can group by, its default first (WorkingDisplay::read())
     * @param ListDefinition|null     $filters the list's filter fields, whose quick filters the view pins; null for a list without
     */
    public function read(string $columns, array $context, string $list, ListView $view, array $query, string $density = ListDisplay::COMFORTABLE, array $groups = [], ?ListDefinition $filters = null): WorkingState
    {
        $pins = $filters instanceof ListDefinition ? self::pinsOf($filters) : null;
        $user = $this->actor->userId();
        $named = [] !== array_intersect_key($query, array_flip(WorkingDisplay::KEYS));
        if (null === $user) {
            return $this->display->read($columns, $context, $view->display, $query, $density, $groups, $pins);
        }
        $stored = null === $view->id ? null : $this->states->display($view->id, $user);
        $remembered = $named ? null : $stored;
        if ($named && null !== $stored && '' !== ($query[WorkingDisplay::DENSITY] ?? null)) {
            // What the address leaves out stays as remembered: the Settings sheet sends no widths, and a
            // dragged column sends its widths alone, which would otherwise show the columns hidden again.
            // Reset names the view's own with an empty density, and forgets it all.
            $query += $this->query($stored);
        }
        $working = $this->display->read($columns, $context, $view->display, null === $remembered ? $query : $this->query($remembered) + $query, $density, $groups, $pins);
        if ($named) {
            if ($working->modified) {
                // A shipped view nobody stored yet is stored first, so the state has its id.
                $this->states->remember($this->views->get($view->ref($list))->id(), $user, $working->payload ?? []);
            } elseif (null !== $view->id) {
                $this->states->forget($view->id, $user);
            }
        }

        return $working;
    }

    /**
     * What WorkingDisplay takes of a list's quick filters: the fields one can pin and those the
     * list pins by default.
     *
     * @return array{fields: list<string>, default: list<string>}
     */
    public static function pinsOf(ListDefinition $list): array
    {
        return ['fields' => array_keys($list->fields), 'default' => $list->pinned];
    }

    /**
     * The address of a remembered display payload, which WorkingDisplay wrote: its
     * columns in order with their widths, its density, what it groups by and the quick filters it pins.
     *
     * @param array<array-key, mixed> $display
     *
     * @return array<string, string>
     */
    private function query(array $display): array
    {
        $keys = [];
        $widths = [];
        foreach ((array) ($display['columns'] ?? []) as $column) {
            if (\is_array($column) && \is_string($column['key'] ?? null)) {
                $keys[] = $column['key'];
                if (\is_int($column['width'] ?? null) && $column['width'] > 0) {
                    $widths[$column['key']] = $column['width'];
                }
            }
        }
        $density = $display['density'] ?? null;
        $group = $display['group_by'] ?? null;

        // An empty density is the view's own.
        $pinned = $display['pinned'] ?? null;

        // Empty widths are the view's own.
        return [WorkingDisplay::COLUMNS => implode(',', $keys), WorkingDisplay::DENSITY => \is_string($density) ? $density : '', WorkingDisplay::WIDTHS => RegistryWorkingDisplay::encodeWidths($widths)]
            + (\is_string($group) ? [WorkingDisplay::GROUP => $group] : [])
            + (\is_array($pinned) ? [WorkingDisplay::PINNED => implode(',', array_filter($pinned, \is_string(...)))] : []);
    }
}
