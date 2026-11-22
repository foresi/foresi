<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Api;

/**
 * The names of the REST reads of Activities.
 */
final class ActivitiesRoutes
{
    public const string ACTIVITIES = 'activities_api_activities';
    public const string PLANNED = 'activities_api_planned';
    public const string ACTIVITY = 'activities_api_activity';
    public const string TYPES = 'activities_api_types';
}
