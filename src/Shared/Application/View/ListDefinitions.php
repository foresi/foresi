<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Application\Field\VisibleFields;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every list the installed modules declare, by key. A list holds the custom fields the
 * current actor sees, so they are kept by the actor's audience (VisibleFields).
 */
final class ListDefinitions
{
    /** @var array<string, array<string, ListDefinition>> by audience, then by key */
    private array $lists = [];

    /**
     * @param iterable<ListDefinitionSource> $sources
     */
    public function __construct(
        #[AutowireIterator(ListDefinitionSource::TAG)]
        private readonly iterable $sources,
        private readonly ?VisibleFields $visible = null,
    ) {
    }

    /**
     * @return array<string, ListDefinition>
     */
    public function all(): array
    {
        $audience = $this->visible?->audience() ?? '';
        if (!isset($this->lists[$audience])) {
            $lists = [];
            foreach ($this->sources as $source) {
                foreach ($source->lists() as $list) {
                    if (isset($lists[$list->key])) {
                        throw new \LogicException(\sprintf('Two modules declare the list "%s".', $list->key));
                    }
                    $lists[$list->key] = $list;
                }
            }
            $this->lists[$audience] = $lists;
        }

        return $this->lists[$audience];
    }

    public function get(string $key): ListDefinition
    {
        return $this->all()[$key] ?? throw new \InvalidArgumentException(\sprintf('No module declares the list "%s".', $key));
    }
}
