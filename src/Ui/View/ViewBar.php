<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\View;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What a list's views offer around its tabs (spec D7, D10): whether the view shows changes
 * nobody saved, with Save (only for who may change the view, D6), Save as new view and
 * Reset; and the views Manage views changes. The forms post to SavedViewController.
 */
final readonly class ViewBar
{
    public function __construct(
        private SavedViews $savedViews,
        private ListViews $listViews,
        private ActorRole $actor,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param list<ListView>        $views       the list's views, as ListViews::of() gives them
     * @param string                $sort        the sort the list shows, as in its address
     * @param string                $defaultSort the list's own order, which a view without a sort shows
     * @param array<string, string> $keep        what Reset keeps of the address: the search
     *
     * @return array{viewBar: array{modified: bool, canSave: bool, resetHref: string, list: string, ref: string, filter: string, sort: string, display: string, displayChanged: bool, return: string, base: string}, manageViews: array<string, mixed>}
     */
    public function of(ListDefinition $list, array $views, ListView $view, ViewFilter $working, string $sort, string $defaultSort, WorkingState $display, string $route, array $keep): array
    {
        $workingSort = $sort === ViewState::sortParam($view->sort, $defaultSort) ? $view->sort : ViewState::sortOf($sort === $defaultSort ? '' : $sort);
        $modified = ViewState::isModified($view, $working, $workingSort, $display->modified);
        $here = ['view' => $view->isAll() ? null : $view->key] + $keep;

        return [
            'viewBar' => [
                'modified' => $modified,
                // All has no conditions and is no one's to change (D7): its changes become a new view.
                'canSave' => $modified && !$view->isAll() && $this->mayEdit($view, $list),
                // Everything but the search (D10); an empty density names the view's own display, which forgets the one remembered.
                'resetHref' => $this->urls->generate($route, $here + [WorkingDisplay::DENSITY => '']),
                'list' => $list->key,
                'ref' => $view->ref($list->key),
                'filter' => WorkingFilter::encode($working),
                'sort' => json_encode($workingSort, \JSON_THROW_ON_ERROR),
                'display' => \is_array($display->payload) ? json_encode($display->payload, \JSON_THROW_ON_ERROR) : '',
                'displayChanged' => $display->modified,
                'return' => $this->urls->generate($route, $here),
                'base' => $this->urls->generate($route, $keep),
            ],
            'manageViews' => $this->manage($list, $views, $route, $keep, $this->urls->generate($route, $here)),
        ];
    }

    /**
     * Manage views (spec D6, D7): the tabs and the views in More views in their order, which
     * owners change, and for owners the shipped views they hid, to show again.
     *
     * @param list<ListView>        $views
     * @param array<string, string> $keep
     * @param string                $return the view shown, which a deleted one falls back from to All
     *
     * @return array<string, mixed>
     */
    private function manage(ListDefinition $list, array $views, string $route, array $keep, string $return): array
    {
        [$tabs, $more] = ListViews::placed($views);
        $owner = $this->actor->isOwner();
        $row = fn (ListView $view): array => $this->row($list, $view, $route, $keep);

        $all = $tabs[0];

        return [
            'all' => ['name' => $all->name, 'translated' => $all->translated],
            'tabs' => array_values(array_map($row, array_filter($tabs, static fn (ListView $view): bool => !$view->isAll()))),
            'more' => array_map($row, $more),
            'hidden' => $owner ? array_map($row, $this->listViews->hidden($list)) : [],
            // One order for everyone, set by owners (D7).
            'canReorder' => $owner,
            // At most six tabs, All among them (D7): a seventh waits in More views.
            'maxTabs' => ListViews::MAX_PRIMARY,
            'primaryFull' => \count($tabs) >= ListViews::MAX_PRIMARY,
            'canRestore' => $owner && [] !== $list->defaults,
            'list' => $list->key,
            'return' => $return,
        ];
    }

    /**
     * @param array<string, string> $keep
     *
     * @return array<string, mixed>
     */
    private function row(ListDefinition $list, ListView $view, string $route, array $keep): array
    {
        return [
            'ref' => $view->ref($list->key),
            'key' => $view->key,
            'name' => $view->name,
            'translated' => $view->translated,
            'private' => $view->private,
            'primary' => Placement::Primary === $view->placement,
            'editable' => $this->mayEdit($view, $list),
            // A view a module ships stays shared and is never deleted, only hidden (D6).
            'shipped' => null !== $view->slug,
            'href' => $this->urls->generate($route, ['view' => $view->key] + $keep),
        ];
    }

    private function mayEdit(ListView $view, ListDefinition $list): bool
    {
        try {
            // A shipped view nobody stored yet is stored on the way, and is the owners' to change.
            return $this->savedViews->mayEdit($this->savedViews->get($view->ref($list->key)));
        } catch (SavedViewRefused) {
            return false;
        }
    }
}
