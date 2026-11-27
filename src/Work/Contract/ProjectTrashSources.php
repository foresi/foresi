<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port: what other modules keep of a project in its trash, such as the time
 * deleted from it. The trash of a project counts it in the link of its header
 * and lists each source as a section of its own after the tasks; a project
 * without any shows the tasks only, so Work renders the same whether those
 * modules are installed or not. The page is for whom keeps the project's
 * settings.
 */
#[AutoconfigureTag(self::TAG)]
interface ProjectTrashSources
{
    public const string TAG = 'foresi.work.project_trash_source';

    /**
     * How many of its records are in the project's trash.
     */
    public function count(string $projectId): int;

    /**
     * @return ProjectTrashSection|null null when none of its records is in the trash
     */
    public function section(string $projectId): ?ProjectTrashSection;
}
