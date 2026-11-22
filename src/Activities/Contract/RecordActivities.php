<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Contract;

/**
 * The timeline of one record, as a page fragment that another module shows
 * inside its own screen, such as a tab of a contact. Link to it with the route
 * ROUTE and the parameters "kind" and "id", and "return" for the address of
 * the screen it is shown on, where its dialogs go back to. The fragment lists
 * the planned activities first and the done ones below, and offers "Log
 * activity" and "Plan activity" with the record linked.
 */
final class RecordActivities
{
    public const string ROUTE = 'activities_for_record';

    /** The record kind of an activity, for links from other modules. */
    public const string KIND = 'activity';
}
