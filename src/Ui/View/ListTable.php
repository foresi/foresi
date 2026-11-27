<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\View;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Application\View\ListTablePage;
use Foresi\Shared\Application\View\ListTables;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\RowConditions;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A short table of records a page holds in memory, such as the series of a firm or the
 * labels of the organisation, as a list with views (docs/design/list-pages.md, rules 8 and 9):
 * its views as tabs, the search, the conditions of its filter sheet checked on the rows,
 * its Settings sheet with the columns the person keeps, and widths they drag, all of it in
 * the table's slot of the page's address. The records keep their own order: no column sorts.
 * The filter sheet is the caller's to add (ListTablePage::with()), as it lives in the shared
 * infrastructure.
 *
 * @implements ListTables<WorkingState>
 */
final readonly class ListTable implements ListTables
{
    private const int MAX_SEARCH = 100;

    public function __construct(
        private ListViews $listViews,
        private ActorRole $actorRole,
        private RememberedDisplay $rememberedDisplay,
        private ViewBar $viewBar,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Where the builder's Apply (spec D11) leads: the table's address with its rows read into
     * the working filter, which is left out when it is the view's own; null when the request is
     * no Apply of this table.
     *
     * @return array<array-key, mixed>|null the page's whole query
     */
    public function built(ListSlot $slot, ListDefinition $list): ?array
    {
        $input = $slot->input();
        if (!$input->has(FilterRows::MARKER)) {
            return null;
        }
        $view = ListViews::chosen($this->listViews->of($list, $this->actorRole->userId()), $input->getString('view'));
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'view' => true]);

        return $slot->with(array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $list), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value));
    }

    /**
     * @template T of object
     *
     * @param string                                          $columns  the list's key in the column registry
     * @param string                                          $route    the page's route, which has no parameters
     * @param string                                          $id       the table's id among the page's, for its filter and columns sheets
     * @param list<T>                                         $records  every record the table can show, in their order
     * @param \Closure(T, WorkingState): array<string, mixed> $row      a row of Ui:DataTable
     * @param \Closure(T, string): mixed                      $valueOf  the value of a field of the list on a record, as RowConditions compares it
     * @param \Closure(T): string                             $searched the text the search looks in
     * @param string|null                                     $hidden   a boolean field whose records show only while the filter names it, as a trash view does
     * @param \Closure(ViewFilter): array<string, mixed>|null $context  the columns' context of the filter shown, such as the trash
     * @param \Closure(ListView): bool|null                   $offered  which views the person gets as tabs, such as the invitations only the owner keeps
     */
    public function of(ListSlot $slot, ListDefinition $list, string $columns, string $route, string $id, array $records, \Closure $row, \Closure $valueOf, \Closure $searched, string $searchLabel, ?string $hidden = null, ?\Closure $context = null, ?\Closure $offered = null): ListTablePage
    {
        $input = $slot->input();
        $views = $this->listViews->of($list, $this->actorRole->userId());
        if ($offered instanceof \Closure) {
            $views = array_values(array_filter($views, $offered));
        }
        $view = ListViews::chosen($views, $input->getString('view'));
        $shown = WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter;
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $text = trim(mb_substr($input->getString('q'), 0, self::MAX_SEARCH));
        $sort = ViewState::sortParam($view->sort);
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'q' => '' === $text ? null : $text, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        $working = $this->rememberedDisplay->read($columns, $context instanceof \Closure ? $context($shown) : [], $list->key, $view, $slot->query(), filters: $list);
        $query += $working->query;
        $url = fn (array $own): string => $this->urls->generate($route, $slot->with($own));
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, $sort, $working, $route, '' === $text ? [] : ['q' => $text], $slot);
        $state = array_diff_key($query, array_flip(WorkingDisplay::KEYS));
        $today = new \DateTimeImmutable('today');
        // The records a filter keeps: those of the hidden field only while it names it.
        $matching = static fn (ViewFilter $filter): array => RowConditions::filter(null === $hidden || [] !== $filter->on($hidden) ? $records : array_values(array_filter($records, static fn (object $record): bool => true !== $valueOf($record, $hidden))), $filter, $list, $valueOf, $today);
        $records = $matching($shown);
        if ('' !== $text) {
            $records = array_values(array_filter($records, static fn (object $record): bool => false !== mb_stripos($searched($record), $text)));
        }
        [$primary, $overflow] = ListViews::placed($views);
        $tab = fn (ListView $one): array => [
            'label' => $one->translated ? $this->translator->trans($one->name) : $one->name,
            // A view opens with its own filter and columns (spec D10).
            'href' => $url(['view' => $one->isAll() ? null : $one->key] + array_diff_key($query, ['view' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $one->key === $view->key,
            'modified' => $bar['viewBar']['modified'] && $one->key === $view->key,
            'private' => $one->private,
            // A count where the view shows one (list-pages.md, rule 7): its own filter, without the search.
            'count' => $one->showCount ? \count($matching($one->filter)) : null,
        ];

        return new ListTablePage([
            'id' => $id,
            'view' => $view->isAll() ? '' : $view->key,
            'search' => ['name' => $slot->field('q'), 'value' => $text, 'label' => $searchLabel],
            // What the filter bar's forms carry: this table's own state under its names, the other tables' as it is.
            'keep' => $this->fields($slot, array_diff_key($query, ['q' => true])),
            ...$bar,
            'tabs' => array_map($tab, $primary),
            'moreViews' => array_map($tab, $overflow),
            'columns' => $working->display->table($sort, fn (string $next): string => $url(['sort' => $next] + $query)),
            'rows' => array_map(static fn (object $record): array => $row($record, $working), $records),
            'widthsParam' => $slot->field(WorkingDisplay::WIDTHS),
            'picker' => [
                'keep' => $state,
                'columns' => $working->picker(),
                // Empty widths name the view's own display, which forgets the one remembered (D-display-3);
                // how tall the rows are is the person's density (Profile, Appearance), no state of the list.
                'resetHref' => $url($state + [WorkingDisplay::WIDTHS => '']),
                'modified' => $working->modified,
                'slot' => $slot,
            ],
            'filtered' => '' !== $text || null !== $filterParam,
            'resetHref' => $url(['view' => $view->isAll() ? null : $view->key]),
        ], $shown, fn (ViewFilter $next): string => $url([WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), $working->pinned, $view);
    }

    /**
     * A table's own state as the fields of its forms, and the other tables' state.
     *
     * @param array<string, string> $own
     *
     * @return array<string, string>
     */
    private function fields(ListSlot $slot, array $own): array
    {
        $fields = [];
        foreach ($own as $name => $value) {
            $fields[$slot->field($name)] = $value;
        }

        return $fields + $slot->kept();
    }
}
