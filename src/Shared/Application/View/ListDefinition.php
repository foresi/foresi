<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * What a module declares of one of its lists (spec D3): the key the list's views are
 * stored under, such as "invoicing.invoices", the fields it can be filtered by and
 * the views it ships. Shared code checks and resolves the conditions; the module's
 * query adapter turns them into its SQL, as only it knows a derived field.
 *
 * The first default view is the list's All: it holds no condition and stays first.
 *
 * The pinned fields are the quick filters a view shows as chips under its tabs until someone
 * picks others in its Display sheet (Shopify Polaris IndexFilters pins filters to the bar,
 * Linear and Notion keep them with the view): the view keeps its own as display.pinned, and
 * a view that keeps none, as each shipped view, shows these.
 */
final readonly class ListDefinition
{
    public const string ALL = 'all';
    /** At most this many quick filters stand under the tabs. */
    public const int MAX_PINNED = 4;

    /** The operators of a single enum and what they read as on a set. */
    private const array SET_OPERATORS = ['is' => 'has_any_of', 'is_any_of' => 'has_any_of', 'is_not' => 'has_none_of', 'is_none_of' => 'has_none_of'];

    /** @var array<string, Field> by key */
    public array $fields;
    /** @var array<string, string> the key of each field by its key and its aliases */
    private array $keys;
    /** @var list<DefaultView> */
    public array $defaults;
    /** @var list<string> the keys of the fields pinned by default, in their order */
    public array $pinned;

    /**
     * @param list<Field>       $fields
     * @param list<DefaultView> $defaults
     * @param list<string>      $pinned   the fields pinned as quick filters by default, by key or alias
     */
    public function __construct(public string $key, array $fields, array $defaults, array $pinned = [])
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('The list key "%s" is "<module>.<list>".', $key));
        }
        $this->fields = array_combine(array_map(static fn (Field $field): string => $field->key, $fields), $fields);
        if (\count($this->fields) !== \count($fields)) {
            throw new \InvalidArgumentException(\sprintf('The list "%s" has two fields of one key.', $key));
        }
        $keys = array_combine(array_keys($this->fields), array_keys($this->fields));
        foreach ($this->fields as $field) {
            foreach ($field->aliases as $alias) {
                if (isset($keys[$alias])) {
                    throw new \InvalidArgumentException(\sprintf('The list "%s" names two fields "%s".', $key, $alias));
                }
                $keys[$alias] = $field->key;
            }
        }
        $this->keys = $keys;
        if ([] === $defaults || self::ALL !== $defaults[0]->slug || [] !== $defaults[0]->filter->conditions) {
            throw new \InvalidArgumentException(\sprintf('The list "%s" starts with its All view, which holds no condition.', $key));
        }
        $slugs = array_map(static fn (DefaultView $view): string => $view->slug, $defaults);
        if (\count($slugs) !== \count(array_unique($slugs))) {
            throw new \InvalidArgumentException(\sprintf('The default views of "%s" have one slug each.', $key));
        }
        foreach ($defaults as $view) {
            $view->filter->check($this);
        }
        $this->defaults = $defaults;
        if (\count($pinned) > self::MAX_PINNED) {
            throw new \InvalidArgumentException(\sprintf('The list "%s" pins at most %d quick filters.', $key, self::MAX_PINNED));
        }
        $this->pinned = array_values(array_unique(array_map(function (string $name) use ($key): string {
            $field = $this->field($name);
            if (!$field instanceof Field) {
                throw new \InvalidArgumentException(\sprintf('The list "%s" pins "%s", which is none of its fields.', $key, $name));
            }

            return $field->key;
        }, $pinned)));
    }

    /**
     * The quick filters a stored list of names pins: the fields it names that the list still has,
     * under their keys, once each and at most MAX_PINNED; the list's own when it names none.
     *
     * @param mixed $names a display's pinned as stored, null for none stored
     *
     * @return list<string>
     */
    public function pinnedOf(mixed $names): array
    {
        if (!\is_array($names)) {
            return $this->pinned;
        }
        $keys = [];
        foreach ($names as $name) {
            $field = \is_string($name) ? $this->field($name) : null;
            if ($field instanceof Field && !\in_array($field->key, $keys, true)) {
                $keys[] = $field->key;
            }
        }

        return \array_slice($keys, 0, self::MAX_PINNED);
    }

    /**
     * The field a condition names, by its key or by an alias.
     */
    public function field(string $name): ?Field
    {
        $key = $this->keys[$name] ?? null;

        return null === $key ? null : $this->fields[$key];
    }

    /**
     * A condition as the list reads it: under its field's key, and on a field that holds a set,
     * with the operators of the single enum it was before read as their set counterparts, so a
     * view kept as "role is customer" is "roles has any of customer" (spec D4).
     */
    public function canonical(Condition $condition): Condition
    {
        $field = $this->field($condition->field);
        if (!$field instanceof Field) {
            return $condition;
        }
        $op = $condition->op;
        $value = $condition->value;
        if (FieldType::MultiEnum === $field->type && isset(self::SET_OPERATORS[$op])) {
            $op = self::SET_OPERATORS[$op];
            $value = \is_array($value) ? $value : [$value];
        }

        return $field->key === $condition->field && $op === $condition->op ? $condition : new Condition($field->key, $op, $value);
    }

    public function systemKey(DefaultView $view): string
    {
        return $this->key.'.'.$view->slug;
    }

    public function default(string $slug): ?DefaultView
    {
        foreach ($this->defaults as $view) {
            if ($view->slug === $slug) {
                return $view;
            }
        }

        return null;
    }
}
