<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every list the installed modules declare, by key.
 */
final class ListDefinitions
{
    /** @var array<string, ListDefinition>|null */
    private ?array $lists = null;

    /**
     * @param iterable<ListDefinitionSource> $sources
     */
    public function __construct(
        #[AutowireIterator(ListDefinitionSource::TAG)]
        private readonly iterable $sources,
    ) {
    }

    /**
     * @return array<string, ListDefinition>
     */
    public function all(): array
    {
        if (null === $this->lists) {
            $this->lists = [];
            foreach ($this->sources as $source) {
                foreach ($source->lists() as $list) {
                    if (isset($this->lists[$list->key])) {
                        throw new \LogicException(\sprintf('Two modules declare the list "%s".', $list->key));
                    }
                    $this->lists[$list->key] = $list;
                }
            }
        }

        return $this->lists;
    }

    public function get(string $key): ListDefinition
    {
        return $this->all()[$key] ?? throw new \InvalidArgumentException(\sprintf('No module declares the list "%s".', $key));
    }
}
