<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Domain\Preset\SourcePreset;

/** All presets of known sources; modules contribute theirs by implementing SourcePreset. */
final readonly class PresetRegistry
{
    /** @var list<SourcePreset> */
    private array $presets;

    /**
     * @param iterable<SourcePreset> $presets
     */
    public function __construct(iterable $presets)
    {
        $this->presets = array_values([...$presets]);
    }

    /**
     * The first preset of the target that knows these headers.
     *
     * @param list<string> $headers
     */
    public function recognise(string $targetKey, array $headers): ?SourcePreset
    {
        foreach ($this->presets as $preset) {
            if ($preset->targetKey() === $targetKey && $preset->recognises($headers)) {
                return $preset;
            }
        }

        return null;
    }

    public function find(?string $key): ?SourcePreset
    {
        foreach ($this->presets as $preset) {
            if ($preset->key() === $key) {
                return $preset;
            }
        }

        return null;
    }
}
