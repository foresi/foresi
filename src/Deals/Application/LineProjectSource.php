<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port: the projects an hourly line links to, answered by Sales' integration
 * with Work (Integration/Work) when Work is installed. Without it a line links
 * to no project. The time logged on them is LineProjectTime's.
 */
#[AutoconfigureTag(self::TAG)]
interface LineProjectSource
{
    public const string TAG = 'foresi.deals.line_project_source';

    /** A project the person signed in sees, archived ones too; null for none. */
    public function find(string $projectId): ?LineProject;

    /**
     * @return list<LineProject> the active projects the person sees of the client and of no client, by name
     */
    public function ofClient(string $clientId): array;
}
