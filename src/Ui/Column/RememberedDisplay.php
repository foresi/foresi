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
        $working = $this->display->read($columns, $context, $view->display, null === $remembered ? $query : $this->query($remembered) + $query, $density);
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
     * The address of a remembered display payload, which WorkingDisplay wrote: its
     * columns in order and its density.
     *
     * @param array<array-key, mixed> $display
     *
     * @return array<string, string>
     */
    private function query(array $display): array
    {
        $keys = [];
        foreach ((array) ($display['columns'] ?? []) as $column) {
            if (\is_array($column) && \is_string($column['key'] ?? null)) {
                $keys[] = $column['key'];
            }
        }
        $density = $display['density'] ?? null;

        // An empty density is the view's own.
        return [WorkingDisplay::COLUMNS => implode(',', $keys), WorkingDisplay::DENSITY => \is_string($density) ? $density : ''];
    }
}
