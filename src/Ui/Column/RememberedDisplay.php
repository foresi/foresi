<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Column;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Application\View\SavedViewUserStates;

/**
 * The working display of a view with what the person last used on it (spec D-display-3):
 * columns or a density they change are kept on the server for them alone, and come back
 * when they open the view again without any in the address. The address wins when it
 * names any; naming the view's own (as Reset does with an empty cols=) forgets them.
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
     */
    public function read(string $columns, array $context, string $list, ListView $view, array $query, string $density = ListDisplay::COMFORTABLE): WorkingState
    {
        $user = $this->actor->userId();
        $named = \array_key_exists(WorkingDisplay::COLUMNS, $query) || \array_key_exists(WorkingDisplay::DENSITY, $query);
        if (null === $user) {
            return $this->display->read($columns, $context, $view->display, $query, $density);
        }
        $remembered = $named || null === $view->id ? null : $this->states->display($view->id, $user);
        $working = $this->display->read($columns, $context, $view->display, null === $remembered ? $query : self::query($remembered) + $query, $density);
        if ($named) {
            if ($working->modified && \is_array($working->payload)) {
                // A shipped view nobody stored yet is stored first, so the state has its id.
                $this->states->remember($view->id ?? $this->views->get($view->ref($list))->id(), $user, $working->payload);
            } elseif (null !== $view->id) {
                $this->states->forget($view->id, $user);
            }
        }

        return $working;
    }

    /**
     * The address of a remembered display payload: its columns in order and its density.
     *
     * @param array<array-key, mixed> $display
     *
     * @return array<string, string>
     */
    public static function query(array $display): array
    {
        $keys = [];
        foreach (\is_array($display['columns'] ?? null) ? $display['columns'] : [] as $column) {
            $key = \is_array($column) ? ($column['key'] ?? null) : $column;
            if (\is_string($key)) {
                $keys[] = $key;
            }
        }
        $density = $display['density'] ?? null;

        return array_filter([
            WorkingDisplay::COLUMNS => [] === $keys ? null : implode(',', $keys),
            WorkingDisplay::DENSITY => \is_string($density) ? $density : null,
        ], static fn (?string $value): bool => null !== $value);
    }
}
