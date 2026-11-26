<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Contract;

/**
 * Asks which records an activity is linked to, for another module that must know an activity
 * belongs to its record: Work asks whether the activity a budget change names as its source is
 * on the project.
 */
interface ActivityLinks
{
    /**
     * Whether the activity exists, is not in the trash and is linked to the record. It answers
     * for the activities the signed-in person sees, as ActivityRecorder::link does: an activity
     * the person does not see, or one that does not exist, is not linked; so is anyone's activity
     * when nobody is signed in.
     */
    public function isLinked(string $activityId, string $kind, string $id): bool;
}
