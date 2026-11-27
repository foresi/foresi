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
use Foresi\Shared\Application\View\ViewRules;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;

/**
 * The working display of a view with what the person last used on it (spec D-display-3):
 * the columns or their widths they change are kept on the server for them alone, and come back
 * when they open the view again without any in the address. The address wins for what it
 * names, the rest stays as remembered; naming the view's own (as Reset does with empty
 * widths=) forgets them.
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
     * @param string                  $columns  the list's key in the column registry
     * @param array<string, mixed>    $context  the list's context, as ListColumnRegistry::display() takes it
     * @param string                  $list     the list's key among the saved views' lists
     * @param array<array-key, mixed> $query    the list's query
     * @param list<string>            $groups   what the list can group by, its default first (WorkingDisplay::read())
     * @param ListDefinition|null     $filters  the list's filter fields, whose quick filters the view pins; null for a list without
     * @param bool                    $calendar whether the list can be laid out as a calendar (WorkingDisplay::read())
     */
    public function read(string $columns, array $context, string $list, ListView $view, array $query, array $groups = [], ?ListDefinition $filters = null, bool $calendar = false): WorkingState
    {
        $pins = $filters instanceof ListDefinition ? self::pinsOf($filters) : null;
        $user = $this->actor->userId();
        $named = [] !== array_intersect_key($query, array_flip(WorkingDisplay::KEYS));
        if (null === $user) {
            return $this->display->read($columns, $context, $view->display, $query, $groups, $pins, $calendar);
        }
        $stored = null === $view->id ? null : $this->states->display($view->id, $user);
        $remembered = $named ? null : $stored;
        if ($named && null !== $stored && '' !== ($query[WorkingDisplay::WIDTHS] ?? null)) {
            // What the address leaves out stays as remembered: the Settings sheet sends no widths, and a
            // dragged column sends its widths alone, which would otherwise show the columns hidden again.
            // Reset names the view's own with empty widths, and forgets it all.
            $query += $this->query($stored, $calendar);
        }
        $working = $this->display->read($columns, $context, $view->display, null === $remembered ? $query : $this->query($remembered, $calendar) + $query, $groups, $pins, $calendar);
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
     * columns in order with their widths, what it groups by and the quick filters it pins.
     *
     * @param array<array-key, mixed> $display
     *
     * @return array<string, string>
     */
    private function query(array $display, bool $calendar): array
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
        $group = $display['group_by'] ?? null;

        $pinned = $display['pinned'] ?? null;
        $layout = $display['layout'] ?? null;
        $weekends = $display['weekends'] ?? null;

        // Empty widths are the view's own.
        return [WorkingDisplay::COLUMNS => implode(',', $keys), WorkingDisplay::WIDTHS => RegistryWorkingDisplay::encodeWidths($widths)]
            + (\is_string($group) ? [WorkingDisplay::GROUP => $group] : [])
            + (\is_array($pinned) ? [WorkingDisplay::PINNED => implode(',', array_filter($pinned, \is_string(...)))] : [])
            // On a list with a calendar it names its layout and weekends either way, so a remembered table wins over a view's calendar.
            + ($calendar ? [WorkingDisplay::LAYOUT => \is_string($layout) ? $layout : ViewRules::LAYOUT_LIST, WorkingDisplay::WEEKENDS => false === $weekends ? '0' : '1'] : []);
    }
}
