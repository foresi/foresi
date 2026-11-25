<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Contract;

use Foresi\Shared\Contract\Refused;

/**
 * Records an activity for another module, under the rules, audit and events of the activities'
 * own screens and API. It is for the owners and members of the organisation, as they are: Inbox
 * calls it inside a request of the owner of the item. Nobody signed in, or a guest, is refused.
 */
interface ActivityRecorder
{
    /**
     * Creates the activity as done at the given time, owned by the given person.
     *
     * @return string the id of the new activity
     *
     * @throws Refused type_unknown when no active type has the key; link_kind_unknown and
     *                 link_target_unknown for a link; owner_unknown; forbidden
     */
    public function record(RecordedActivity $activity): string;

    /**
     * Adds one record to the links of an activity and keeps the others. Linking a record twice
     * changes nothing.
     *
     * @throws Refused link_kind_unknown (no module offers the kind), link_target_unknown (the
     *                 record is not one the person may link to), not_found (no activity the person
     *                 sees), trashed, forbidden
     */
    public function link(string $activityId, string $kind, string $id): void;
}
