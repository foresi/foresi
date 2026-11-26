<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Contract;

/**
 * What another module asks of the records activities link to, before it links one (Inbox links
 * an e-mail to a record): which kinds the installed modules offer, whether the person may link a
 * record, how a record shows, and who sees an activity through its records. Every answer is for
 * the person signed in, as Activities' own screens judge it, but isLinked(), which asks no one.
 */
interface LinkableRecords
{
    /**
     * @return list<string> the kinds some ActivityTarget offers, sorted, such as ["contact", "deal"]
     */
    public function kinds(): array;

    /** Whether the person may link an activity to the record; false for a kind no module offers. */
    public function canLink(string $kind, string $id): bool;

    /** The record's name and screen, or null when it is gone, not the person's to see, or of no offered kind. */
    public function describe(string $kind, string $id): ?LinkedRecord;

    /**
     * Whether the person sees the activity: through one of its records, or as its owner when they
     * see none of them. False for an unknown activity and one in the trash.
     */
    public function seesActivity(string $activityId): bool;

    /** Whether the activity exists, in the trash too, and is linked to at least one record. */
    public function isLinked(string $activityId): bool;
}
