<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The records of some kinds an activity can be about. The module that owns
 * them implements this in Integration/Activities. An activity is seen by whoever
 * sees one of its records; a record that goes away (archived, trashed, deleted)
 * keeps its links, and canSee() then answers false. An activity whose records
 * are all gone, or that has none, is seen by its owner only.
 */
#[AutoconfigureTag(self::TAG)]
interface ActivityTarget
{
    public const string TAG = 'app.activity_target';

    /**
     * @return list<string> the record kinds this target answers for, such as ["contact"]
     */
    public function kinds(): array;

    /** Whether the record exists and the person may see it, and so its activities. */
    public function canSee(string $kind, string $id): bool;

    /** Whether the person may link a new activity to the record. */
    public function canLink(string $kind, string $id): bool;

    /** The name to show for the record, or null when it is gone. */
    public function label(string $kind, string $id): ?string;

    /** The screen of the record, or null when it is gone. */
    public function url(string $kind, string $id): ?string;
}
