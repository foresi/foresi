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
 */
final readonly class ListDefinition
{
    public const string ALL = 'all';

    /** @var array<string, Field> by key */
    public array $fields;
    /** @var list<DefaultView> */
    public array $defaults;

    /**
     * @param list<Field>       $fields
     * @param list<DefaultView> $defaults
     */
    public function __construct(public string $key, array $fields, array $defaults)
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('The list key "%s" is "<module>.<list>".', $key));
        }
        $this->fields = array_combine(array_map(static fn (Field $field): string => $field->key, $fields), $fields);
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
