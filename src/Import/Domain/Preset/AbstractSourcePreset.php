<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain\Preset;

use App\Import\Domain\HeaderName;

/** Shares the recognition rule between presets; a preset only lists its columns. */
abstract class AbstractSourcePreset implements SourcePreset
{
    private const float RECOGNISED_SHARE = 0.7;

    /**
     * @param list<string> $headers
     */
    public function recognises(array $headers): bool
    {
        $known = array_map(static fn (int|string $header): string => HeaderName::normalise((string) $header), array_keys($this->columns()));
        $headers = array_map(HeaderName::normalise(...), $headers);
        if ([] === $headers) {
            return false;
        }
        $matched = \count(array_filter($headers, static fn (string $header): bool => \in_array($header, $known, true)));

        return $this->isRecognised($matched, \count($headers));
    }

    /**
     * The rule that turns the known headers into a verdict; a preset whose
     * exports carry many unrelated columns overrides it.
     *
     * @param int $known how many of the headers the preset knows
     * @param int $total how many headers the source has
     */
    protected function isRecognised(int $known, int $total): bool
    {
        return $known / $total >= self::RECOGNISED_SHARE;
    }
}
