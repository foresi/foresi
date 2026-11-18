<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View\Api;

use Foresi\Shared\Application\View\DateRange;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\ListColumnCatalog;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\View\SavedView;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Saved views and the lists they filter as the REST API and MCP write them (spec
 * D13): the same shape over both. Names and labels a module declares are given
 * in English; a name a person typed is given as typed.
 */
final readonly class ViewJson
{
    /**
     * @param iterable<ListColumnCatalog> $catalogs
     */
    public function __construct(
        private SavedViews $views,
        private TranslatorInterface $translator,
        #[AutowireIterator(ListColumnCatalog::TAG)]
        private iterable $catalogs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function view(SavedView $view): array
    {
        return [
            'id' => $view->id(),
            'list' => $view->listKey(),
            'name' => null === $view->systemKey() ? $view->name() : $this->english($view->name()),
            'system_key' => $view->systemKey(),
            'visibility' => $view->visibility()->value,
            'owner_id' => $view->ownerId(),
            'filters' => $view->filters(),
            'sort' => $view->sort(),
            'display' => $view->display(),
            'show_count' => $view->showCount(),
            'placement' => $view->placement()->value,
            'position' => $view->position(),
            'hidden' => $view->isHidden(),
            'may_edit' => $this->views->mayEdit($view),
            'created_at' => $view->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $view->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * A list as a client builds a filter for it: its fields with their operators
     * and values, and its columns.
     *
     * @return array<string, mixed>
     */
    public function list(ListDefinition $list): array
    {
        return [
            'key' => $list->key,
            'fields' => array_map(fn (Field $field): array => [
                'key' => $field->key,
                'type' => $field->type->value,
                'label' => $this->english($field->label),
                'operators' => $field->operators,
                'options' => $field->options,
            ], array_values($list->fields)),
            'columns' => $this->columns($list->key),
            'default_views' => array_map(static fn ($default): string => $list->systemKey($default), $list->defaults),
        ];
    }

    /**
     * The date values every date field takes (spec D5).
     *
     * @return array<string, mixed>
     */
    public static function dateValues(): array
    {
        return [
            'day' => 'YYYY-MM-DD',
            'month' => '{"month": "YYYY-MM"}',
            'relative_periods' => DateRange::PERIODS,
            'relative_counted' => DateRange::COUNTED,
            'max_days' => DateRange::MAX_DAYS,
            'range' => '{"from": <date>, "to": <date>} with op between',
            'max_conditions' => ViewFilter::MAX_CONDITIONS,
        ];
    }

    /**
     * @return list<array{key: string, label: string, sortable: bool, default: bool, locked: bool}>
     */
    private function columns(string $listKey): array
    {
        $columns = [];
        foreach ($this->catalogs as $catalog) {
            $columns = [...$columns, ...$catalog->columns($listKey)];
        }

        return $columns;
    }

    private function english(string $key): string
    {
        return $this->translator->trans($key, locale: 'en');
    }
}
