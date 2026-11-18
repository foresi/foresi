<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The filter row of a list (Calm UI spec, filters option A; Polaris IndexFilters,
 * Stripe filter chips, level.io's filter chips and filters panel):
 *
 * - a search field that applies on Enter;
 * - at most two pinned chips: an empty one is quiet with a dashed edge and
 *   "+ Field", a set one shows "Field: value" on the green tint with a ✕ that
 *   clears it; a filter set from the sheet shows as a set chip too;
 * - "More filters" opens the other filters in the 400 px side sheet;
 * - "Clear all" only while something is set.
 *
 * A chip is a menu of links, each one the list with that value, so choosing
 * applies it at once and there is no Filter button. The sheet sends its fields
 * with one Apply (NN/g batch filtering). On a phone the row is the search and
 * one "Filters (n)" button that opens the same sheet over the whole screen,
 * the set filters as chips under it. Without JavaScript a <noscript> form
 * holds every filter with its button. A list that is empty while nothing
 * narrows it shows no bar.
 *
 * @phpstan-type Option array{label: string, href: string, checked: bool}
 * @phpstan-type Group array{label: ?string, options: list<Option>}
 * @phpstan-type FilterView array{name: string, id: string, label: string, value: string, type: string, options: array<int|string, string|array<int|string, string>>, groups: list<Group>, pinned: bool, set: bool, text: string, clearHref: string}
 */
#[AsTwigComponent]
final class FilterBar
{
    public const int MAX_PINNED = 2;

    /** The id the bar's parts are named from. */
    public string $id = '';
    /** Where the list is: the form's action and the base of every link. */
    public string $action = '';
    /** @var array<string, scalar|null> what the list keeps while it is filtered (the tab, the sort): empty values are left out */
    public array $keep = [];
    /** @var array<string, mixed>|null name, value, label, optional placeholder and maxlength */
    public ?array $search = null;
    /**
     * @var list<array<string, mixed>> each with name, label, options (as Ui:Select takes them), value,
     *                                 optional default (the value that sets nothing, '' when not given),
     *                                 pinned (a chip in the row; at most two), type ("select", or "date" for a
     *                                 day picked in the sheet, never pinned) and text (the value as a set chip
     *                                 says it, for a date)
     */
    public array $filters = [];
    /**
     * @var list<array{label: string, href: string, removeLabel: string}> the conditions of the list's working filter, each a chip with its ✕
     */
    public array $conditions = [];

    /**
     * The filter builder of the sheet (spec D11): the fields one can add a row for, the rows
     * of the conditions set, the conditions it sends back as they are (JSON) and the rules
     * a date end is picked from; null for a list without one.
     *
     * @var array{fields: list<array<string, mixed>>, rows: list<array<string, mixed>>, kept: list<string>, rules: array<string, string>}|null
     */
    public ?array $builder = null;

    /** The list shows nothing: without a filter set there is no bar. */
    public bool $empty = false;

    /** @var list<FilterView> */
    private array $views = [];

    /** @var array<string, string> the value of each filter that is set, by name */
    private array $setValues = [];

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->id) {
            throw new \InvalidArgumentException('A filter bar needs an id.');
        }
        if ('' === $this->action) {
            throw new \InvalidArgumentException('A filter bar needs the address of its list.');
        }
        if (null !== $this->search && (!\is_string($this->search['name'] ?? null) || !\is_string($this->search['label'] ?? null))) {
            throw new \InvalidArgumentException('The search of a filter bar needs a name and a label.');
        }
        $pinned = 0;
        foreach ($this->filters as $filter) {
            $type = $filter['type'] ?? 'select';
            if (!\in_array($type, ['select', 'date'], true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown filter type "%s", expected select or date.', \is_string($type) ? $type : get_debug_type($type)));
            }
            if (!\is_string($filter['name'] ?? null) || !\is_string($filter['label'] ?? null) || ('select' === $type && !\is_array($filter['options'] ?? null))) {
                throw new \InvalidArgumentException('A filter needs a name, a label and, as a select, its options.');
            }
            if ('date' === $type && true === ($filter['pinned'] ?? false)) {
                throw new \InvalidArgumentException('A date filter is picked in the sheet; it cannot be pinned.');
            }
            $pinned += true === ($filter['pinned'] ?? false) ? 1 : 0;
        }
        if ($pinned > self::MAX_PINNED) {
            throw new \InvalidArgumentException(\sprintf('A list pins at most %d filters, %d given.', self::MAX_PINNED, $pinned));
        }
        foreach ($this->filters as $filter) {
            $value = $this->text($filter['value'] ?? '');
            if ($value !== $this->text($filter['default'] ?? '')) {
                $this->setValues[$this->text($filter['name'])] = $value;
            }
        }
        $this->views = array_map($this->view(...), $this->filters);
    }

    /**
     * @return list<FilterView>
     */
    public function views(): array
    {
        return $this->views;
    }

    /**
     * @return array<int, FilterView>
     */
    public function pinned(): array
    {
        return array_filter($this->views, static fn (array $view): bool => $view['pinned']);
    }

    /**
     * The filters of the sheet only.
     *
     * @return array<int, FilterView>
     */
    public function rest(): array
    {
        return array_filter($this->views, static fn (array $view): bool => !$view['pinned']);
    }

    /**
     * @return array<int, FilterView>
     */
    public function set(): array
    {
        return array_filter($this->views, static fn (array $view): bool => $view['set']);
    }

    public function searchValue(): string
    {
        return $this->text($this->search['value'] ?? '');
    }

    /**
     * Whether anything narrows the list: a filter or the search.
     */
    public function narrowed(): bool
    {
        return [] !== $this->setValues || [] !== $this->conditions || '' !== $this->searchValue();
    }

    /**
     * How many filters are set, conditions of the builder among them, for "Filters (n)".
     */
    public function setCount(): int
    {
        return \count($this->set()) + \count($this->conditions);
    }

    /**
     * The fields of the builder by key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function builderFields(): array
    {
        $fields = [];
        foreach ($this->builder['fields'] ?? [] as $field) {
            $fields[$this->text($field['key'] ?? '')] = $field;
        }

        return $fields;
    }

    public function shown(): bool
    {
        return !$this->empty || $this->narrowed();
    }

    /**
     * The list with nothing narrowing it, what it keeps still kept.
     */
    public function clearHref(): string
    {
        return $this->href($this->kept());
    }

    /**
     * @return array<string, string> the hidden values a form of the bar sends besides its own fields
     */
    public function hidden(bool $withSearch, bool $withFilters): array
    {
        $values = $this->kept();
        if ($withSearch && '' !== $this->searchValue()) {
            $values[$this->text($this->search['name'] ?? '')] = $this->searchValue();
        }

        return $withFilters ? $values + $this->setValues : $values;
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return FilterView
     */
    private function view(array $filter): array
    {
        $name = $this->text($filter['name']);
        $value = $this->text($filter['value'] ?? '');
        $default = $this->text($filter['default'] ?? '');
        /** @var array<int|string, string|array<int|string, string>> $options */
        $options = $filter['options'] ?? [];
        $groups = [];
        $text = '';
        $loose = [];
        foreach ($options as $key => $label) {
            if (\is_array($label)) {
                $groups[] = ['label' => (string) $key, 'options' => $this->options($name, $default, $value, $label, $text)];
            } else {
                $loose[$key] = $label;
            }
        }
        if ([] !== $loose) {
            array_unshift($groups, ['label' => null, 'options' => $this->options($name, $default, $value, $loose, $text)]);
        }

        return [
            'name' => $name,
            'id' => $this->id.'-'.preg_replace('/[^a-z0-9]+/i', '-', $name),
            'label' => $this->text($filter['label']),
            'value' => $value,
            'options' => $options,
            'groups' => $groups,
            'pinned' => true === ($filter['pinned'] ?? false),
            'set' => $value !== $default,
            'text' => \is_string($filter['text'] ?? null) ? $filter['text'] : ('' === $text ? $value : $text),
            'type' => $this->text($filter['type'] ?? 'select'),
            'clearHref' => $this->with($name, null),
        ];
    }

    /**
     * @param array<int|string, string> $options
     *
     * @return list<Option>
     */
    private function options(string $name, string $default, string $value, array $options, string &$text): array
    {
        $views = [];
        foreach ($options as $key => $label) {
            $key = (string) $key;
            if ($key === $value) {
                $text = $label;
            }
            $views[] = ['label' => $label, 'href' => $this->with($name, $key === $default ? null : $key), 'checked' => $key === $value];
        }

        return $views;
    }

    /**
     * The list as it is now, with one filter set to a value or cleared, from its first page.
     */
    private function with(string $name, ?string $value): string
    {
        $query = $this->hidden(true, true);
        unset($query[$name]);
        if (null !== $value) {
            $query[$name] = $value;
        }

        return $this->href($query);
    }

    /**
     * @return array<string, string>
     */
    private function kept(): array
    {
        $kept = [];
        foreach ($this->keep as $name => $value) {
            $text = true === $value ? '1' : $this->text($value);
            if ('' !== $text) {
                $kept[$name] = $text;
            }
        }

        return $kept;
    }

    /**
     * @param array<string, string> $query
     */
    private function href(array $query): string
    {
        return [] === $query ? $this->action : $this->action.(str_contains($this->action, '?') ? '&' : '?').http_build_query($query);
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
