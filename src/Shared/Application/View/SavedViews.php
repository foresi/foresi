<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Domain\View\Visibility;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reading and writing saved views as a person may (spec D6), for the list
 * screens, the REST API and MCP alike:
 * - only the staff (owners and members) work with views; guests never do;
 * - a private view is its owner's alone;
 * - anyone of the staff may make a view, private or shared;
 * - a shared view is changed or deleted by its creator or an owner;
 * - a view a module ships is changed or hidden by owners only and never deleted;
 *   "restore defaults" brings it back to what the module declares.
 *
 * A view a module ships that app:views:sync has not stored yet is stored here
 * the first time its list is read, so it has an id to be named by. Every write
 * is in the audit log, which the Audit module keeps for SavedView.
 */
final readonly class SavedViews
{
    public function __construct(
        private SavedViewStore $store,
        private ListDefinitions $lists,
        private ActorRole $actor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The views of a list you can see, hidden ones too, in their order.
     *
     * @return list<SavedView>
     *
     * @throws SavedViewRefused
     */
    public function visible(string $listKey): array
    {
        $user = $this->staff();
        $list = $this->list($listKey, 'listKey');
        $this->storeDefaults($list);

        return array_values(array_filter($this->store->ofList($list->key), fn (SavedView $view): bool => $this->sees($view, $user)));
    }

    /**
     * A view you can see, by its id or the system key of a view a module ships,
     * such as "invoicing.invoices.overdue".
     *
     * @throws SavedViewRefused
     */
    public function get(string $id): SavedView
    {
        $user = $this->staff();
        $view = $this->store->get($id) ?? $this->shipped($id);
        if (!$view instanceof SavedView || !$this->sees($view, $user)) {
            throw SavedViewRefused::notFound();
        }

        return $view;
    }

    /**
     * The checked filter of a view, as its list compiles it.
     *
     * @throws SavedViewRefused when its list is gone or no longer accepts it
     */
    public function filterOf(SavedView $view): ViewFilter
    {
        $list = $this->list($view->listKey(), 'viewId');
        try {
            return ViewFilter::parse($view->filters(), $list);
        } catch (InvalidFilter $invalid) {
            throw SavedViewRefused::conflict('view_outdated', \sprintf('The view filters on what its list no longer has (%s); change its conditions.', $invalid->getMessage()));
        }
    }

    public function list(string $key, string $field = 'listKey'): ListDefinition
    {
        try {
            return $this->lists->get($key);
        } catch (\InvalidArgumentException) {
            throw SavedViewRefused::invalid('list_unknown', $field, \sprintf('There is no list "%s". Find the lists with foresi_list_lists.', $key));
        }
    }

    /**
     * @throws SavedViewRefused
     */
    public function create(CreateViewInput $input): SavedView
    {
        $user = $this->staff();
        $list = $this->list($input->listKey);
        $this->storeDefaults($list);
        $filters = $this->filters($input->conditions, $list);
        $sort = $this->checked(static fn (): array => ViewRules::sort($input->sort));
        $display = $this->checked(static fn (): ?array => ViewRules::display($input->columns, $input->density, $input->groupBy));
        $name = $this->name($input->name);
        $positions = array_map(static fn (SavedView $view): int => $view->position(), $this->store->ofList($list->key));
        $view = SavedView::made(
            $list->key,
            $user,
            $name,
            $this->visibility($input->visibility),
            $filters,
            $sort,
            $display,
            $input->showCount,
            $this->placement($input->placement),
            [] === $positions ? 1 : max($positions) + 1,
            $this->now(),
        );
        $this->store->add($view);
        $this->store->flush();

        return $view;
    }

    /**
     * @throws SavedViewRefused
     */
    public function update(UpdateViewInput $input): SavedView
    {
        $view = $this->get($input->viewId);
        $user = $this->mayChange($view);
        $list = $this->list($view->listKey(), 'viewId');
        $now = $this->now();
        if (!$input->hidden instanceof Omitted && $input->hidden !== $view->isHidden()) {
            if (null !== $view->ownerId()) {
                throw SavedViewRefused::invalid('hide_own_view', 'hidden', 'Only a view a module ships is hidden; make your own view private or delete it.');
            }
        }
        if (!$input->visibility instanceof Omitted) {
            $visibility = $this->visibility($input->visibility);
            if ($visibility !== $view->visibility()) {
                if (null === $view->ownerId()) {
                    throw SavedViewRefused::invalid('default_view_shared', 'visibility', 'A view a module ships stays shared; an owner can hide it instead.');
                }
                if (Visibility::Shared === $visibility && $view->ownerId() !== $user) {
                    throw SavedViewRefused::forbidden('Only its creator shares a private view.');
                }
                // Made private by an owner who did not create it, it stays its creator's.
                $view->changeVisibility($visibility, $user, $now);
            }
        }
        $view->edit(
            $user,
            $now,
            name: $input->name instanceof Omitted ? null : $this->name($input->name),
            filters: $input->conditions instanceof Omitted ? null : $this->filters($input->conditions, $list),
            sort: $input->sort instanceof Omitted ? null : $this->checked(static fn (): array => ViewRules::sort($input->sort)),
            showCount: $input->showCount instanceof Omitted ? null : $input->showCount,
            placement: $input->placement instanceof Omitted ? null : $this->placement($input->placement),
            position: $input->position instanceof Omitted ? null : $input->position,
            hidden: $input->hidden instanceof Omitted ? null : $input->hidden,
        );
        if (!$input->columns instanceof Omitted || !$input->density instanceof Omitted || !$input->groupBy instanceof Omitted) {
            $was = $view->display();
            $columns = $input->columns instanceof Omitted ? (\is_array($was['columns'] ?? null) ? array_values($was['columns']) : null) : $input->columns;
            $density = $input->density instanceof Omitted ? (\is_string($was['density'] ?? null) ? $was['density'] : null) : $input->density;
            $groupBy = $input->groupBy instanceof Omitted ? (\is_string($was['group_by'] ?? null) ? $was['group_by'] : null) : $input->groupBy;
            $view->redisplay($this->checked(static fn (): ?array => ViewRules::display($columns, $density, $groupBy)), $user, $now);
        }
        $this->store->flush();

        return $view;
    }

    /**
     * @return string the id of the deleted view
     *
     * @throws SavedViewRefused
     */
    public function delete(DeleteViewInput $input): string
    {
        $view = $this->get($input->viewId);
        if (null !== $view->systemKey()) {
            throw SavedViewRefused::conflict('default_view', 'A view a module ships is not deleted; an owner can hide it, or restore the default views of its list.');
        }
        $this->mayChange($view);
        $id = $view->id();
        $this->store->remove($view);
        $this->store->flush();

        return $id;
    }

    /**
     * @return list<SavedView> the views the module ships on the list, as it declares them
     *
     * @throws SavedViewRefused
     */
    public function restoreDefaults(RestoreDefaultViewsInput $input): array
    {
        $this->staff();
        if (!$this->actor->isOwner()) {
            throw SavedViewRefused::forbidden('Only owners restore the default views of a list.');
        }
        $list = $this->list($input->listKey);
        $this->storeDefaults($list);
        $now = $this->now();
        $restored = [];
        foreach ($list->defaults as $default) {
            $view = $this->store->bySystemKey($list->systemKey($default));
            if ($view instanceof SavedView) {
                $view->restore($default->name, $default->filter->toArray(), [], $default->showCount, $default->placement, $default->position, $now);
                $restored[] = $view;
            }
        }
        $this->store->flush();

        return $restored;
    }

    /**
     * Whether you may change or delete a view you can see, as the list screens ask
     * before offering Save.
     */
    public function mayEdit(SavedView $view): bool
    {
        try {
            $this->mayChange($view);

            return true;
        } catch (SavedViewRefused) {
            return false;
        }
    }

    /**
     * @return string the id of the person changing it
     *
     * @throws SavedViewRefused
     */
    private function mayChange(SavedView $view): string
    {
        $user = $this->staff();
        if (null === $view->ownerId()) {
            if (!$this->actor->isOwner()) {
                throw SavedViewRefused::forbidden('Only owners change a view a module ships; save your change as a new view instead.');
            }

            return $user;
        }
        if ($view->ownerId() !== $user && (Visibility::Private === $view->visibility() || !$this->actor->isOwner())) {
            throw SavedViewRefused::forbidden('Only its creator and the owners change a shared view; save your change as a new view instead.');
        }

        return $user;
    }

    /**
     * @throws SavedViewRefused
     */
    private function staff(): string
    {
        $user = $this->actor->userId();
        if (null === $user || !$this->actor->isStaff()) {
            throw SavedViewRefused::forbidden('Only the owners and members of the organisation work with saved views.');
        }

        return $user;
    }

    private function sees(SavedView $view, string $user): bool
    {
        return Visibility::Shared === $view->visibility() || $view->ownerId() === $user;
    }

    private function shipped(string $systemKey): ?SavedView
    {
        foreach ($this->lists->all() as $list) {
            if (str_starts_with($systemKey, $list->key.'.') && null !== $list->default(substr($systemKey, \strlen($list->key) + 1))) {
                $this->storeDefaults($list);

                return $this->store->bySystemKey($systemKey);
            }
        }

        return null;
    }

    /**
     * The views the module ships that are not stored yet, stored as
     * app:views:sync would.
     */
    private function storeDefaults(ListDefinition $list): void
    {
        $added = false;
        foreach ($list->defaults as $default) {
            $systemKey = $list->systemKey($default);
            if (!$this->store->bySystemKey($systemKey) instanceof SavedView) {
                $this->store->add(SavedView::shipped($list->key, $systemKey, $default->name, $default->filter->toArray(), [], $default->showCount, $default->placement, $default->position, $this->now()));
                $added = true;
            }
        }
        if ($added) {
            $this->store->flush();
        }
    }

    /**
     * @param list<mixed> $conditions
     *
     * @return array<string, mixed>
     */
    private function filters(array $conditions, ListDefinition $list): array
    {
        return $this->checked(static fn (): array => ViewFilter::parse(['conditions' => $conditions], $list)->toArray());
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > SavedView::NAME_MAX_LENGTH) {
            throw SavedViewRefused::invalid('name_invalid', 'name', \sprintf('A view is named in 1 to %d characters.', SavedView::NAME_MAX_LENGTH));
        }

        return $name;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $check
     *
     * @return T
     */
    private function checked(\Closure $check): mixed
    {
        try {
            return $check();
        } catch (InvalidFilter $invalid) {
            throw SavedViewRefused::invalid('filter_invalid', $invalid->path, $invalid->getMessage());
        }
    }

    private function visibility(string $value): Visibility
    {
        return Visibility::tryFrom($value) ?? throw SavedViewRefused::invalid('visibility_unknown', 'visibility', 'The visibility is private or shared.');
    }

    private function placement(string $value): Placement
    {
        return Placement::tryFrom($value) ?? throw SavedViewRefused::invalid('placement_unknown', 'placement', 'The placement is primary or overflow.');
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
