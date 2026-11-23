<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Contract\DocumentImageSource;
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
     * The values as a template prints them: an image variable becomes its <img>,
     * or nothing when it has no image. The values themselves, kept with a
     * document, hold only the name the source gave the file.
     *
     * @param array<string, array<string, mixed>> $values  by prefix
     * @param bool                                $preview for the browser rather than the PDF: the image inline, and a
     *                                                     placeholder for an image without a file, such as an example
     *
     * @return array<string, array<string, mixed>>
     */
    public function printable(array $values, bool $preview = false): array
    {
        foreach ($this->sources as $source) {
            $prefix = $source->prefix();
            foreach ($source->definitions() as $definition) {
                $value = $values[$prefix][$definition->name] ?? null;
                if (!$definition->image || !\is_string($value)) {
                    continue;
                }
                $file = '' === $value || !$source instanceof DocumentImageSource ? null : $source->imageFile($definition->name, $value);
                $tag = match (true) {
                    null === $file => null,
                    $preview => ImageTag::forPreview($file, $definition->label),
                    default => ImageTag::forPdf($file, $definition->label),
                };
                $values[$prefix][$definition->name] = $tag ?? ($preview && '' !== $value ? ImageTag::placeholder($definition->label) : '');
            }
        }

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
