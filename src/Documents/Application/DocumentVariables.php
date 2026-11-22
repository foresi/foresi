<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Contract\DocumentVariableSource;
use Foresi\Documents\Contract\RecordLink;
use Foresi\Documents\Contract\VariableDefinition;

/**
 * The variables templates can use, gathered from the modules that offer them.
 */
final readonly class DocumentVariables
{
    /**
     * @param iterable<DocumentVariableSource> $sources
     */
    public function __construct(private iterable $sources)
    {
    }

    /**
     * @return array<string, list<VariableDefinition>> by prefix, in prefix order
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->sources as $source) {
            $definitions[$source->prefix()] = $source->definitions();
        }
        ksort($definitions);

        return $definitions;
    }

    /**
     * The values for a document with these links, by prefix. A source that does
     * not apply to the links gives none, so its variables are missing.
     *
     * @param list<RecordLink> $links
     *
     * @return array<string, array<string, mixed>>
     */
    public function values(array $links): array
    {
        $values = [];
        foreach ($this->sources as $source) {
            $own = $source->values($links);
            if (null !== $own) {
                $values[$source->prefix()] = $own;
            }
        }
        ksort($values);

        return $values;
    }

    /**
     * The examples of every variable, by prefix, for a preview.
     *
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        $examples = [];
        foreach ($this->definitions() as $prefix => $definitions) {
            foreach ($definitions as $definition) {
                $examples[$prefix][$definition->name] = $definition->example;
            }
        }

        return $examples;
    }
}
