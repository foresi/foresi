<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Contract\Field;

use Foresi\Shared\Contract\Field\RecordExtension;

/**
 * An entity of another module that adds custom fields to a meeting (ADR 0045):
 * its id is the id of the meeting, and each of its other properties has
 * Foresi\Shared\Contract\Field\CustomField. Changes of it show in the history of
 * the meeting.
 */
interface MeetingExtension extends RecordExtension
{
    /** The record kind of its fields, as Foresi\Shared\Contract\Field\Fields names it. */
    public const string RECORD = 'meeting';

    /**
     * The id of the meeting the fields belong to.
     */
    public function meetingId(): string;
}
