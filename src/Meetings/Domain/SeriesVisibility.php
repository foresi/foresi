<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Domain;

/**
 * Who sees a series and its meetings besides the owners of the firm: everyone in
 * the firm but the guests, or only its members and the attendees of each meeting.
 * A new series is for its members (spec decision M3).
 */
enum SeriesVisibility: string
{
    case Organisation = 'organisation';
    case Members = 'members';
}
