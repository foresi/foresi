<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain\Preset;

/** Knows the export of one known program: which header means which field of which target. */
interface SourcePreset
{
    public function key(): string;

    /** Translation key of the name shown to the user. */
    public function labelKey(): string;

    /** Key of the import target the preset belongs to. */
    public function targetKey(): string;

    /**
     * @return array<array-key, string> normalised header (see HeaderName) => field key
     */
    public function columns(): array;

    /**
     * What the source values of a field mean.
     *
     * @return array<string, array<string, string>> field key => normalised source value => target value
     */
    public function values(): array;

    /**
     * True when at least 70 % of the headers are known to the preset.
     *
     * @param list<string> $headers
     */
    public function recognises(array $headers): bool;
}
